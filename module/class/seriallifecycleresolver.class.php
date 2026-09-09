<?php
/* Copyright (C) 2026 Serial Tracker contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       class/seriallifecycleresolver.class.php
 *  \ingroup    serialtracker
 *  \brief      Resolves the fulfillment journey of order lines and their serials.
 *
 *  Model (a "thing's journey", anchored on the ORDER LINE):
 *
 *    FRONT HALF — per order line, as counts (no per-unit identity yet):
 *        Ordered (N) -> Picking (x) -> Shipped (y)
 *
 *    BACK HALF — per serial, once a lot is attached at shipment:
 *        Manufactured (MO, traced by lot) -> Shipped -> Under Warranty -> Support Ended
 *
 *  The only tie between an order and its physical goods is the serial/lot
 *  attached at shipment, so MO is NOT a forward stage on the order — it is
 *  resolved per-serial (by lot) in the serial detail. Live resolution is not
 *  wired; SERIALTRACKER_SAMPLE = 1 backs every surface with one coherent mock
 *  dataset until the order->shipment->lot linkage is verified (ajax/debug.php).
 */
class SerialLifecycleResolver
{
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_ENDED    = 'ended';

	/** @var DoliDB */
	public $db;

	/** @var bool  True when returned data is mock, not live */
	public $isSample = false;

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Front-half stages (per order line). Order matters.
	 *
	 *  @return array
	 */
	public function orderStages()
	{
		global $langs;
		return array(
			array('key' => 'ORD',  'label' => $langs->trans('SerialtrackerStageOrdered'), 'label_todo' => $langs->trans('SerialtrackerTodoOrdered')),
			array('key' => 'PICK', 'label' => $langs->trans('SerialtrackerStagePicking'), 'label_todo' => $langs->trans('SerialtrackerTodoPicking')),
			array('key' => 'SHIP', 'label' => $langs->trans('SerialtrackerStageShipped'), 'label_todo' => $langs->trans('SerialtrackerTodoShipped')),
		);
	}

	/**
	 *  Back-half stages (per serial).
	 *
	 *  @return array
	 */
	public function serialStages()
	{
		global $langs;
		return array(
			array('key' => 'MFG',  'label' => $langs->trans('SerialtrackerStageManufactured'), 'label_todo' => $langs->trans('SerialtrackerTodoManufactured')),
			array('key' => 'SHIP', 'label' => $langs->trans('SerialtrackerStageShipped'),      'label_todo' => $langs->trans('SerialtrackerTodoShipped')),
			array('key' => 'WARR', 'label' => $langs->trans('SerialtrackerStageWarranty'),     'label_todo' => $langs->trans('SerialtrackerTodoWarranty')),
			array('key' => 'EOL',  'label' => $langs->trans('SerialtrackerStageSupportEnded'), 'label_todo' => $langs->trans('SerialtrackerTodoSupportEnded')),
		);
	}

	// -------------------------------------------------------------------------
	// Public resolve entry points (one per surface)
	// -------------------------------------------------------------------------

	/**
	 *  Order lines for a project, each with its fulfillment journey + serials.
	 *
	 *  @param  int  $projectId
	 *  @return array  List of order lines
	 */
	public function resolveForProject($projectId)
	{
		if ($this->useSample()) {
			return $this->sampleLines();
		}
		return $this->liveLines(array('fk_project' => (int) $projectId));
	}

	/**
	 *  Order lines for a customer (third party), across all projects.
	 *
	 *  @param  int  $socId
	 *  @return array
	 */
	public function resolveForThirdparty($socId)
	{
		if ($this->useSample()) {
			return $this->sampleLines();
		}
		return $this->liveLines(array('fk_soc' => (int) $socId));
	}

	/**
	 *  One serial's full back-half detail (incl. MO/production trace by lot).
	 *
	 *  @param  int  $lotId  product_lot rowid
	 *  @return array|null
	 */
	public function resolveSerial($lotId)
	{
		$lotId = (int) $lotId;
		if ($this->useSample()) {
			foreach ($this->sampleSerials() as $s) {
				if ((int) $s['lot_id'] === $lotId) {
					return $s;
				}
			}
			$all = $this->sampleSerials();
			return !empty($all) ? $all[0] : null;
		}
		return $this->liveSerial($lotId);
	}

	/**
	 *  Compact counts for the project's slim main-card line.
	 *
	 *  @param  int  $projectId
	 *  @return array  ['lines','units','picking','shipped','in_warranty','support_ended']
	 */
	public function summaryForProject($projectId)
	{
		$lines = $this->resolveForProject($projectId);
		$sum = array('lines' => 0, 'units' => 0, 'picking' => 0, 'shipped' => 0, 'in_warranty' => 0, 'support_ended' => 0);
		foreach ($lines as $l) {
			// Collapsed "other parts" summary row carries its own counts.
			if (!empty($l['is_summary'])) {
				$sum['lines']   += (int) $l['lines'];
				$sum['units']   += (int) $l['qty'];
				$sum['shipped'] += (int) $l['shipped'];
				continue;
			}
			$sum['lines']++;
			$sum['units']   += (int) $l['qty'];
			$sum['picking'] += (int) $l['picking'];
			$sum['shipped'] += (int) $l['shipped'];
			foreach ((isset($l['serials']) ? $l['serials'] : array()) as $srl) {
				if ($srl['stage'] === 'WARR') {
					$sum['in_warranty']++;
				} elseif ($srl['stage'] === 'EOL') {
					$sum['support_ended']++;
				}
			}
		}
		return $sum;
	}

	// -------------------------------------------------------------------------
	// Live resolution
	//
	// FRONT HALF (order line -> picking -> shipped, serial identity) resolves from
	// stable native tables: commande/commandedet, expedition(det|det_batch),
	// product_lot, product.tobatch. BACK HALF (warranty / support-ended) is routed
	// through SerialWarrantyAdapter, which is deliberately isolated because that
	// module is being overhauled — this resolver never touches those tables.
	// -------------------------------------------------------------------------

	/**
	 *  Order lines (serial-tracked as full journeys + one collapsed parts summary).
	 *
	 *  @param  array  $filter  fk_project | fk_soc
	 *  @return array
	 */
	private function liveLines($filter)
	{
		if (!empty($filter['fk_project'])) {
			$where = "c.fk_projet = ".((int) $filter['fk_project']);
		} elseif (!empty($filter['fk_soc'])) {
			$where = "c.fk_soc = ".((int) $filter['fk_soc']);
		} else {
			return array();
		}

		// 1. Order lines + serialized flag (product.tobatch > 0).
		$sql = "SELECT cd.rowid as line_id, cd.fk_product, cd.qty,"
			." c.ref as order_ref, c.date_commande,"
			." p.ref as product_ref, p.label as product_label, COALESCE(p.tobatch, 0) as tobatch"
			." FROM ".MAIN_DB_PREFIX."commandedet cd"
			." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
			." LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product"
			." WHERE ".$where." AND cd.fk_product > 0"
			." AND c.entity IN (".getEntity('commande').")"
			." ORDER BY c.rowid DESC, cd.rowid";
		$res = $this->db->query($sql);
		if (!$res) {
			return array();
		}
		$lines = array();
		$ids = array();
		while ($o = $this->db->fetch_object($res)) {
			$id = (int) $o->line_id;
			$lines[$id] = array(
				'line_id'      => $id,
				'product'      => ($o->product_label != '' ? $o->product_label : $o->product_ref),
				'order_ref'    => $o->order_ref,
				'order_date'   => $o->date_commande ? $this->db->jdate($o->date_commande) : 0,
				'qty'          => (int) $o->qty,
				'serialized'   => ((int) $o->tobatch > 0),
				'fk_product'   => (int) $o->fk_product,
				'picking'      => 0,
				'shipped'      => 0,
				'shipped_date' => 0,
				'serials'      => array(),
			);
			$ids[] = $id;
		}
		if (empty($ids)) {
			return array();
		}
		$inIds = implode(',', $ids);

		// 2. Picking / shipped counts + latest shipped date per line.
		$sql2 = "SELECT ed.fk_elementdet as line_id,"
			." SUM(CASE WHEN e.fk_statut >= 1 THEN ed.qty ELSE 0 END) as shipped,"
			." SUM(CASE WHEN e.fk_statut = 0 THEN ed.qty ELSE 0 END) as picking,"
			." MAX(CASE WHEN e.fk_statut >= 1 THEN e.date_expedition ELSE NULL END) as shipped_date"
			." FROM ".MAIN_DB_PREFIX."expeditiondet ed"
			." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition"
			." WHERE ed.element_type = 'commande' AND ed.fk_elementdet IN (".$inIds.")"
			." AND e.entity IN (".getEntity('expedition').")"
			." GROUP BY ed.fk_elementdet";
		$res2 = $this->db->query($sql2);
		if ($res2) {
			while ($o = $this->db->fetch_object($res2)) {
				$id = (int) $o->line_id;
				if (isset($lines[$id])) {
					$lines[$id]['shipped']      = (int) $o->shipped;
					$lines[$id]['picking']      = (int) $o->picking;
					$lines[$id]['shipped_date'] = $o->shipped_date ? $this->db->jdate($o->shipped_date) : 0;
				}
			}
		}

		// 3. Serials attached at shipment, for serial-tracked lines only.
		$serialIds = array();
		foreach ($lines as $id => $l) {
			if ($l['serialized']) {
				$serialIds[] = $id;
			}
		}
		if (!empty($serialIds)) {
			$sql3 = "SELECT ed.fk_elementdet as line_id, eb.batch, ed.fk_product, pl.rowid as lot_id"
				." FROM ".MAIN_DB_PREFIX."expeditiondet ed"
				." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition AND e.fk_statut >= 1"
				." INNER JOIN ".MAIN_DB_PREFIX."expeditiondet_batch eb ON eb.fk_expeditiondet = ed.rowid"
				." LEFT JOIN ".MAIN_DB_PREFIX."product_lot pl ON pl.batch = eb.batch AND pl.fk_product = ed.fk_product"
				." WHERE ed.element_type = 'commande' AND ed.fk_elementdet IN (".implode(',', $serialIds).")"
				." AND e.entity IN (".getEntity('expedition').")"
				." ORDER BY eb.batch";
			$res3 = $this->db->query($sql3);
			if ($res3) {
				while ($o = $this->db->fetch_object($res3)) {
					$id = (int) $o->line_id;
					if (isset($lines[$id])) {
						$lines[$id]['serials'][] = array(
							'lot_id' => (int) $o->lot_id,
							'serial' => $o->batch,
							'stage'  => 'SHIP', // back-half stage is deferred to the warranty adapter
						);
					}
				}
			}
		}

		// 4. Build rows: serial-tracked lines as journeys, the rest collapsed.
		$out = array();
		$otherLines = 0;
		$otherQty = 0;
		$otherShipped = 0;
		foreach ($lines as $l) {
			if ($l['serialized']) {
				$out[] = array(
					'line_id'   => $l['line_id'],
					'product'   => $l['product'],
					'order_ref' => $l['order_ref'],
					'qty'       => $l['qty'],
					'picking'   => $l['picking'],
					'shipped'   => $l['shipped'],
					'steps'     => $this->lineSteps($l['qty'], $l['picking'], $l['shipped'], $l['order_ref'], $l['order_date'], '', $l['shipped_date']),
					'serials'   => $l['serials'],
				);
			} else {
				$otherLines++;
				$otherQty     += $l['qty'];
				$otherShipped += $l['shipped'];
			}
		}
		if ($otherLines > 0) {
			$out[] = array('is_summary' => true, 'lines' => $otherLines, 'qty' => $otherQty, 'shipped' => $otherShipped);
		}
		return $out;
	}

	/**
	 *  One serial's back-half detail, resolved by lot. Warranty/support come from
	 *  the isolated adapter (currently deferred while that module is overhauled).
	 *
	 *  @param  int  $lotId  product_lot rowid
	 *  @return array|null
	 */
	private function liveSerial($lotId)
	{
		global $langs;
		$lotId = (int) $lotId;

		$sql = "SELECT pl.rowid, pl.batch, pl.fk_product, pl.manufacturing_date, pl.eol_date,"
			." p.ref as product_ref, p.label as product_label"
			." FROM ".MAIN_DB_PREFIX."product_lot pl"
			." LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product"
			." WHERE pl.rowid = ".$lotId;
		$res = $this->db->query($sql);
		if (!$res || !($lot = $this->db->fetch_object($res))) {
			return null;
		}
		$batch = $lot->batch;

		// Shipment for this serial (most recent).
		$ship = null;
		$sqlS = "SELECT e.ref as ship_ref, e.date_expedition, e.fk_projet, e.fk_soc, ed.fk_elementdet as line_id"
			." FROM ".MAIN_DB_PREFIX."expeditiondet_batch eb"
			." INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.rowid = eb.fk_expeditiondet"
			." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition"
			." WHERE eb.batch = '".$this->db->escape($batch)."' AND ed.fk_product = ".((int) $lot->fk_product)
			." ORDER BY e.date_expedition DESC";
		$resS = $this->db->query($sqlS);
		if ($resS) {
			$ship = $this->db->fetch_object($resS);
		}

		$ss = $this->serialStages(); // MFG, SHIP, WARR, EOL

		// MFG: the lot exists, so it was made; date if we have it.
		$steps = array();
		$steps[] = $this->step($ss[0], self::STATE_COMPLETE, '',
			($lot->manufacturing_date ? $this->db->jdate($lot->manufacturing_date) : 0),
			$langs->trans('SerialtrackerStatusProduced'));

		// SHIP
		if ($ship && !empty($ship->ship_ref)) {
			$steps[] = $this->step($ss[1], self::STATE_COMPLETE, $ship->ship_ref,
				($ship->date_expedition ? $this->db->jdate($ship->date_expedition) : 0),
				$langs->trans('SerialtrackerStatusShipped'));
		} else {
			$steps[] = $this->step($ss[1], self::STATE_PENDING, '', 0, '');
		}

		// WARR / EOL via the isolated adapter (deferred -> pending).
		dol_include_once('/serialtracker/class/serialwarrantyadapter.class.php');
		$w = class_exists('SerialWarrantyAdapter')
			? SerialWarrantyAdapter::forSerial($this->db, $batch, (int) $lot->fk_product)
			: null;
		if ($w === null) {
			$steps[] = $this->step($ss[2], self::STATE_PENDING, '', 0, $langs->trans('SerialtrackerWarrantyPending'));
			$steps[] = $this->step($ss[3], self::STATE_PENDING, '', 0, '');
		} else {
			$warrState = !empty($w['active']) ? self::STATE_CURRENT : self::STATE_COMPLETE;
			$steps[] = $this->step($ss[2], $warrState, (!empty($w['expiry_str']) ? $w['expiry_str'] : ''),
				(!empty($w['start']) ? (int) $w['start'] : 0), (isset($w['status_label']) ? $w['status_label'] : ''));
			$eolState = !empty($w['ended']) ? self::STATE_ENDED : self::STATE_PENDING;
			$steps[] = $this->step($ss[3], $eolState, '', (!empty($w['ended_ts']) ? (int) $w['ended_ts'] : 0), '');
		}

		// Context.
		$orderRef = '';
		if ($ship && !empty($ship->line_id)) {
			$rc = $this->db->query("SELECT c.ref FROM ".MAIN_DB_PREFIX."commandedet cd"
				." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
				." WHERE cd.rowid = ".((int) $ship->line_id));
			if ($rc && ($rco = $this->db->fetch_object($rc))) {
				$orderRef = $rco->ref;
			}
		}
		$projectRef = '';
		if ($ship && !empty($ship->fk_projet)) {
			$rp = $this->db->query("SELECT ref FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".((int) $ship->fk_projet));
			if ($rp && ($rpo = $this->db->fetch_object($rp))) {
				$projectRef = $rpo->ref;
			}
		}
		$thirdparty = '';
		if ($ship && !empty($ship->fk_soc)) {
			$rt = $this->db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $ship->fk_soc));
			if ($rt && ($rto = $this->db->fetch_object($rt))) {
				$thirdparty = $rto->nom;
			}
		}

		return array(
			'lot_id'     => (int) $lot->rowid,
			'serial'     => $batch,
			'product'    => ($lot->product_label != '' ? $lot->product_label : $lot->product_ref),
			'thirdparty' => $thirdparty,
			'project'    => $projectRef,
			'order_ref'  => $orderRef,
			'steps'      => $steps,
		);
	}

	// -------------------------------------------------------------------------
	// Sample data (one coherent dataset behind every surface)
	// -------------------------------------------------------------------------

	/**
	 *  @return bool
	 */
	private function useSample()
	{
		$s = (getDolGlobalString('SERIALTRACKER_SAMPLE', '1') === '1');
		$this->isSample = $s;
		return $s;
	}

	/**
	 *  Build a step with display metadata (subtitle ref, date, status label).
	 *
	 *  @param  array   $base
	 *  @param  string  $state
	 *  @param  string  $ref
	 *  @param  string  $dateStr
	 *  @param  string  $statusLabel
	 *  @return array
	 */
	private function step($base, $state, $ref = '', $date = '', $statusLabel = '')
	{
		// $date may be a 'YYYY-MM-DD' string (sample) or a unix timestamp (live).
		if (is_string($date)) {
			$date = ($date !== '') ? dol_stringtotime($date, 1) : 0;
		}
		$base['state']        = $state;
		$base['ref']          = $ref;
		$base['date']         = $date ? (int) $date : 0;
		$base['status_label'] = $statusLabel;
		return $base;
	}

	/**
	 *  Front-half journey steps for a line, given ordered/picking/shipped counts.
	 *
	 *  @param  int     $qty
	 *  @param  int     $picking
	 *  @param  int     $shipped
	 *  @param  string  $orderRef
	 *  @param  string  $orderDate
	 *  @param  string  $shipRef
	 *  @param  string  $shipDate
	 *  @return array
	 */
	private function lineSteps($qty, $picking, $shipped, $orderRef, $orderDate, $shipRef, $shipDate)
	{
		global $langs;
		$os = $this->orderStages();

		// Ordered is always complete once the line exists.
		$ordered = $this->step($os[0], self::STATE_COMPLETE, $orderRef, $orderDate,
			$langs->trans('SerialtrackerCountOfShort', $qty));

		// Picking: current while units are being assigned but not all shipped.
		$pickState = ($shipped >= $qty) ? self::STATE_COMPLETE
			: (($picking > 0 || $shipped > 0) ? self::STATE_CURRENT : self::STATE_PENDING);
		$picking = $this->step($os[1], $pickState,
			($picking > 0 ? $langs->trans('SerialtrackerCountOf', $picking, $qty) : ''),
			'', '');

		// Shipped: complete when all shipped, current when some shipped.
		$shipState = ($shipped >= $qty) ? self::STATE_COMPLETE
			: ($shipped > 0 ? self::STATE_CURRENT : self::STATE_PENDING);
		$shippedStep = $this->step($os[2], $shipState,
			$langs->trans('SerialtrackerCountOf', $shipped, $qty),
			($shipped > 0 ? $shipDate : ''),
			($shipRef !== '' ? $shipRef : ''));

		return array($ordered, $picking, $shippedStep);
	}

	/**
	 *  Sample order lines (front half + serial chips).
	 *
	 *  @return array
	 */
	private function sampleLines()
	{
		$this->isSample = true;

		// Map serials to each line via the shared serial dataset.
		$byLine = array();
		foreach ($this->sampleSerials() as $s) {
			$byLine[$s['line_id']][] = array(
				'lot_id' => $s['lot_id'],
				'serial' => $s['serial'],
				'stage'  => $this->currentSerialStageKey($s['steps']),
			);
		}

		return array(
			array(
				'line_id'   => 5001,
				'product'   => 'Stage 2 Compression System',
				'order_ref' => 'CO-2405-0031',
				'qty'       => 3,
				'picking'   => 1,
				'shipped'   => 2,
				'steps'     => $this->lineSteps(3, 1, 2, 'CO-2405-0031', '2024-05-02', 'SH2405-0012', '2024-05-14'),
				'serials'   => isset($byLine[5001]) ? $byLine[5001] : array(),
			),
			array(
				'line_id'   => 5002,
				'product'   => 'Oxygen Concentrator Unit',
				'order_ref' => 'CO-2405-0031',
				'qty'       => 1,
				'picking'   => 1,
				'shipped'   => 0,
				'steps'     => $this->lineSteps(1, 1, 0, 'CO-2405-0031', '2024-05-02', '', ''),
				'serials'   => isset($byLine[5002]) ? $byLine[5002] : array(),
			),
			array(
				'line_id'   => 5003,
				'product'   => 'Booster Pump Assembly',
				'order_ref' => 'CO-2312-0018',
				'qty'       => 1,
				'picking'   => 0,
				'shipped'   => 1,
				'steps'     => $this->lineSteps(1, 0, 1, 'CO-2312-0018', '2023-11-28', 'SH2312-0007', '2023-12-06'),
				'serials'   => isset($byLine[5003]) ? $byLine[5003] : array(),
			),
		);
	}

	/**
	 *  Sample serials (back half + MO/production trace). Keyed by lot_id.
	 *
	 *  @return array
	 */
	private function sampleSerials()
	{
		global $langs;
		$ss = $this->serialStages();

		return array(
			array(
				'lot_id'     => 9001,
				'line_id'    => 5001,
				'serial'     => 'SN-2405-0012',
				'product'    => 'Stage 2 Compression System',
				'thirdparty' => 'Tristan Houle',
				'project'    => 'PJ2505-0024',
				'order_ref'  => 'CO-2405-0031',
				'steps'      => array(
					$this->step($ss[0], self::STATE_COMPLETE, 'MO-118',         '2024-05-10', $langs->trans('SerialtrackerStatusProduced')),
					$this->step($ss[1], self::STATE_COMPLETE, 'SH2405-0012',    '2024-05-14', $langs->trans('SerialtrackerStatusShipped')),
					$this->step($ss[2], self::STATE_CURRENT,  'exp 2026-05-14', '2024-05-14', $langs->trans('SerialtrackerStatusWarrantyActive')),
					$this->step($ss[3], self::STATE_PENDING,  '',               '',           ''),
				),
				'production' => array('mo_ref' => 'MO-118', 'stocked' => '2024-05-10'),
			),
			array(
				'lot_id'     => 9004,
				'line_id'    => 5001,
				'serial'     => 'SN-2405-0014',
				'product'    => 'Stage 2 Compression System',
				'thirdparty' => 'Tristan Houle',
				'project'    => 'PJ2505-0024',
				'order_ref'  => 'CO-2405-0031',
				'steps'      => array(
					$this->step($ss[0], self::STATE_COMPLETE, 'MO-118',         '2024-05-10', $langs->trans('SerialtrackerStatusProduced')),
					$this->step($ss[1], self::STATE_COMPLETE, 'SH2405-0012',    '2024-05-14', $langs->trans('SerialtrackerStatusShipped')),
					$this->step($ss[2], self::STATE_CURRENT,  'exp 2026-05-14', '2024-05-14', $langs->trans('SerialtrackerStatusWarrantyActive')),
					$this->step($ss[3], self::STATE_PENDING,  '',               '',           ''),
				),
				'production' => array('mo_ref' => 'MO-118', 'stocked' => '2024-05-10'),
			),
			array(
				'lot_id'     => 9003,
				'line_id'    => 5003,
				'serial'     => 'SN-2312-0007',
				'product'    => 'Booster Pump Assembly',
				'thirdparty' => 'Tristan Houle',
				'project'    => 'PJ2411-0009',
				'order_ref'  => 'CO-2312-0018',
				'steps'      => array(
					$this->step($ss[0], self::STATE_COMPLETE, 'MO-092',         '2023-12-02', $langs->trans('SerialtrackerStatusProduced')),
					$this->step($ss[1], self::STATE_COMPLETE, 'SH2312-0007',    '2023-12-06', $langs->trans('SerialtrackerStatusShipped')),
					$this->step($ss[2], self::STATE_COMPLETE, 'exp 2025-12-06', '2023-12-06', $langs->trans('SerialtrackerStatusWarrantyExpired')),
					$this->step($ss[3], self::STATE_ENDED,    '',               '2025-12-15', $langs->trans('SerialtrackerStatusSupportEnded')),
				),
				'production' => array('mo_ref' => 'MO-092', 'stocked' => '2023-12-02'),
			),
		);
	}

	/**
	 *  Current back-half stage key for a serial (current/ended, else last complete).
	 *
	 *  @param  array  $steps
	 *  @return string
	 */
	public function currentSerialStageKey($steps)
	{
		$lastComplete = '';
		foreach ($steps as $st) {
			if ($st['state'] === self::STATE_CURRENT || $st['state'] === self::STATE_ENDED) {
				return $st['key'];
			}
			if ($st['state'] === self::STATE_COMPLETE) {
				$lastComplete = $st['key'];
			}
		}
		return $lastComplete;
	}
}
