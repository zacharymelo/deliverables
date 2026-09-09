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
 *  \file       serial_card.php
 *  \ingroup    deliverables
 *  \brief      Per-serial detail: back-half journey + MO/production trace by lot.
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

dol_include_once('/deliverables/class/deliverablesresolver.class.php');
dol_include_once('/deliverables/class/deliverablesrenderer.class.php');

$langs->loadLangs(array('deliverables@deliverables'));

$id = GETPOSTINT('id'); // product_lot rowid

if (!isModEnabled('deliverables')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('deliverables', 'read')) {
	accessforbidden();
}

$resolver = new DeliverablesResolver($db);
$serial   = $resolver->resolveSerial($id);

if (empty($serial)) {
	accessforbidden('Serial not found');
}

$title = $langs->trans('DeliverablesSerialTitle').' - '.$serial['serial'];
llxHeader('', $title);

$cssfile = dol_buildpath('/deliverables/css/deliverables.css', 0);
$cssurl  = dol_buildpath('/deliverables/css/deliverables.css', 1).'?v='.(is_file($cssfile) ? filemtime($cssfile) : '1');
print '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($cssurl).'">'."\n";

// Header line: serial ref + product.
$head = dol_escape_htmltag($serial['serial']);
if (!empty($serial['product'])) {
	$head .= ' <span class="opacitymedium">'.dol_escape_htmltag($serial['product']).'</span>';
}
print load_fiche_titre($langs->trans('DeliverablesSerialTitle').' '.$head, '', 'barcode');

$renderer = new DeliverablesRenderer();
print $renderer->renderSerialDetail($serial);

llxFooter();
$db->close();
