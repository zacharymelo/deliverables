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
 *  \brief      Pure HTML renderer for the fulfillment journey. No DB access.
 *
 *  Mirrors the Order Progress tracker's subtitle + tooltip richness: each step
 *  carries a subtitle (ref/count under the label) and a native title tooltip
 *  built from ref / status / date, plus a "what's still needed" hint and to-do
 *  phrasing on open steps.
 */
class SerialLifecycleRenderer
{
	/** @var bool  Whether the data shown is sample/mock */
	public $isSample = false;

	/** @var array<string,string>  Hint lang key per step key — shown for open steps */
	private static $hintKeys = array(
		'ORD'  => 'SerialtrackerHintOrdered',
		'PICK' => 'SerialtrackerHintPicking',
		'SHIP' => 'SerialtrackerHintShipped',
		'MFG'  => 'SerialtrackerHintManufactured',
		'WARR' => 'SerialtrackerHintWarranty',
		'EOL'  => 'SerialtrackerHintSupportEnded',
	);

	// -------------------------------------------------------------------------
	// Order-line list (project / customer tabs)
	// -------------------------------------------------------------------------

	/**
	 *  Render the list of order lines, each with its front-half journey and the
	 *  serials assigned to it.
	 *
	 *  @param  array  $lines  From SerialLifecycleResolver::resolveForProject()/ForThirdparty()
	 *  @return string          HTML (empty if nothing)
	 */
	public function renderLines($lines)
	{
		global $langs;
		$langs->loadLangs(array('serialtracker@serialtracker'));

		if (empty($lines) || !is_array($lines)) {
			return '';
		}

		$out  = '<div class="serialtracker-panel">';
		if ($this->isSample) {
			$out .= '<div class="serialtracker-head"><span class="serialtracker-sample-tag">'
				.dol_escape_htmltag($langs->trans('SerialtrackerSampleTag')).'</span></div>';
		}

		foreach ($lines as $line) {
			if (!empty($line['is_summary'])) {
				$out .= $this->renderSummaryRow($line);
			} else {
				$out .= $this->renderLineRow($line);
			}
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 *  Collapsed row for the non-serialized order lines (parts/fittings), so
	 *  fulfillment of the extras is visible without cluttering the machine rows.
	 *
	 *  @param  array  $line  ['is_summary'=>true, 'lines', 'qty', 'shipped']
	 *  @return string
	 */
	private function renderSummaryRow($line)
	{
		global $langs;

		$n       = (int) $line['lines'];
		$qty     = (int) $line['qty'];
		$shipped = (int) $line['shipped'];

		$out  = '<div class="serialtracker-line serialtracker-otherline">';
		$out .= '<div class="serialtracker-ident"><span class="serialtracker-otherlabel">'
			.dol_escape_htmltag($langs->trans('SerialtrackerOtherLines', $n)).'</span></div>';
		$out .= '<div class="serialtracker-linebody"><span class="serialtracker-othermeta">'
			.dol_escape_htmltag($langs->trans('SerialtrackerOtherShipped', $shipped, $qty)).'</span></div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 *  One order-line row: identity + front-half stepper + serial chips.
	 *
	 *  @param  array  $line
	 *  @return string
	 */
	private function renderLineRow($line)
	{
		global $langs;

		$product = isset($line['product']) ? $line['product'] : '';
		$orderR  = isset($line['order_ref']) ? $line['order_ref'] : '';
		$qty     = isset($line['qty']) ? (int) $line['qty'] : 0;
		$steps   = isset($line['steps']) && is_array($line['steps']) ? $line['steps'] : array();
		$serials = isset($line['serials']) && is_array($line['serials']) ? $line['serials'] : array();

		$out  = '<div class="serialtracker-line">';

		$out .= '<div class="serialtracker-ident">';
		$out .= '<span class="serialtracker-product">'.dol_escape_htmltag($product).'</span>';
		$meta = array();
		if ($orderR !== '') { $meta[] = $orderR; }
		$meta[] = $langs->trans('SerialtrackerQtyUnits', $qty);
		$out .= '<span class="serialtracker-linemeta">'.dol_escape_htmltag(implode(' · ', $meta)).'</span>';
		$out .= '</div>';

		$out .= '<div class="serialtracker-linebody">';
		$out .= $this->renderTrack($steps);

		if (!empty($serials)) {
			$out .= '<div class="serialtracker-chips">';
			foreach ($serials as $srl) {
				$out .= $this->renderSerialChip($srl);
			}
			$out .= '</div>';
		}
		$out .= '</div>';

		$out .= '</div>';
		return $out;
	}

	/**
	 *  A serial chip that links to the serial detail page, colored by back-half stage.
	 *
	 *  @param  array  $srl  ['lot_id','serial','stage']
	 *  @return string
	 */
	private function renderSerialChip($srl)
	{
		global $langs;

		$lotId  = isset($srl['lot_id']) ? (int) $srl['lot_id'] : 0;
		$serial = isset($srl['serial']) ? $srl['serial'] : '';
		$stage  = isset($srl['stage']) ? $srl['stage'] : '';

		$stageClass = 'serialtracker-chip-'.preg_replace('/[^a-z]/', '', strtolower($stage));
		$title = $this->stageTitle($stage);
		$titleAttr = ($title !== '' ? ' title="'.dol_escape_htmltag($title).'"' : '');

		$inner = '<span class="serialtracker-chip-dot" aria-hidden="true"></span>'.dol_escape_htmltag($serial);

		// Only link when we have a lot record to open; otherwise show a plain chip.
		if ($lotId > 0) {
			$url = dol_buildpath('/serialtracker/serial_card.php', 1).'?id='.$lotId;
			return '<a class="serialtracker-chip '.$stageClass.'" href="'.dol_escape_htmltag($url).'"'.$titleAttr.'>'.$inner.'</a>';
		}
		return '<span class="serialtracker-chip '.$stageClass.'"'.$titleAttr.'>'.$inner.'</span>';
	}

	/**
	 *  Human label for a back-half stage key (chip tooltip).
	 *
	 *  @param  string  $stage
	 *  @return string
	 */
	private function stageTitle($stage)
	{
		global $langs;
		switch ($stage) {
			case 'MFG':  return $langs->trans('SerialtrackerStageManufactured');
			case 'SHIP': return $langs->trans('SerialtrackerStageShipped');
			case 'WARR': return $langs->trans('SerialtrackerStatusWarrantyActive');
			case 'EOL':  return $langs->trans('SerialtrackerStatusSupportEnded');
			default:     return '';
		}
	}

	// -------------------------------------------------------------------------
	// Serial detail (serial_card.php)
	// -------------------------------------------------------------------------

	/**
	 *  Render one serial's back-half journey plus its production/link detail.
	 *
	 *  @param  array  $serial  From SerialLifecycleResolver::resolveSerial()
	 *  @return string
	 */
	public function renderSerialDetail($serial)
	{
		global $langs;
		$langs->loadLangs(array('serialtracker@serialtracker'));

		if (empty($serial) || !is_array($serial)) {
			return '';
		}

		$steps = isset($serial['steps']) && is_array($serial['steps']) ? $serial['steps'] : array();

		$out  = '<div class="serialtracker-panel serialtracker-detail">';
		$out .= '<div class="serialtracker-detailtrack">'.$this->renderTrack($steps).'</div>';

		// Production trace (MO, by lot) + context links.
		$rows = array();
		if (!empty($serial['production']['mo_ref'])) {
			$val = $serial['production']['mo_ref'];
			if (!empty($serial['production']['stocked'])) {
				$val .= ' · '.dol_print_date(dol_stringtotime($serial['production']['stocked'], 1), 'day');
			}
			$rows[$langs->trans('SerialtrackerFieldProduction')] = $val;
		}
		if (!empty($serial['order_ref']))  { $rows[$langs->trans('SerialtrackerFieldOrder')]      = $serial['order_ref']; }
		if (!empty($serial['project']))    { $rows[$langs->trans('SerialtrackerFieldProject')]    = $serial['project']; }
		if (!empty($serial['thirdparty'])) { $rows[$langs->trans('SerialtrackerFieldThirdparty')] = $serial['thirdparty']; }

		if (!empty($rows)) {
			$out .= '<table class="serialtracker-detailtable">';
			foreach ($rows as $k => $v) {
				$out .= '<tr><td class="serialtracker-dt-key">'.dol_escape_htmltag($k).'</td>'
					.'<td>'.dol_escape_htmltag($v).'</td></tr>';
			}
			$out .= '</table>';
		}

		$out .= '</div>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Slim main-card summary line
	// -------------------------------------------------------------------------

	/**
	 *  One-line fulfillment summary for the project main card, linking to the tab.
	 *
	 *  @param  array   $sum     From SerialLifecycleResolver::summaryForProject()
	 *  @param  string  $tabUrl  URL of the project fulfillment tab
	 *  @return string
	 */
	public function renderSummaryLine($sum, $tabUrl)
	{
		global $langs;
		$langs->loadLangs(array('serialtracker@serialtracker'));

		if (empty($sum) || (int) $sum['lines'] === 0) {
			return '';
		}

		$parts = array();
		$parts[] = $langs->trans('SerialtrackerSummaryUnits', $this->fmt($sum['units']), (int) $sum['lines']);
		$parts[] = $langs->trans('SerialtrackerSummaryShipped', $this->fmt($sum['shipped']));
		if ((float) $sum['outstanding'] > 0) { $parts[] = $langs->trans('SerialtrackerSummaryOutstanding', $this->fmt($sum['outstanding'])); }
		if ((float) $sum['short'] > 0)       { $parts[] = $langs->trans('SerialtrackerSummaryShort', $this->fmt($sum['short'])); }

		$out  = '<div class="serialtracker-summary">';
		$out .= '<span class="serialtracker-summary-label">'.dol_escape_htmltag($langs->trans('SerialtrackerTitle')).':</span> ';
		$out .= dol_escape_htmltag(implode(' · ', $parts));
		$out .= ' <a class="serialtracker-summary-link" href="'.dol_escape_htmltag($tabUrl).'">'
			.dol_escape_htmltag($langs->trans('SerialtrackerViewAll')).' &rarr;</a>';
		if ($this->isSample) {
			$out .= ' <span class="serialtracker-sample-tag">'.dol_escape_htmltag($langs->trans('SerialtrackerSampleTag')).'</span>';
		}
		$out .= '</div>';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Deliverables + shortfall table (project / customer tabs)
	// -------------------------------------------------------------------------

	/**
	 *  Render the deliverables table: per order line, fulfillment + ATP/shortfall.
	 *
	 *  @param  array  $rows  From SerialLifecycleResolver::resolveDeliverables()
	 *  @return string
	 */
	public function renderDeliverables($rows)
	{
		global $langs;
		$langs->loadLangs(array('serialtracker@serialtracker'));

		if (empty($rows) || !is_array($rows)) {
			return '';
		}

		// Surface actionable rows first: shortfalls, then still-outstanding.
		usort($rows, function ($a, $b) {
			$asf = (isset($a['shortfall']) && $a['shortfall'] > 0) ? 1 : 0;
			$bsf = (isset($b['shortfall']) && $b['shortfall'] > 0) ? 1 : 0;
			if ($asf !== $bsf) {
				return $bsf - $asf;
			}
			$aout = (isset($a['outstanding']) && $a['outstanding'] > 0) ? 1 : 0;
			$bout = (isset($b['outstanding']) && $b['outstanding'] > 0) ? 1 : 0;
			return $bout - $aout;
		});

		$bulkpo = (function_exists('isModEnabled') && isModEnabled('bulkpo'));

		$out  = '<div class="serialtracker-panel">';
		$out .= '<div class="serialtracker-tablewrap"><table class="serialtracker-deliverables">';
		$out .= '<thead><tr>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('SerialtrackerColProduct')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColOrdered')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColShipped')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColOutstanding')).'</th>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('SerialtrackerColSerials')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColStock')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColIncoming')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColCommitted')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('SerialtrackerColShort')).'</th>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('SerialtrackerColAction')).'</th>';
		$out .= '</tr></thead><tbody>';

		foreach ($rows as $r) {
			$out .= $this->renderDeliverableRow($r, $bulkpo);
		}

		$out .= '</tbody></table></div>';

		// Multi-select batch bar: group the checked purchased-short lines into one
		// pre-seeded Bulk PO. Only when the bulkpo module is available.
		if ($bulkpo) {
			$wiz = dol_buildpath('/bulkpo/bulkpo_wizard.php', 1);
			$out .= '<div id="serialtracker-pobar" class="serialtracker-pobar" style="display:none;">';
			$out .= '<span class="serialtracker-pobar-hint">'.dol_escape_htmltag($langs->trans('SerialtrackerPoSelectHint')).'</span> ';
			$out .= '<a href="#" id="serialtracker-po-btn" class="serialtracker-act serialtracker-act-po" data-url="'.dol_escape_htmltag($wiz).'">'
				.dol_escape_htmltag($langs->trans('SerialtrackerCreatePoSelected')).' (<span id="serialtracker-po-count">0</span>)</a>';
			$out .= '</div>';
			$out .= $this->poBatchScript();
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 *  Inline JS for the multi-select → one-PO batch bar. Vanilla, no deps.
	 *  Collects checked purchased-short lines and deep-links to the Bulk PO wizard
	 *  pre-seeded with base64(JSON [{id,qty}]).
	 *
	 *  @return string
	 */
	private function poBatchScript()
	{
		return "<script>(function(){\n"
			." var checks=[].slice.call(document.querySelectorAll('.serialtracker-po-check'));\n"
			." var bar=document.getElementById('serialtracker-pobar');\n"
			." var btn=document.getElementById('serialtracker-po-btn');\n"
			." var cnt=document.getElementById('serialtracker-po-count');\n"
			." if(!bar||!btn||!cnt){return;}\n"
			." function upd(){var n=0;for(var i=0;i<checks.length;i++){if(checks[i].checked)n++;}cnt.textContent=n;bar.style.display=n>0?'':'none';}\n"
			." for(var i=0;i<checks.length;i++){checks[i].addEventListener('change',upd);}\n"
			." btn.addEventListener('click',function(e){e.preventDefault();var sel=[];for(var i=0;i<checks.length;i++){if(checks[i].checked){sel.push({id:parseInt(checks[i].getAttribute('data-pid'),10),qty:parseFloat(checks[i].getAttribute('data-qty'))});}}if(!sel.length){return;}var seed=btoa(JSON.stringify(sel));window.location=btn.getAttribute('data-url')+'?seed='+encodeURIComponent(seed);});\n"
			." upd();\n"
			."})();</script>\n";
	}

	/**
	 *  One deliverables table row.
	 *
	 *  @param  array  $r
	 *  @return string
	 */
	private function renderDeliverableRow($r, $bulkpo = false)
	{
		global $langs;

		$short   = isset($r['shortfall']) ? (float) $r['shortfall'] : 0;
		$rowCls  = ($short > 0) ? ' class="serialtracker-short"' : '';

		$out  = '<tr'.$rowCls.'>';
		$out .= '<td>'.dol_escape_htmltag($r['product']);
		if (!empty($r['order_ref'])) {
			// One-click breadcrumb to the sales order this line belongs to.
			if (!empty($r['commande_id'])) {
				$ou = DOL_URL_ROOT.'/commande/card.php?id='.((int) $r['commande_id']);
				$out .= '<a class="serialtracker-rowmeta serialtracker-orderlink" href="'.dol_escape_htmltag($ou).'" title="'
					.dol_escape_htmltag($langs->trans('SerialtrackerOpenOrder')).'">'.dol_escape_htmltag($r['order_ref']).' &rsaquo;</a>';
			} else {
				$out .= '<span class="serialtracker-rowmeta">'.dol_escape_htmltag($r['order_ref']).'</span>';
			}
		}
		$out .= '</td>';
		$out .= '<td class="c">'.$this->fmt($r['ordered']).'</td>';
		$out .= '<td class="c">'.$this->fmt($r['shipped']).'</td>';
		$out .= '<td class="c">'.($r['outstanding'] > 0 ? '<b>'.$this->fmt($r['outstanding']).'</b>' : '0').'</td>';

		// Serials cell.
		$out .= '<td>';
		if (!empty($r['serials'])) {
			foreach ($r['serials'] as $srl) {
				$lotId  = (int) $srl['lot_id'];
				$serial = dol_escape_htmltag($srl['serial']);
				if ($lotId > 0) {
					$u = dol_buildpath('/serialtracker/serial_card.php', 1).'?id='.$lotId;
					$out .= '<a class="serialtracker-chip" href="'.dol_escape_htmltag($u).'">'.$serial.'</a> ';
				} else {
					$out .= '<span class="serialtracker-chip">'.$serial.'</span> ';
				}
			}
		}
		$out .= '</td>';

		$out .= '<td class="c">'.$this->fmt($r['onhand']).'</td>';
		$out .= '<td class="c">'.$this->fmt($r['incoming']).'</td>';
		$out .= '<td class="c">'.$this->fmt($r['committed']).'</td>';
		$out .= '<td class="c">'.($short > 0 ? '<b class="serialtracker-shortnum">'.$this->fmt($short).'</b>' : '0').'</td>';

		// Action cell.
		$out .= '<td>';
		if ($short > 0) {
			if (!empty($r['manufacturable']) && !empty($r['bom_id'])) {
				$u = DOL_URL_ROOT.'/mrp/mo_card.php?action=create&fk_bom='.((int) $r['bom_id']).'&qty='.rawurlencode($this->fmt($short));
				$out .= '<a class="serialtracker-act serialtracker-act-mo" href="'.dol_escape_htmltag($u).'">'
					.dol_escape_htmltag($langs->trans('SerialtrackerCreateMO', $this->fmt($short))).'</a>';
			} else {
				$src = isset($r['source']) ? $r['source'] : null;
				$ch  = ($src && !empty($src['cheapest'])) ? $src['cheapest'] : null;
				$vid = $ch ? (int) $ch['vendor_id'] : 0;

				// Checkbox groups this purchased-short line into a multi-line PO;
				// the link beside it still does a single-item reorder (cheapest vendor).
				if ($bulkpo) {
					$out .= '<label class="serialtracker-po-pick" title="'.dol_escape_htmltag($langs->trans('SerialtrackerPoSelectHint')).'">'
						.'<input type="checkbox" class="serialtracker-po-check" data-pid="'.((int) $r['product_id']).'" data-qty="'.dol_escape_htmltag($this->fmt($short)).'" data-vendor="'.$vid.'"></label> ';
				}
				$u = $this->reorderUrl((int) $r['product_id'], $short, $vid);
				$out .= '<a class="serialtracker-act serialtracker-act-po" href="'.dol_escape_htmltag($u).'">'
					.dol_escape_htmltag($langs->trans('SerialtrackerReorder', $this->fmt($short))).'</a>';

				// Sourcing sublines: best price (seeds cheapest) + optional faster vendor.
				if ($ch) {
					$bits = array($ch['vendor']);
					if ($ch['unit'] > 0) {
						$bits[] = price($ch['unit']);
					}
					if ($ch['lead'] > 0) {
						$bits[] = $this->fmt($ch['lead']).'d';
					}
					$out .= '<span class="serialtracker-src">'.dol_escape_htmltag($langs->trans('SerialtrackerSrcBest'))
						.': '.dol_escape_htmltag(implode(' · ', $bits)).'</span>';

					if (!empty($src['fastest'])) {
						$f  = $src['fastest'];
						$fu = $this->reorderUrl((int) $r['product_id'], $short, (int) $f['vendor_id']);
						$fbits = array($f['vendor']);
						if ($f['lead'] > 0) {
							$fbits[] = $this->fmt($f['lead']).'d';
						}
						$out .= '<a class="serialtracker-src serialtracker-src-fast" href="'.dol_escape_htmltag($fu).'">'
							.dol_escape_htmltag($langs->trans('SerialtrackerSrcFaster')).': '
							.dol_escape_htmltag(implode(' · ', $fbits)).' &rsaquo;</a>';
					}
				} else {
					$out .= '<span class="serialtracker-src serialtracker-src-none">'
						.dol_escape_htmltag($langs->trans('SerialtrackerSrcNoVendor')).'</span>';
				}
			}
		} else {
			$out .= '<span class="serialtracker-ok" title="'.dol_escape_htmltag($langs->trans('SerialtrackerCovered')).'">&#10003;</span>';
		}
		$out .= '</td>';

		$out .= '</tr>';
		return $out;
	}

	/**
	 *  Build the "Reorder" target for a short purchased product.
	 *
	 *  Prefers the Bulk PO wizard (numero-independent, resolved by dol_buildpath)
	 *  pre-seeded with the shortfall product+qty when that module is installed;
	 *  otherwise falls back to the product's supplier tab as a stopgap.
	 *
	 *  @param  int    $productId
	 *  @param  float  $qty
	 *  @return string
	 */
	private function reorderUrl($productId, $qty, $vendorId = 0)
	{
		if (function_exists('isModEnabled') && isModEnabled('bulkpo')) {
			$seed = base64_encode(json_encode(array(array('id' => (int) $productId, 'qty' => (float) $qty))));
			$u = dol_buildpath('/bulkpo/bulkpo_wizard.php', 1).'?seed='.rawurlencode($seed);
			if ((int) $vendorId > 0) {
				$u .= '&socid='.((int) $vendorId); // pre-select the chosen vendor in the seed
			}
			return $u;
		}
		return DOL_URL_ROOT.'/product/fournisseurs.php?id='.((int) $productId);
	}

	/**
	 *  Format a quantity: drop trailing zeros / decimals when whole.
	 *
	 *  @param  mixed  $n
	 *  @return string
	 */
	private function fmt($n)
	{
		$n = (float) $n;
		if ($n == (int) $n) {
			return (string) (int) $n;
		}
		return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
	}

	// -------------------------------------------------------------------------
	// Shared stepper
	// -------------------------------------------------------------------------

	/**
	 *  Render a horizontal stepper with subtitle + tooltip + to-do phrasing.
	 *
	 *  @param  array  $steps
	 *  @return string
	 */
	public function renderTrack($steps)
	{
		if (empty($steps) || !is_array($steps)) {
			return '';
		}
		$out = '<div class="serialtracker-track" role="list">';
		$prevState = null;
		foreach ($steps as $idx => $step) {
			$out .= $this->renderStep($step, ($idx > 0), $prevState);
			$prevState = isset($step['state']) ? $step['state'] : null;
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 *  Render a single step: connector + circle + label + subtitle, with tooltip.
	 *
	 *  @param  array   $step
	 *  @param  bool    $withConnector
	 *  @param  string  $prevState
	 *  @return string
	 */
	private function renderStep($step, $withConnector, $prevState = null)
	{
		$state = isset($step['state']) ? $step['state'] : 'pending';
		$stateClass = 'serialtracker-'.preg_replace('/[^a-z]/', '', $state);

		$done = array('complete', 'current', 'ended');
		$connectorClass = (in_array($prevState, $done, true) && in_array($state, $done, true))
			? ' serialtracker-connector-complete' : '';

		$tooltip = $this->buildTooltip($step);

		// Open steps read as the action still needed (to-do phrasing).
		$isOpen = in_array($state, array('current', 'pending'), true);
		$label  = ($isOpen && !empty($step['label_todo'])) ? $step['label_todo'] : (isset($step['label']) ? $step['label'] : '');

		$out = '<div class="serialtracker-step '.$stateClass.'" role="listitem"'
			.($tooltip !== '' ? ' title="'.dol_escape_htmltag($tooltip).'"' : '').'>';
		if ($withConnector) {
			$out .= '<span class="serialtracker-connector'.$connectorClass.'" aria-hidden="true"></span>';
		}
		$out .= '<span class="serialtracker-circle" aria-hidden="true">'.$this->glyph($state).'</span>';
		$out .= '<span class="serialtracker-label">'.dol_escape_htmltag($label);
		if (!empty($step['ref'])) {
			$out .= '<span class="serialtracker-sub">'.dol_escape_htmltag($step['ref']).'</span>';
		}
		$out .= '</span>';
		$out .= '</div>';
		return $out;
	}

	/**
	 *  Build the tooltip: ref — status — date, plus a "what's needed" hint on open steps.
	 *
	 *  @param  array  $step
	 *  @return string
	 */
	private function buildTooltip($step)
	{
		global $langs;

		$parts = array();
		if (!empty($step['ref']))          { $parts[] = $step['ref']; }
		if (!empty($step['status_label'])) { $parts[] = $step['status_label']; }
		if (!empty($step['date']))         { $parts[] = dol_print_date($step['date'], 'day'); }

		$isOpen = in_array(isset($step['state']) ? $step['state'] : '', array('current', 'pending'), true);
		if ($isOpen && !empty($step['key']) && isset(self::$hintKeys[$step['key']])) {
			$key = self::$hintKeys[$step['key']];
			$translated = $langs->trans($key);
			if ($translated !== $key) {
				$parts[] = $translated;
			}
		}
		return implode(' — ', $parts);
	}

	/**
	 *  Glyph inside a circle for a state.
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
