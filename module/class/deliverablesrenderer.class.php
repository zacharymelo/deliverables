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
 *  \file       class/deliverablesrenderer.class.php
 *  \ingroup    deliverables
 *  \brief      Pure HTML renderer for the fulfillment journey. No DB access.
 *
 *  Mirrors the Order Progress tracker's subtitle + tooltip richness: each step
 *  carries a subtitle (ref/count under the label) and a native title tooltip
 *  built from ref / status / date, plus a "what's still needed" hint and to-do
 *  phrasing on open steps.
 */
class DeliverablesRenderer
{
	/** @var bool  Whether the data shown is sample/mock */
	public $isSample = false;

	/** @var array<string,string>  Hint lang key per step key — shown for open steps */
	private static $hintKeys = array(
		'ORD'  => 'DeliverablesHintOrdered',
		'PICK' => 'DeliverablesHintPicking',
		'SHIP' => 'DeliverablesHintShipped',
		'MFG'  => 'DeliverablesHintManufactured',
		'WARR' => 'DeliverablesHintWarranty',
		'EOL'  => 'DeliverablesHintSupportEnded',
	);

	// -------------------------------------------------------------------------
	// Order-line list (project / customer tabs)
	// -------------------------------------------------------------------------

	/**
	 *  Render the list of order lines, each with its front-half journey and the
	 *  serials assigned to it.
	 *
	 *  @param  array  $lines  From DeliverablesResolver::resolveForProject()/ForThirdparty()
	 *  @return string          HTML (empty if nothing)
	 */
	public function renderLines($lines)
	{
		global $langs;
		$langs->loadLangs(array('deliverables@deliverables'));

		if (empty($lines) || !is_array($lines)) {
			return '';
		}

		$out  = '<div class="deliverables-panel">';
		if ($this->isSample) {
			$out .= '<div class="deliverables-head"><span class="deliverables-sample-tag">'
				.dol_escape_htmltag($langs->trans('DeliverablesSampleTag')).'</span></div>';
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

		$out  = '<div class="deliverables-line deliverables-otherline">';
		$out .= '<div class="deliverables-ident"><span class="deliverables-otherlabel">'
			.dol_escape_htmltag($langs->trans('DeliverablesOtherLines', $n)).'</span></div>';
		$out .= '<div class="deliverables-linebody"><span class="deliverables-othermeta">'
			.dol_escape_htmltag($langs->trans('DeliverablesOtherShipped', $shipped, $qty)).'</span></div>';
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

		$out  = '<div class="deliverables-line">';

		$out .= '<div class="deliverables-ident">';
		$out .= '<span class="deliverables-product">'.dol_escape_htmltag($product).'</span>';
		$meta = array();
		if ($orderR !== '') { $meta[] = $orderR; }
		$meta[] = $langs->trans('DeliverablesQtyUnits', $qty);
		$out .= '<span class="deliverables-linemeta">'.dol_escape_htmltag(implode(' · ', $meta)).'</span>';
		$out .= '</div>';

		$out .= '<div class="deliverables-linebody">';
		$out .= $this->renderTrack($steps);

		if (!empty($serials)) {
			$out .= '<div class="deliverables-chips">';
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

		$stageClass = 'deliverables-chip-'.preg_replace('/[^a-z]/', '', strtolower($stage));
		$title = $this->stageTitle($stage);
		$titleAttr = ($title !== '' ? ' title="'.dol_escape_htmltag($title).'"' : '');

		$inner = '<span class="deliverables-chip-dot" aria-hidden="true"></span>'.dol_escape_htmltag($serial);

		// Only link when we have a lot record to open; otherwise show a plain chip.
		if ($lotId > 0) {
			$url = DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.$lotId;
			return '<a class="deliverables-chip '.$stageClass.'" href="'.dol_escape_htmltag($url).'"'.$titleAttr.'>'.$inner.'</a>';
		}
		return '<span class="deliverables-chip '.$stageClass.'"'.$titleAttr.'>'.$inner.'</span>';
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
			case 'MFG':  return $langs->trans('DeliverablesStageManufactured');
			case 'SHIP': return $langs->trans('DeliverablesStageShipped');
			case 'WARR': return $langs->trans('DeliverablesStatusWarrantyActive');
			case 'EOL':  return $langs->trans('DeliverablesStatusSupportEnded');
			default:     return '';
		}
	}

	// -------------------------------------------------------------------------
	// Serial detail (rendered as a tab on the native product-lot card — lot_fulfillment.php)
	// -------------------------------------------------------------------------

	/**
	 *  Render one serial's back-half journey plus its production/link detail.
	 *
	 *  @param  array  $serial  From DeliverablesResolver::resolveSerial()
	 *  @return string
	 */
	public function renderSerialDetail($serial)
	{
		global $langs;
		$langs->loadLangs(array('deliverables@deliverables'));

		if (empty($serial) || !is_array($serial)) {
			return '';
		}

		$steps = isset($serial['steps']) && is_array($serial['steps']) ? $serial['steps'] : array();

		$out  = '<div class="deliverables-panel deliverables-detail">';
		$out .= '<div class="deliverables-detailtrack">'.$this->renderTrack($steps).'</div>';

		// Linked records — every native object this serial references, clickable.
		$g = function ($k) use ($serial) { return isset($serial[$k]) ? $serial[$k] : null; };
		$links = array();
		if ($g('lot_id')) {
			$links[] = array($langs->trans('DeliverablesLinkLot'), DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.((int) $g('lot_id')), $g('serial'), 'barcode');
		}
		if ($g('product_id')) {
			$links[] = array($langs->trans('DeliverablesFieldProduct'), DOL_URL_ROOT.'/product/card.php?id='.((int) $g('product_id')), $g('product'), 'product');
		}
		if ($g('mo_id')) {
			$links[] = array($langs->trans('DeliverablesLinkMO'), DOL_URL_ROOT.'/mrp/mo_card.php?id='.((int) $g('mo_id')), $g('mo_ref'), 'mrp');
		}
		if ($g('commande_id')) {
			$links[] = array($langs->trans('DeliverablesFieldOrder'), DOL_URL_ROOT.'/commande/card.php?id='.((int) $g('commande_id')), $g('order_ref'), 'order');
		}
		if ($g('expedition_id')) {
			$links[] = array($langs->trans('DeliverablesLinkShipment'), DOL_URL_ROOT.'/expedition/card.php?id='.((int) $g('expedition_id')), $g('ship_ref'), 'dolly');
		}
		if ($g('fk_projet')) {
			$links[] = array($langs->trans('DeliverablesFieldProject'), DOL_URL_ROOT.'/projet/card.php?id='.((int) $g('fk_projet')), $g('project'), 'project');
		}
		if ($g('fk_soc')) {
			$links[] = array($langs->trans('DeliverablesFieldThirdparty'), DOL_URL_ROOT.'/societe/card.php?socid='.((int) $g('fk_soc')), $g('thirdparty'), 'company');
		}

		if (!empty($links)) {
			$out .= '<div class="deliverables-detailhead">'.dol_escape_htmltag($langs->trans('DeliverablesLinkedRecords')).'</div>';
			$out .= '<table class="deliverables-detailtable">';
			foreach ($links as $lk) {
				list($label, $url, $text, $picto) = $lk;
				$out .= '<tr><td class="deliverables-dt-key">'.dol_escape_htmltag($label).'</td><td>'
					.'<a href="'.dol_escape_htmltag($url).'">'.img_picto('', $picto, 'class="pictofixedwidth"')
					.dol_escape_htmltag($text !== null && $text !== '' ? $text : '#'.$url).'</a></td></tr>';
			}
			$out .= '</table>';
		}

		// Native lot / MRP facts.
		$facts = array();
		if ($g('manufacturing_date')) { $facts[$langs->trans('DeliverablesFieldManufactured')] = dol_print_date((int) $g('manufacturing_date'), 'day'); }
		if ($g('eol_date'))           { $facts[$langs->trans('DeliverablesFieldEol')]          = dol_print_date((int) $g('eol_date'), 'day'); }

		if (!empty($facts)) {
			$out .= '<div class="deliverables-detailhead">'.dol_escape_htmltag($langs->trans('DeliverablesDetails')).'</div>';
			$out .= '<table class="deliverables-detailtable">';
			foreach ($facts as $k => $v) {
				$out .= '<tr><td class="deliverables-dt-key">'.dol_escape_htmltag($k).'</td><td>'.dol_escape_htmltag($v).'</td></tr>';
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
	 *  @param  array   $sum     From DeliverablesResolver::summaryForProject()
	 *  @param  string  $tabUrl  URL of the project fulfillment tab
	 *  @return string
	 */
	public function renderSummaryLine($sum, $tabUrl)
	{
		global $langs;
		$langs->loadLangs(array('deliverables@deliverables'));

		if (empty($sum) || (int) $sum['lines'] === 0) {
			return '';
		}

		$parts = array();
		$parts[] = $langs->trans('DeliverablesSummaryUnits', $this->fmt($sum['units']), (int) $sum['lines']);
		$parts[] = $langs->trans('DeliverablesSummaryShipped', $this->fmt($sum['shipped']));
		if ((float) $sum['outstanding'] > 0) { $parts[] = $langs->trans('DeliverablesSummaryOutstanding', $this->fmt($sum['outstanding'])); }
		if ((float) $sum['short'] > 0)       { $parts[] = $langs->trans('DeliverablesSummaryShort', $this->fmt($sum['short'])); }

		$out  = '<div class="deliverables-summary">';
		$out .= '<span class="deliverables-summary-label">'.dol_escape_htmltag($langs->trans('DeliverablesTitle')).':</span> ';
		$out .= dol_escape_htmltag(implode(' · ', $parts));
		$out .= ' <a class="deliverables-summary-link" href="'.dol_escape_htmltag($tabUrl).'">'
			.dol_escape_htmltag($langs->trans('DeliverablesViewAll')).' &rarr;</a>';
		if ($this->isSample) {
			$out .= ' <span class="deliverables-sample-tag">'.dol_escape_htmltag($langs->trans('DeliverablesSampleTag')).'</span>';
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
	 *  @param  array  $rows  From DeliverablesResolver::resolveDeliverables()
	 *  @return string
	 */
	public function renderDeliverables($rows)
	{
		global $langs;
		$langs->loadLangs(array('deliverables@deliverables'));

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

		$out  = '<div class="deliverables-panel">';
		$out .= '<div class="deliverables-tablewrap"><table class="deliverables-deliverables">';
		$out .= '<thead><tr>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('DeliverablesColProduct')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColOrdered')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColShipped')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColOutstanding')).'</th>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('DeliverablesColSerials')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColStock')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColIncoming')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColCommitted')).'</th>';
		$out .= '<th class="c">'.dol_escape_htmltag($langs->trans('DeliverablesColShort')).'</th>';
		$out .= '<th>'.dol_escape_htmltag($langs->trans('DeliverablesColAction')).'</th>';
		$out .= '</tr></thead><tbody>';

		foreach ($rows as $r) {
			$out .= $this->renderDeliverableRow($r, $bulkpo);
		}

		$out .= '</tbody></table></div>';

		// Multi-select batch bar: group the checked purchased-short lines into one
		// pre-seeded Bulk PO. Only when the bulkpo module is available.
		if ($bulkpo) {
			$wiz = dol_buildpath('/bulkpo/bulkpo_wizard.php', 1);
			$out .= '<div id="deliverables-pobar" class="deliverables-pobar" style="display:none;">';
			$out .= '<span class="deliverables-pobar-hint">'.dol_escape_htmltag($langs->trans('DeliverablesPoSelectHint')).'</span> ';
			$out .= '<a href="#" id="deliverables-po-btn" class="deliverables-act deliverables-act-po" data-url="'.dol_escape_htmltag($wiz).'">'
				.dol_escape_htmltag($langs->trans('DeliverablesCreatePoSelected')).' (<span id="deliverables-po-count">0</span>)</a>';
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
			." var checks=[].slice.call(document.querySelectorAll('.deliverables-po-check'));\n"
			." var bar=document.getElementById('deliverables-pobar');\n"
			." var btn=document.getElementById('deliverables-po-btn');\n"
			." var cnt=document.getElementById('deliverables-po-count');\n"
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
		$rowCls  = ($short > 0) ? ' class="deliverables-short"' : '';

		$out  = '<tr'.$rowCls.'>';
		$out .= '<td>'.dol_escape_htmltag($r['product']);
		// Icon-only link to the product card (kept off the name text so it isn't
		// misclicked against the order-ref link on the line below). Real products only.
		if (!empty($r['product_id'])) {
			$pu = DOL_URL_ROOT.'/product/card.php?id='.((int) $r['product_id']);
			$out .= ' <a class="deliverables-prodlink" href="'.dol_escape_htmltag($pu).'" title="'
				.dol_escape_htmltag($langs->trans('DeliverablesOpenProduct')).'">'
				.img_picto('', 'product', 'class="pictofixedwidth"').'</a>';
		}
		if (!empty($r['order_ref'])) {
			// One-click breadcrumb to the sales order this line belongs to.
			if (!empty($r['commande_id'])) {
				$ou = DOL_URL_ROOT.'/commande/card.php?id='.((int) $r['commande_id']);
				$out .= '<a class="deliverables-rowmeta deliverables-orderlink" href="'.dol_escape_htmltag($ou).'" title="'
					.dol_escape_htmltag($langs->trans('DeliverablesOpenOrder')).'">'.dol_escape_htmltag($r['order_ref']).' &rsaquo;</a>';
			} else {
				$out .= '<span class="deliverables-rowmeta">'.dol_escape_htmltag($r['order_ref']).'</span>';
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
					$u = DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.$lotId;
					$out .= '<a class="deliverables-chip" href="'.dol_escape_htmltag($u).'">'.$serial.'</a> ';
				} else {
					$out .= '<span class="deliverables-chip">'.$serial.'</span> ';
				}
			}
		}
		$out .= '</td>';

		$out .= '<td class="c">'.$this->fmt($r['onhand']).'</td>';
		$out .= '<td class="c">'.$this->fmt($r['incoming']).'</td>';
		$out .= '<td class="c">'.$this->fmt($r['committed']).'</td>';
		$out .= '<td class="c">'.($short > 0 ? '<b class="deliverables-shortnum">'.$this->fmt($short).'</b>' : '0').'</td>';

		// Action cell.
		$out .= '<td>';
		if ($short > 0) {
			if (!empty($r['manufacturable']) && !empty($r['bom_id'])) {
				$u = DOL_URL_ROOT.'/mrp/mo_card.php?action=create&fk_bom='.((int) $r['bom_id']).'&qty='.rawurlencode($this->fmt($short));
				$out .= '<a class="deliverables-act deliverables-act-mo" href="'.dol_escape_htmltag($u).'">'
					.dol_escape_htmltag($langs->trans('DeliverablesCreateMO', $this->fmt($short))).'</a>';
			} else {
				$src = isset($r['source']) ? $r['source'] : null;
				$ch  = ($src && !empty($src['cheapest'])) ? $src['cheapest'] : null;
				$vid = $ch ? (int) $ch['vendor_id'] : 0;

				// Checkbox groups this purchased-short line into a multi-line PO;
				// the link beside it still does a single-item reorder (cheapest vendor).
				if ($bulkpo) {
					$out .= '<label class="deliverables-po-pick" title="'.dol_escape_htmltag($langs->trans('DeliverablesPoSelectHint')).'">'
						.'<input type="checkbox" class="deliverables-po-check" data-pid="'.((int) $r['product_id']).'" data-qty="'.dol_escape_htmltag($this->fmt($short)).'" data-vendor="'.$vid.'"></label> ';
				}
				$u = $this->reorderUrl((int) $r['product_id'], $short, $vid);
				$out .= '<a class="deliverables-act deliverables-act-po" href="'.dol_escape_htmltag($u).'">'
					.dol_escape_htmltag($langs->trans('DeliverablesReorder', $this->fmt($short))).'</a>';

				// Sourcing sublines: best price (seeds cheapest) + optional faster vendor.
				if ($ch) {
					$bits = array($ch['vendor']);
					if ($ch['unit'] > 0) {
						$bits[] = price($ch['unit']);
					}
					if ($ch['lead'] > 0) {
						$bits[] = $this->fmt($ch['lead']).'d';
					}
					$out .= '<span class="deliverables-src">'.dol_escape_htmltag($langs->trans('DeliverablesSrcBest'))
						.': '.dol_escape_htmltag(implode(' · ', $bits)).'</span>';

					if (!empty($src['fastest'])) {
						$f  = $src['fastest'];
						$fu = $this->reorderUrl((int) $r['product_id'], $short, (int) $f['vendor_id']);
						$fbits = array($f['vendor']);
						if ($f['lead'] > 0) {
							$fbits[] = $this->fmt($f['lead']).'d';
						}
						$out .= '<a class="deliverables-src deliverables-src-fast" href="'.dol_escape_htmltag($fu).'">'
							.dol_escape_htmltag($langs->trans('DeliverablesSrcFaster')).': '
							.dol_escape_htmltag(implode(' · ', $fbits)).' &rsaquo;</a>';
					}
				} else {
					$out .= '<span class="deliverables-src deliverables-src-none">'
						.dol_escape_htmltag($langs->trans('DeliverablesSrcNoVendor')).'</span>';
				}
			}
		} else {
			$out .= '<span class="deliverables-ok" title="'.dol_escape_htmltag($langs->trans('DeliverablesCovered')).'">&#10003;</span>';
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
		$out = '<div class="deliverables-track" role="list">';
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
		$stateClass = 'deliverables-'.preg_replace('/[^a-z]/', '', $state);

		$done = array('complete', 'current', 'ended');
		$connectorClass = (in_array($prevState, $done, true) && in_array($state, $done, true))
			? ' deliverables-connector-complete' : '';

		$tooltip = $this->buildTooltip($step);

		// Open steps read as the action still needed (to-do phrasing).
		$isOpen = in_array($state, array('current', 'pending'), true);
		$label  = ($isOpen && !empty($step['label_todo'])) ? $step['label_todo'] : (isset($step['label']) ? $step['label'] : '');

		$out = '<div class="deliverables-step '.$stateClass.'" role="listitem"'
			.($tooltip !== '' ? ' title="'.dol_escape_htmltag($tooltip).'"' : '').'>';
		if ($withConnector) {
			$out .= '<span class="deliverables-connector'.$connectorClass.'" aria-hidden="true"></span>';
		}
		$out .= '<span class="deliverables-circle" aria-hidden="true">'.$this->glyph($state).'</span>';
		$out .= '<span class="deliverables-label">'.dol_escape_htmltag($label);
		if (!empty($step['ref'])) {
			$out .= '<span class="deliverables-sub">'.dol_escape_htmltag($step['ref']).'</span>';
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
				return '<span class="deliverables-glyph">&#10003;</span>';
			case 'ended':
				return '<span class="deliverables-glyph">&#9632;</span>';
			default:
				return '<span class="deliverables-glyph"></span>';
		}
	}
}
