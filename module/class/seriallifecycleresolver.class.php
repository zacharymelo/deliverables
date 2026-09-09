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
			$sum['lines']++;
			$sum['units']   += (int) $l['qty'];
			$sum['picking'] += (int) $l['picking'];
			$sum['shipped'] += (int) $l['shipped'];
			foreach ($l['serials'] as $srl) {
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
	// Live resolution (STUB — verify order->shipment->lot linkage first)
	// -------------------------------------------------------------------------

	/**
	 *  STUB — order lines from live data. $filter: fk_project | fk_soc.
	 *  @param  array  $filter
	 *  @return array
	 */
	private function liveLines($filter)
	{
		return array();
	}

	/**
	 *  STUB — one serial from live data, tracing its lot to MO/production.
	 *  @param  int  $lotId
	 *  @return array|null
	 */
	private function liveSerial($lotId)
	{
		return null;
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
	private function step($base, $state, $ref = '', $dateStr = '', $statusLabel = '')
	{
		$base['state']        = $state;
		$base['ref']          = $ref;
		$base['date']         = ($dateStr !== '') ? dol_stringtotime($dateStr, 1) : 0;
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
