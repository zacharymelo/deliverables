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
 *  \brief      Resolves the lifecycle steps for each serial linked to a project.
 */

/**
 *  SerialLifecycleResolver
 *
 *  Returns, for a project, a list of serials and each serial's lifecycle steps.
 *  The lifecycle is fixed for the prototype:
 *      Manufactured (MO) -> Shipped (expedition) -> Under Warranty -> Support Ended
 *
 *  IMPORTANT: live evidence resolution is NOT wired yet. The linkage from a
 *  project to its serials, and from a serial to MO / shipment / warranty state,
 *  must be verified against the real schema first (see ajax/debug.php) before
 *  findSerialsForProject()/resolveSteps() are pointed at live data. Until then,
 *  SERIALTRACKER_SAMPLE = 1 returns mock serials so the UX can be evaluated.
 */
class SerialLifecycleResolver
{
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_ENDED    = 'ended';

	/** @var DoliDB */
	public $db;

	/** @var bool  True when the returned serials are mock data, not live */
	public $isSample = false;

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Canonical lifecycle stages (order matters).
	 *
	 *  @return array  List of ['key' => ..., 'label' => ...]
	 */
	public function stages()
	{
		global $langs;
		return array(
			array('key' => 'MFG',  'label' => $langs->trans('SerialtrackerStageManufactured')),
			array('key' => 'SHIP', 'label' => $langs->trans('SerialtrackerStageShipped')),
			array('key' => 'WARR', 'label' => $langs->trans('SerialtrackerStageWarranty')),
			array('key' => 'EOL',  'label' => $langs->trans('SerialtrackerStageSupportEnded')),
		);
	}

	/**
	 *  Build the per-serial steps for a project.
	 *
	 *  @param  int  $projectId
	 *  @return array  List of serials: ['serial', 'product', 'steps' => [...]]
	 */
	public function resolveForProject($projectId)
	{
		$projectId = (int) $projectId;

		$useSample = (getDolGlobalString('SERIALTRACKER_SAMPLE', '1') === '1');
		if ($useSample) {
			$this->isSample = true;
			return $this->sampleSerials();
		}

		// --- Live path (not yet wired — verify linkage via ajax/debug.php first) ---
		$serials = $this->findSerialsForProject($projectId);
		$out = array();
		foreach ($serials as $s) {
			$out[] = array(
				'serial'  => $s['serial'],
				'product' => $s['product'],
				'steps'   => $this->resolveSteps($s),
			);
		}
		return $out;
	}

	/**
	 *  Discover the serials (product_lot) tied to a project.
	 *
	 *  STUB — returns nothing until the real linkage is confirmed. Candidate paths
	 *  to verify (see ajax/debug.php): shipments on the project -> expedition batch
	 *  lines -> product_lot; or product_lot rows filtered by the project's company.
	 *
	 *  @param  int  $projectId
	 *  @return array  List of ['serial', 'product', plus raw evidence ids]
	 */
	private function findSerialsForProject($projectId)
	{
		return array();
	}

	/**
	 *  Map a serial's raw evidence to lifecycle step states.
	 *
	 *  STUB — every stage pending until live evidence (MO done, shipped, warranty
	 *  active/expired) is wired.
	 *
	 *  @param  array  $serial
	 *  @return array  Steps with states
	 */
	private function resolveSteps($serial)
	{
		$steps = array();
		foreach ($this->stages() as $stage) {
			$stage['state'] = self::STATE_PENDING;
			$steps[] = $stage;
		}
		return $steps;
	}

	/**
	 *  Mock serials covering every visual state, for the UX preview.
	 *
	 *  @return array
	 */
	private function sampleSerials()
	{
		$st = $this->stages();
		$mk = function ($states) use ($st) {
			$steps = array();
			foreach ($st as $i => $stage) {
				$stage['state'] = isset($states[$i]) ? $states[$i] : self::STATE_PENDING;
				$steps[] = $stage;
			}
			return $steps;
		};

		return array(
			array(
				'serial'  => 'SN-2405-0012',
				'product' => 'Stage 2 Compression System',
				'steps'   => $mk(array(self::STATE_COMPLETE, self::STATE_COMPLETE, self::STATE_CURRENT, self::STATE_PENDING)),
			),
			array(
				'serial'  => 'SN-2405-0013',
				'product' => 'Oxygen Concentrator Unit',
				'steps'   => $mk(array(self::STATE_COMPLETE, self::STATE_CURRENT, self::STATE_PENDING, self::STATE_PENDING)),
			),
			array(
				'serial'  => 'SN-2312-0007',
				'product' => 'Booster Pump Assembly',
				'steps'   => $mk(array(self::STATE_COMPLETE, self::STATE_COMPLETE, self::STATE_COMPLETE, self::STATE_ENDED)),
			),
		);
	}
}
