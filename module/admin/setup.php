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
 *  \file       admin/setup.php
 *  \ingroup    serialtracker
 *  \brief      Admin settings page: sample mode + debug toggle.
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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('admin', 'serialtracker@serialtracker'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$form   = new Form($db);

// -------------------------------------------------------------------------
// Save
// -------------------------------------------------------------------------
if ($action == 'update') {
	// CSRF is validated automatically by main.inc.php (the hidden 'token' field
	// below is sufficient) — no manual verification call is needed here.
	dolibarr_set_const($db, 'SERIALTRACKER_DEBUG',  GETPOSTINT('SERIALTRACKER_DEBUG')  ? '1' : '0', 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SERIALTRACKER_ATP_DEMAND_ALL', GETPOSTINT('SERIALTRACKER_ATP_DEMAND_ALL') ? '1' : '0', 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SERIALTRACKER_ATP_WINDOW_DAYS', (string) GETPOSTINT('SERIALTRACKER_ATP_WINDOW_DAYS'), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// -------------------------------------------------------------------------
// View
// -------------------------------------------------------------------------
llxHeader('', $langs->trans('SerialtrackerSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('SerialtrackerSetup'), $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('SerialtrackerSetupIntro').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameter').'</td>';
print '<td class="center" width="120">'.$langs->trans('Value').'</td>';
print '</tr>';

// Debug mode
print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans('SerialtrackerDebugMode').'</strong><br>';
print '<span class="opacitymedium">'.$langs->trans('SerialtrackerDebugModeHelp').'</span></td>';
print '<td class="center">'.$form->selectyesno('SERIALTRACKER_DEBUG', getDolGlobalInt('SERIALTRACKER_DEBUG', 0), 1).'</td>';
print '</tr>';

// ATP: include draft orders in demand.
print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans('SerialtrackerAtpDemandAll').'</strong><br>';
print '<span class="opacitymedium">'.$langs->trans('SerialtrackerAtpDemandAllHelp').'</span></td>';
print '<td class="center">'.$form->selectyesno('SERIALTRACKER_ATP_DEMAND_ALL', getDolGlobalInt('SERIALTRACKER_ATP_DEMAND_ALL', 0), 1).'</td>';
print '</tr>';

// ATP: demand time window (days).
print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans('SerialtrackerAtpWindow').'</strong><br>';
print '<span class="opacitymedium">'.$langs->trans('SerialtrackerAtpWindowHelp').'</span></td>';
print '<td class="center"><input type="number" min="0" name="SERIALTRACKER_ATP_WINDOW_DAYS" value="'.dol_escape_htmltag((string) getDolGlobalInt('SERIALTRACKER_ATP_WINDOW_DAYS', 180)).'" class="width75"></td>';
print '</tr>';

print '</table>';

print '<div class="center" style="margin-top:16px;">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

// When debug is on, surface the discovery endpoint link for admins.
if (getDolGlobalString('SERIALTRACKER_DEBUG')) {
	$dbgurl = dol_buildpath('/serialtracker/ajax/debug.php', 1).'?project_id=';
	print '<br><div class="info">'.$langs->trans('SerialtrackerDebugHint', dol_escape_htmltag($dbgurl.'N')).'</div>';
}

llxFooter();
$db->close();
