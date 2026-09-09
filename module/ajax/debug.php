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

// --- Sample rows (columns + data) from each discovered candidate table, so the
//     warranty / RMA / batch / lot / MO schemas reveal themselves without any
//     column guessing. Names come straight from information_schema (already
//     prefixed for this install). ---
$out['candidate_table_samples'] = array();
if (!empty($out['table_discovery']['rows'])) {
	foreach ($out['table_discovery']['rows'] as $trow) {
		$tname = isset($trow->table_name) ? $trow->table_name : (isset($trow->TABLE_NAME) ? $trow->TABLE_NAME : '');
		$tname = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tname);
		if ($tname === '') {
			continue;
		}
		// Only the tables likely to carry lifecycle evidence — keep the payload lean.
		if (!preg_match('/(warrant|rma|return|lot|batch|mrp|expeditiondet)/i', $tname)) {
			continue;
		}
		$out['candidate_table_samples'][$tname] = st_probe($db, "SELECT * FROM ".$tname." LIMIT 3", 3);
	}
}

$projectId = GETPOSTINT('project_id');
$out['project_id'] = $projectId;

if ($projectId > 0) {
	$pid = (int) $projectId;

	// Project's company (warranty links to the third party).
	$out['project'] = st_probe($db, "SELECT rowid, ref, fk_soc, fk_statut FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".$pid, 1);

	// FRONT-HALF ANCHOR: the project's sales-order lines (product + qty).
	$out['order_lines'] = st_probe(
		$db,
		"SELECT c.rowid as commande_id, c.ref as order_ref, c.fk_soc,"
			." cd.rowid as line_id, cd.fk_product, cd.qty,"
			." p.ref as product_ref, p.label as product_label"
			." FROM ".MAIN_DB_PREFIX."commande c"
			." INNER JOIN ".MAIN_DB_PREFIX."commandedet cd ON cd.fk_commande = c.rowid"
			." LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product"
			." WHERE c.fk_projet = ".$pid
			." ORDER BY c.rowid DESC, cd.rowid",
		100
	);

	// Shipments for the project (three candidate link paths).
	$out['shipments_by_project'] = st_probe(
		$db,
		"SELECT rowid, ref, fk_soc, fk_projet FROM ".MAIN_DB_PREFIX."expedition WHERE fk_projet = ".$pid." ORDER BY rowid DESC",
		50
	);
	$out['shipments_via_element_element'] = st_probe(
		$db,
		"SELECT ee.fk_source, ee.sourcetype, ee.fk_target, ee.targettype"
			." FROM ".MAIN_DB_PREFIX."element_element ee"
			." WHERE (ee.sourcetype = 'project' AND ee.fk_source = ".$pid." AND ee.targettype = 'shipping')"
			."  OR (ee.targettype = 'project' AND ee.fk_target = ".$pid." AND ee.sourcetype = 'shipping')",
		50
	);
	// THE KEY TIE (verified schema): shipment -> shipment line (-> order line) ->
	// batch/lot. Expedition attaches to the project directly via fk_projet; the
	// order line is expeditiondet.element_type='commande' + fk_elementdet.
	$out['shipment_batch_lots'] = st_probe(
		$db,
		"SELECT e.rowid as expedition_id, e.ref as ship_ref, e.fk_statut,"
			." ed.rowid as expeditiondet_id, ed.fk_elementdet as order_line_id, ed.fk_product, ed.qty,"
			." eb.batch, eb.qty as batch_qty, pl.rowid as lot_id"
			." FROM ".MAIN_DB_PREFIX."expedition e"
			." INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.fk_expedition = e.rowid AND ed.element_type = 'commande'"
			." LEFT JOIN ".MAIN_DB_PREFIX."expeditiondet_batch eb ON eb.fk_expeditiondet = ed.rowid"
			." LEFT JOIN ".MAIN_DB_PREFIX."product_lot pl ON pl.batch = eb.batch AND pl.fk_product = ed.fk_product"
			." WHERE e.fk_projet = ".$pid,
		200
	);
}

print json_encode($out, JSON_PRETTY_PRINT);
exit;
