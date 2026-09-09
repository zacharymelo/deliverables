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
 *  \file       project_fulfillment.php
 *  \ingroup    serialtracker
 *  \brief      Project tab: order-line fulfillment journeys + their serials.
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

require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/project.lib.php';
dol_include_once('/serialtracker/class/seriallifecycleresolver.class.php');
dol_include_once('/serialtracker/class/seriallifecyclerenderer.class.php');

$langs->loadLangs(array('projects', 'serialtracker@serialtracker'));

$id = GETPOSTINT('id');

if (!isModEnabled('serialtracker')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('serialtracker', 'read')) {
	accessforbidden();
}

$object = new Project($db);
if ($id > 0) {
	$object->fetch($id);
}
if (empty($object->id)) {
	accessforbidden('Project not found');
}
// Respect native project visibility.
restrictedArea($user, 'projet', $object->id, 'projet&project');

$title = $langs->trans('SerialtrackerTabTitle').' - '.$object->ref;
llxHeader('', $title);

$head = project_prepare_head($object);
print dol_get_fiche_head($head, 'serialtracker', $langs->trans('Project'), -1, ($object->public ? 'projectpub' : 'project'));

$linkback = '<a href="'.DOL_URL_ROOT.'/projet/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '');

print '<div class="fichecenter">';

$cssurl = dol_buildpath('/serialtracker/css/serialtracker.css', 1);
print '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($cssurl).'">'."\n";

$resolver = new SerialLifecycleResolver($db);
$rows     = $resolver->resolveDeliverables(array('fk_project' => $object->id));

$renderer = new SerialLifecycleRenderer();

if (empty($rows)) {
	print '<div class="opacitymedium">'.$langs->trans('SerialtrackerNoLines').'</div>';
} else {
	print $renderer->renderDeliverables($rows);
}

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
