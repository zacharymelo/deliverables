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
 *  \file       class/seriallifecyclerenderer.class.php
 *  \ingroup    serialtracker
 *  \brief      Pure HTML renderer — a list of per-serial lifecycle steppers.
 */

/**
 *  SerialLifecycleRenderer
 *
 *  Converts the resolver's per-serial steps into an HTML panel: a titled block
 *  containing one compact horizontal stepper per serial. Output only — no DB.
 */
class SerialLifecycleRenderer
{
	/** @var bool  Whether the data shown is sample/mock (adds a preview banner) */
	public $isSample = false;

	/**
	 *  Render the per-serial lifecycle list.
	 *
	 *  @param  array  $serials  List of ['serial', 'product', 'steps' => [...]]
	 *  @return string            HTML (empty string if nothing to show)
	 */
	public function render($serials)
	{
		global $langs;

		$langs->loadLangs(array('serialtracker@serialtracker'));

		if (empty($serials) || !is_array($serials)) {
			return '';
		}

		$out  = '<div class="serialtracker-panel">';
		$out .= '<div class="serialtracker-head">';
		$out .= '<span class="serialtracker-title">'.dol_escape_htmltag($langs->trans('SerialtrackerTitle')).'</span>';
		if ($this->isSample) {
			$out .= ' <span class="serialtracker-sample-tag">'.dol_escape_htmltag($langs->trans('SerialtrackerSampleTag')).'</span>';
		}
		$out .= '</div>';

		foreach ($serials as $serial) {
			$out .= $this->renderSerialRow($serial);
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Render a single serial row: identity on the left, stepper on the right.
	 *
	 *  @param  array  $serial
	 *  @return string
	 */
	private function renderSerialRow($serial)
	{
		$steps   = isset($serial['steps']) && is_array($serial['steps']) ? $serial['steps'] : array();
		$serialN = isset($serial['serial']) ? $serial['serial'] : '';
		$product = isset($serial['product']) ? $serial['product'] : '';

		$out  = '<div class="serialtracker-row">';

		$out .= '<div class="serialtracker-ident">';
		$out .= '<span class="serialtracker-serial">'.dol_escape_htmltag($serialN).'</span>';
		if ($product !== '') {
			$out .= '<span class="serialtracker-product">'.dol_escape_htmltag($product).'</span>';
		}
		$out .= '</div>';

		$out .= '<div class="serialtracker-track" role="list">';
		$prevState = null;
		foreach ($steps as $idx => $step) {
			$out .= $this->renderStep($step, ($idx > 0), $prevState);
			$prevState = isset($step['state']) ? $step['state'] : null;
		}
		$out .= '</div>';

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Render a single step: connector + circle + label.
	 *
	 *  @param  array   $step
	 *  @param  bool    $withConnector
	 *  @param  string  $prevState
	 *  @return string
	 */
	private function renderStep($step, $withConnector, $prevState = null)
	{
		$state = isset($step['state']) ? $step['state'] : 'pending';
		$label = isset($step['label']) ? $step['label'] : '';

		$stateClass = 'serialtracker-'.preg_replace('/[^a-z]/', '', $state);

		$done = array('complete', 'current', 'ended');
		$connectorClass = (in_array($prevState, $done, true) && in_array($state, $done, true))
			? ' serialtracker-connector-complete' : '';

		$out = '<div class="serialtracker-step '.$stateClass.'" role="listitem">';
		if ($withConnector) {
			$out .= '<span class="serialtracker-connector'.$connectorClass.'" aria-hidden="true"></span>';
		}
		$out .= '<span class="serialtracker-circle" aria-hidden="true">'.$this->glyph($state).'</span>';
		$out .= '<span class="serialtracker-label">'.dol_escape_htmltag($label).'</span>';
		$out .= '</div>';

		return $out;
	}

	/**
	 *  Glyph for a circle interior.
	 *
	 *  @param  string  $state
	 *  @return string
	 */
	private function glyph($state)
	{
		switch ($state) {
			case 'complete':
				return '<span class="serialtracker-glyph">&#10003;</span>';
			case 'ended':
				return '<span class="serialtracker-glyph">&#9632;</span>';
			default:
				return '<span class="serialtracker-glyph"></span>';
		}
	}
}
