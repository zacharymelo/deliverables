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
 *  \file       lot_fulfillment.php
 *  \ingroup    deliverables
 *  \brief      Tab on the NATIVE product-lot card: this serial's fulfillment
 *              synthesis (journey + linked order/shipment/MO/customer records).
 *              Augments the native lot card rather than rebuilding it.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = realpath(__FILE__);
$i = strlen($tmp) - 1;
while ($i > 0 && !$res) {
	if (file_exists(substr($tmp, 0, $i)."/main.inc.php")) {
		$res = @include substr($tmp, 0, $i)."/main.inc.php";
		break;
	}
	$i--;
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
dol_include_once('/deliverables/class/deliverablesresolver.class.php');
dol_include_once('/deliverables/class/deliverablesrenderer.class.php');

$langs->loadLangs(array('products', 'deliverables@deliverables'));

$id = GETPOSTINT('id');

if (!isModEnabled('deliverables')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('deliverables', 'read')) {
	accessforbidden();
}

$object = new Productlot($db);
if ($id > 0) {
	$object->fetch($id);
}
if (empty($object->id)) {
	accessforbidden('Lot not found');
}

$title = $langs->trans('DeliverablesTabTitle').' - '.$object->batch;
llxHeader('', $title);

$head = productlot_prepare_head($object);
print dol_get_fiche_head($head, 'deliverables', $langs->trans('Batch'), -1, (empty($object->picto) ? 'lot' : $object->picto));

$linkback = '<a href="'.DOL_URL_ROOT.'/product/stock/productlot_list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'batch', '');

print '<div class="fichecenter">';

$cssfile = dol_buildpath('/deliverables/css/deliverables.css', 0);
$cssurl  = dol_buildpath('/deliverables/css/deliverables.css', 1).'?v='.(is_file($cssfile) ? filemtime($cssfile) : '1');
print '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($cssurl).'">'."\n";

$resolver = new DeliverablesResolver($db);
$serial   = $resolver->resolveSerial($object->id);

$renderer = new DeliverablesRenderer();

if (empty($serial)) {
	print '<div class="opacitymedium">'.$langs->trans('DeliverablesNoSerial').'</div>';
} else {
	print $renderer->renderSerialDetail($serial);
}

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
