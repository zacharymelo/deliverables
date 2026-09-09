<?php
/* Copyright (C) 2026 Deliverables contributors
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
 *  \file       class/deliverablesresolver.class.php
 *  \ingroup    deliverables
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
 *  resolved per-serial (by lot) in the serial detail. All resolution is live;
 *  warranty / support-ended are routed through WarrantyAdapter (deferred
 *  while that module is overhauled).
 */
class DeliverablesResolver
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
			array('key' => 'ORD',  'label' => $langs->trans('DeliverablesStageOrdered'), 'label_todo' => $langs->trans('DeliverablesTodoOrdered')),
			array('key' => 'PICK', 'label' => $langs->trans('DeliverablesStagePicking'), 'label_todo' => $langs->trans('DeliverablesTodoPicking')),
			array('key' => 'SHIP', 'label' => $langs->trans('DeliverablesStageShipped'), 'label_todo' => $langs->trans('DeliverablesTodoShipped')),
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
			array('key' => 'MFG',  'label' => $langs->trans('DeliverablesStageManufactured'), 'label_todo' => $langs->trans('DeliverablesTodoManufactured')),
			array('key' => 'SHIP', 'label' => $langs->trans('DeliverablesStageShipped'),      'label_todo' => $langs->trans('DeliverablesTodoShipped')),
			array('key' => 'WARR', 'label' => $langs->trans('DeliverablesStageWarranty'),     'label_todo' => $langs->trans('DeliverablesTodoWarranty')),
			array('key' => 'EOL',  'label' => $langs->trans('DeliverablesStageSupportEnded'), 'label_todo' => $langs->trans('DeliverablesTodoSupportEnded')),
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
		return $this->liveSerial((int) $lotId);
	}

	/**
	 *  Compact counts for the project's slim main-card line.
	 *
	 *  @param  int  $projectId
	 *  @return array  ['lines','units','picking','shipped','in_warranty','support_ended']
	 */
	public function summaryForProject($projectId)
	{
		$rows = $this->resolveDeliverables(array('fk_project' => (int) $projectId));
		$sum = array('lines' => 0, 'units' => 0, 'shipped' => 0, 'outstanding' => 0, 'short' => 0);
		foreach ($rows as $r) {
			$sum['lines']++;
			$sum['units']       += (float) $r['ordered'];
			$sum['shipped']     += (float) $r['shipped'];
			$sum['outstanding'] += (float) $r['outstanding'];
			$sum['short']       += isset($r['shortfall']) ? (float) $r['shortfall'] : 0;
		}
		return $sum;
	}

	// -------------------------------------------------------------------------
	// Live resolution
	//
	// FRONT HALF (order line -> picking -> shipped, serial identity) resolves from
	// stable native tables: commande/commandedet, expedition(det|det_batch),
	// product_lot, product.tobatch. BACK HALF (warranty / support-ended) is routed
	// through WarrantyAdapter, which is deliberately isolated because that
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
		$sqlS = "SELECT e.rowid as expedition_id, e.ref as ship_ref, e.date_expedition, e.fk_projet, e.fk_soc, ed.fk_elementdet as line_id"
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
			$langs->trans('DeliverablesStatusProduced'));

		// SHIP
		if ($ship && !empty($ship->ship_ref)) {
			$steps[] = $this->step($ss[1], self::STATE_COMPLETE, $ship->ship_ref,
				($ship->date_expedition ? $this->db->jdate($ship->date_expedition) : 0),
				$langs->trans('DeliverablesStatusShipped'));
		} else {
			$steps[] = $this->step($ss[1], self::STATE_PENDING, '', 0, '');
		}

		// WARR / EOL via the isolated adapter (deferred -> pending).
		dol_include_once('/deliverables/class/warrantyadapter.class.php');
		$w = class_exists('WarrantyAdapter')
			? WarrantyAdapter::forSerial($this->db, $batch, (int) $lot->fk_product)
			: null;
		if ($w === null) {
			$steps[] = $this->step($ss[2], self::STATE_PENDING, '', 0, $langs->trans('DeliverablesWarrantyPending'));
			$steps[] = $this->step($ss[3], self::STATE_PENDING, '', 0, '');
		} else {
			$warrState = !empty($w['active']) ? self::STATE_CURRENT : self::STATE_COMPLETE;
			$steps[] = $this->step($ss[2], $warrState, (!empty($w['expiry_str']) ? $w['expiry_str'] : ''),
				(!empty($w['start']) ? (int) $w['start'] : 0), (isset($w['status_label']) ? $w['status_label'] : ''));
			$eolState = !empty($w['ended']) ? self::STATE_ENDED : self::STATE_PENDING;
			$steps[] = $this->step($ss[3], $eolState, '', (!empty($w['ended_ts']) ? (int) $w['ended_ts'] : 0), '');
		}

		// Context — capture ids (not just refs) so the detail page can link out.
		$expeditionId = ($ship && !empty($ship->expedition_id)) ? (int) $ship->expedition_id : 0;
		$fkProjet     = ($ship && !empty($ship->fk_projet)) ? (int) $ship->fk_projet : 0;
		$fkSoc        = ($ship && !empty($ship->fk_soc)) ? (int) $ship->fk_soc : 0;

		$orderRef = '';
		$commandeId = 0;
		if ($ship && !empty($ship->line_id)) {
			$rc = $this->db->query("SELECT c.rowid as commande_id, c.ref FROM ".MAIN_DB_PREFIX."commandedet cd"
				." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
				." WHERE cd.rowid = ".((int) $ship->line_id));
			if ($rc && ($rco = $this->db->fetch_object($rc))) {
				$orderRef   = $rco->ref;
				$commandeId = (int) $rco->commande_id;
			}
		}
		$projectRef = '';
		if ($fkProjet > 0) {
			$rp = $this->db->query("SELECT ref FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".$fkProjet);
			if ($rp && ($rpo = $this->db->fetch_object($rp))) {
				$projectRef = $rpo->ref;
			}
		}
		$thirdparty = '';
		if ($fkSoc > 0) {
			$rt = $this->db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".$fkSoc);
			if ($rt && ($rto = $this->db->fetch_object($rt))) {
				$thirdparty = $rto->nom;
			}
		}

		// Manufacturing order that produced this serial (mrp_production produced-role row).
		$moId = 0;
		$moRef = '';
		$rm = $this->db->query("SELECT mp.fk_mo, m.ref FROM ".MAIN_DB_PREFIX."mrp_production mp"
			." INNER JOIN ".MAIN_DB_PREFIX."mrp_mo m ON m.rowid = mp.fk_mo"
			." WHERE mp.batch = '".$this->db->escape($batch)."' AND mp.role = 'produced'"
			." AND m.fk_product = ".((int) $lot->fk_product)
			." ORDER BY mp.rowid DESC");
		if ($rm && ($rmo = $this->db->fetch_object($rm))) {
			$moId  = (int) $rmo->fk_mo;
			$moRef = $rmo->ref;
		}

		return array(
			'lot_id'             => (int) $lot->rowid,
			'serial'             => $batch,
			'product'            => ($lot->product_label != '' ? $lot->product_label : $lot->product_ref),
			'product_id'         => (int) $lot->fk_product,
			'thirdparty'         => $thirdparty,
			'fk_soc'             => $fkSoc,
			'project'            => $projectRef,
			'fk_projet'          => $fkProjet,
			'order_ref'          => $orderRef,
			'commande_id'        => $commandeId,
			'ship_ref'           => ($ship && !empty($ship->ship_ref)) ? $ship->ship_ref : '',
			'expedition_id'      => $expeditionId,
			'mo_ref'             => $moRef,
			'mo_id'              => $moId,
			'manufacturing_date' => ($lot->manufacturing_date ? $this->db->jdate($lot->manufacturing_date) : 0),
			'eol_date'           => ($lot->eol_date ? $this->db->jdate($lot->eol_date) : 0),
			'steps'              => $steps,
		);
	}

	// -------------------------------------------------------------------------
	// Deliverables + ATP shortfall (native/stable tables only)
	// -------------------------------------------------------------------------

	/**
	 *  Available-to-promise per product (company-wide), with shortfall.
	 *
	 *    on_hand   = SUM(product_stock.reel) across all warehouses
	 *    incoming  = SUM(mrp_mo.qty) for in-progress MOs (status 2)
	 *    committed = SUM per-line max(0, ordered - shipped) over open sales orders
	 *                (status per DELIVERABLES_ATP_DEMAND_ALL; within the time window)
	 *    shortfall = max(0, committed - on_hand - incoming)
	 *
	 *  Action routing: a product with an active BOM is manufacturable (Create MO);
	 *  otherwise it is purchased (reorder / PO).
	 *
	 *  @param  int[]  $productIds
	 *  @return array  productId => [onhand, incoming, committed, shortfall, manufacturable, bom_id]
	 */
	public function atpForProducts($productIds)
	{
		$out = array();
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if (empty($productIds)) {
			return $out;
		}
		$in = implode(',', $productIds);
		foreach ($productIds as $pid) {
			$out[$pid] = array('onhand' => 0, 'incoming' => 0, 'committed' => 0, 'shortfall' => 0, 'manufacturable' => false, 'bom_id' => 0);
		}

		// On-hand, all warehouses.
		$r = $this->db->query("SELECT fk_product, SUM(reel) as onhand FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product IN (".$in.") GROUP BY fk_product");
		if ($r) {
			while ($o = $this->db->fetch_object($r)) {
				$out[(int) $o->fk_product]['onhand'] = (float) $o->onhand;
			}
		}

		// Incoming = in-progress MOs only (status 2).
		$r = $this->db->query("SELECT fk_product, SUM(qty) as inc FROM ".MAIN_DB_PREFIX."mrp_mo WHERE fk_product IN (".$in.") AND status = 2 AND entity IN (".getEntity('mrp_mo').") GROUP BY fk_product");
		if ($r) {
			while ($o = $this->db->fetch_object($r)) {
				$out[(int) $o->fk_product]['incoming'] = (float) $o->inc;
			}
		}

		// Manufacturable = has an active BOM (status 1).
		$r = $this->db->query("SELECT fk_product, MIN(rowid) as bom_id FROM ".MAIN_DB_PREFIX."bom_bom WHERE fk_product IN (".$in.") AND status = 1 AND entity IN (".getEntity('bom_bom').") GROUP BY fk_product");
		if ($r) {
			while ($o = $this->db->fetch_object($r)) {
				$out[(int) $o->fk_product]['bom_id'] = (int) $o->bom_id;
				$out[(int) $o->fk_product]['manufacturable'] = true;
			}
		}

		// Committed = per-line outstanding across open orders (netted, windowed).
		$statuses   = (getDolGlobalString('DELIVERABLES_ATP_DEMAND_ALL', '0') === '1') ? '0,1,2' : '1,2';
		$windowDays = (int) getDolGlobalInt('DELIVERABLES_ATP_WINDOW_DAYS', 180);
		$windowClause = '';
		if ($windowDays > 0) {
			$windowClause = " AND c.date_commande >= '".$this->db->idate(dol_now() - ($windowDays * 86400))."'";
		}
		$sql = "SELECT cd.fk_product, cd.qty as ordered,"
			." COALESCE((SELECT SUM(ed.qty) FROM ".MAIN_DB_PREFIX."expeditiondet ed"
			."  INNER JOIN ".MAIN_DB_PREFIX."expedition e2 ON e2.rowid = ed.fk_expedition AND e2.fk_statut >= 1"
			."  WHERE ed.element_type = 'commande' AND ed.fk_elementdet = cd.rowid), 0) as shipped"
			." FROM ".MAIN_DB_PREFIX."commandedet cd"
			." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande"
			." WHERE cd.fk_product IN (".$in.")"
			." AND c.fk_statut IN (".$statuses.")"
			." AND c.entity IN (".getEntity('commande').")"
			.$windowClause;
		$r = $this->db->query($sql);
		if ($r) {
			while ($o = $this->db->fetch_object($r)) {
				$out[(int) $o->fk_product]['committed'] += max(0, (float) $o->ordered - (float) $o->shipped);
			}
		}

		foreach ($out as $pid => $a) {
			$out[$pid]['shortfall'] = max(0, $a['committed'] - $a['onhand'] - $a['incoming']);
		}
		return $out;
	}

	/**
	 *  Best supplier options per product, for the reorder action: the cheapest
	 *  vendor (by unit price) and the fastest (by delivery_time_days), so a worker
	 *  can pick speed vs cost. A product with no supplier price returns nothing
	 *  (Reorder then opens the wizard with no vendor pre-chosen).
	 *
	 *  @param  int[]  $productIds
	 *  @return array  productId => ['cheapest'=>[vendor_id,vendor,unit,lead], 'fastest'=>[...] (only if a different, faster vendor)]
	 */
	public function supplierOptions($productIds)
	{
		$out = array();
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		if (empty($productIds)) {
			return $out;
		}
		$sql = "SELECT pfp.fk_product, pfp.fk_soc, s.nom as vendor,"
			." pfp.unitprice, pfp.price, pfp.quantity, pfp.delivery_time_days as lead"
			." FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp"
			." INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = pfp.fk_soc"
			." WHERE pfp.fk_product IN (".implode(',', $productIds).")"
			." AND pfp.entity IN (".getEntity('product').")";
		$r = $this->db->query($sql);
		if (!$r) {
			return $out;
		}
		$byProd = array();
		while ($o = $this->db->fetch_object($r)) {
			$unit = (float) $o->unitprice;
			if ($unit <= 0) {
				$qy = (float) $o->quantity;
				$unit = ($qy > 0) ? ((float) $o->price / $qy) : (float) $o->price;
			}
			$byProd[(int) $o->fk_product][] = array(
				'vendor_id' => (int) $o->fk_soc,
				'vendor'    => $o->vendor,
				'unit'      => $unit,
				'lead'      => (int) $o->lead,
			);
		}
		foreach ($byProd as $pid => $rowsP) {
			$cheapest = null;
			foreach ($rowsP as $row) {
				if ($row['unit'] <= 0) {
					continue;
				}
				if ($cheapest === null || $row['unit'] < $cheapest['unit']) {
					$cheapest = $row;
				}
			}
			if ($cheapest === null) {
				$cheapest = $rowsP[0]; // no priced row — still surface a vendor
			}
			$fastest = null;
			foreach ($rowsP as $row) {
				if ($row['lead'] <= 0) {
					continue;
				}
				if ($fastest === null || $row['lead'] < $fastest['lead']) {
					$fastest = $row;
				}
			}
			$entry = array('cheapest' => $cheapest);
			if ($fastest !== null && (int) $fastest['vendor_id'] !== (int) $cheapest['vendor_id']) {
				$entry['fastest'] = $fastest;
			}
			$out[$pid] = $entry;
		}
		return $out;
	}

	/**
	 *  Deliverables for a project/customer: every order line with fulfillment
	 *  counts, deduped serials, and the product's ATP/shortfall. Not collapsed.
	 *
	 *  @param  array  $filter  fk_project | fk_soc
	 *  @return array
	 */
	public function resolveDeliverables($filter)
	{
		if (!empty($filter['fk_project'])) {
			$where = "c.fk_projet = ".((int) $filter['fk_project']);
		} elseif (!empty($filter['fk_soc'])) {
			$where = "c.fk_soc = ".((int) $filter['fk_soc']);
		} else {
			return array();
		}

		$sql = "SELECT cd.rowid as line_id, cd.fk_product, cd.qty,"
			." c.rowid as commande_id, c.ref as order_ref,"
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
		$rows = array();
		$ids = array();
		$pids = array();
		while ($o = $this->db->fetch_object($res)) {
			$id = (int) $o->line_id;
			$rows[$id] = array(
				'line_id'     => $id,
				'product_id'  => (int) $o->fk_product,
				'product'     => ($o->product_label != '' ? $o->product_label : $o->product_ref),
				'order_ref'   => $o->order_ref,
				'commande_id' => (int) $o->commande_id,
				'ordered'     => (float) $o->qty,
				'serialized' => ((int) $o->tobatch > 0),
				'shipped'    => 0,
				'serials'    => array(),
			);
			$ids[] = $id;
			$pids[(int) $o->fk_product] = (int) $o->fk_product;
		}
		if (empty($ids)) {
			return array();
		}
		$inIds = implode(',', $ids);

		// This order's shipped qty per line (validated shipments).
		$r2 = $this->db->query("SELECT ed.fk_elementdet as line_id, SUM(ed.qty) as shipped"
			." FROM ".MAIN_DB_PREFIX."expeditiondet ed"
			." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition AND e.fk_statut >= 1"
			." WHERE ed.element_type = 'commande' AND ed.fk_elementdet IN (".$inIds.")"
			." AND e.entity IN (".getEntity('expedition').")"
			." GROUP BY ed.fk_elementdet");
		if ($r2) {
			while ($o = $this->db->fetch_object($r2)) {
				if (isset($rows[(int) $o->line_id])) {
					$rows[(int) $o->line_id]['shipped'] = (float) $o->shipped;
				}
			}
		}

		// Serials, deduped by batch (a returned+reshipped serial appears once).
		$serIds = array();
		foreach ($rows as $id => $r) {
			if ($r['serialized']) {
				$serIds[] = $id;
			}
		}
		if (!empty($serIds)) {
			$r3 = $this->db->query("SELECT ed.fk_elementdet as line_id, eb.batch, ed.fk_product, pl.rowid as lot_id"
				." FROM ".MAIN_DB_PREFIX."expeditiondet ed"
				." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition AND e.fk_statut >= 1"
				." INNER JOIN ".MAIN_DB_PREFIX."expeditiondet_batch eb ON eb.fk_expeditiondet = ed.rowid"
				." LEFT JOIN ".MAIN_DB_PREFIX."product_lot pl ON pl.batch = eb.batch AND pl.fk_product = ed.fk_product"
				." WHERE ed.element_type = 'commande' AND ed.fk_elementdet IN (".implode(',', $serIds).")"
				." AND e.entity IN (".getEntity('expedition').")"
				." ORDER BY eb.batch");
			if ($r3) {
				$seen = array();
				while ($o = $this->db->fetch_object($r3)) {
					$k = ((int) $o->line_id).'|'.$o->batch;
					if (isset($seen[$k])) {
						continue;
					}
					$seen[$k] = 1;
					if (isset($rows[(int) $o->line_id])) {
						$rows[(int) $o->line_id]['serials'][] = array('lot_id' => (int) $o->lot_id, 'serial' => $o->batch);
					}
				}
			}
		}

		$atp = $this->atpForProducts(array_values($pids));
		$src = $this->supplierOptions(array_values($pids));

		$out = array();
		foreach ($rows as $r) {
			$shipped = min($r['shipped'], $r['ordered']); // clamp reship inflation for display
			$r['shipped']     = $shipped;
			$r['outstanding'] = max(0, $r['ordered'] - $shipped);
			$a = isset($atp[$r['product_id']]) ? $atp[$r['product_id']]
				: array('onhand' => 0, 'incoming' => 0, 'committed' => 0, 'shortfall' => 0, 'manufacturable' => false, 'bom_id' => 0);
			$r = array_merge($r, $a);
			$r['source'] = isset($src[$r['product_id']]) ? $src[$r['product_id']] : null;
			$out[] = $r;
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Step / stage helpers (shared by live resolution)
	// -------------------------------------------------------------------------

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
			$langs->trans('DeliverablesCountOfShort', $qty));

		// Picking: current while units are being assigned but not all shipped.
		$pickState = ($shipped >= $qty) ? self::STATE_COMPLETE
			: (($picking > 0 || $shipped > 0) ? self::STATE_CURRENT : self::STATE_PENDING);
		$picking = $this->step($os[1], $pickState,
			($picking > 0 ? $langs->trans('DeliverablesCountOf', $picking, $qty) : ''),
			'', '');

		// Shipped: complete when all shipped, current when some shipped.
		$shipState = ($shipped >= $qty) ? self::STATE_COMPLETE
			: ($shipped > 0 ? self::STATE_CURRENT : self::STATE_PENDING);
		$shippedStep = $this->step($os[2], $shipState,
			$langs->trans('DeliverablesCountOf', $shipped, $qty),
			($shipped > 0 ? $shipDate : ''),
			($shipRef !== '' ? $shipRef : ''));

		return array($ordered, $picking, $shippedStep);
	}
}
