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
 *  \file       ajax/debug.php
 *  \ingroup    serialtracker
 *  \brief      Discovery endpoint. Admin-only, gated by SERIALTRACKER_DEBUG.
 *
 *  Purpose: map the REAL linkage from a project to its serials and lifecycle
 *  evidence BEFORE any of it is trusted for display. Every query is defensive —
 *  a missing column/table returns its lasterror instead of failing silently
 *  (the lesson from the leadtracker rowid/type saga).
 *
 *  Pass ?project_id=N to probe a specific project.
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

header('Content-Type: application/json; charset=utf-8');

if (!$user->admin || !getDolGlobalString('SERIALTRACKER_DEBUG')) {
	http_response_code(403);
	print json_encode(array('error' => 'Forbidden'));
	exit;
}

if (!isModEnabled('serialtracker')) {
	http_response_code(503);
	print json_encode(array('error' => 'Module not enabled'));
	exit;
}

$out = array();

/**
 *  Run a SELECT and capture rows, or the SQL error if it failed.
 *
 *  @param  DoliDB  $db
 *  @param  string  $sql
 *  @param  int     $limit  Max rows to return
 *  @return array            ['rows' => [...]] or ['error' => '...']
 */
function st_probe($db, $sql, $limit = 50)
{
	$r = $db->query($sql);
	if (!$r) {
		return array('error' => $db->lasterror(), 'sql' => $sql);
	}
	$rows = array();
	$n = 0;
	while (($row = $db->fetch_object($r)) && $n < $limit) {
		$rows[] = $row;
		$n++;
	}
	return array('rows' => $rows);
}

// --- Schema discovery: which tables in this install relate to serials? ---
$out['table_discovery'] = st_probe(
	$db,
	"SELECT table_name FROM information_schema.tables"
		." WHERE table_schema = DATABASE()"
		." AND (table_name LIKE '%lot%'"
		."   OR table_name LIKE '%serial%'"
		."   OR table_name LIKE '%warrant%'"
		."   OR table_name LIKE '%rma%'"
		."   OR table_name LIKE '%return%'"
		."   OR table_name LIKE '%mrp%'"
		."   OR table_name LIKE '%mo%'"
		."   OR table_name LIKE '%expedition%'"
		.")"
		." ORDER BY table_name",
	200
);

// --- product_lot: the serial identity spine ---
$out['product_lot_sample'] = st_probe($db, "SELECT * FROM ".MAIN_DB_PREFIX."product_lot ORDER BY rowid DESC LIMIT 5", 5);

// --- MRP / Manufacturing Orders ---
$out['mrp_mo_sample'] = st_probe($db, "SELECT * FROM ".MAIN_DB_PREFIX."mrp_mo ORDER BY rowid DESC LIMIT 3", 3);

$projectId = GETPOSTINT('project_id');
$out['project_id'] = $projectId;

if ($projectId > 0) {
	$pid = (int) $projectId;

	// Project's company (warranty links to third party).
	$out['project'] = st_probe($db, "SELECT rowid, ref, fk_soc, fk_statut FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".$pid, 1);

	// Candidate path A: shipments carrying fk_projet directly.
	$out['shipments_by_project'] = st_probe(
		$db,
		"SELECT rowid, ref, fk_soc, fk_projet FROM ".MAIN_DB_PREFIX."expedition WHERE fk_projet = ".$pid." ORDER BY rowid DESC",
		50
	);

	// Candidate path B: shipments linked to the project via element_element.
	$out['shipments_via_element_element'] = st_probe(
		$db,
		"SELECT ee.fk_source, ee.sourcetype, ee.fk_target, ee.targettype"
			." FROM ".MAIN_DB_PREFIX."element_element ee"
			." WHERE (ee.sourcetype = 'project' AND ee.fk_source = ".$pid." AND ee.targettype = 'shipping')"
			."  OR (ee.targettype = 'project' AND ee.fk_target = ".$pid." AND ee.sourcetype = 'shipping')",
		50
	);

	// Candidate path C: shipments via the project's orders (commande.fk_projet -> expedition.fk_origin).
	$out['shipments_via_orders'] = st_probe(
		$db,
		"SELECT e.rowid as expedition_id, e.ref as expedition_ref, c.rowid as commande_id, c.ref as commande_ref"
			." FROM ".MAIN_DB_PREFIX."commande c"
			." INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.fk_origin = 'commande' AND e.origin_id = c.rowid"
			." WHERE c.fk_projet = ".$pid,
		50
	);
}

print json_encode($out, JSON_PRETTY_PRINT);
exit;
