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
 *  \file       core/modules/modSerialtracker.class.php
 *  \ingroup    serialtracker
 *  \brief      Module descriptor for Serial Tracker (UX prototype)
 *
 *  Serial Tracker is a prototype, installable ALONGSIDE Lead Tracker, that
 *  renders a per-serial lifecycle step-indicator (Manufactured -> Shipped ->
 *  Under Warranty -> Support Ended) as a list on the project card — one row per
 *  serial (product_lot). Built so staff can compare it against the sales funnel.
 *  Read-only: no automation, no core file changes.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Class to describe and enable module Serialtracker
 */
class modSerialtracker extends DolibarrModules
{
	/**
	 *  Constructor.
	 *
	 *  @param  DoliDB  $db  Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Distinct numero from leadtracker (500121) so both install side by side.
		$this->numero = 500122;

		$this->rights_class = 'serialtracker';

		$this->family = "products";
		$this->module_position = '91';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "Order-fulfillment / thing's-journey tracker prototype (project + customer tabs, per-serial detail).";
		$this->descriptionlong = "Serial Tracker follows a thing's journey anchored on the order line — Ordered, Picking, Shipped (counts per line) — then fans out into each shipped serial's back-half: Manufactured (MO traced by lot), Shipped, Under Warranty, Support Ended. Surfaced as a Fulfillment tab on projects and customers, a slim summary on the project card, and a per-serial detail page.";

		$this->editor_name = 'Serial Tracker contributors';
		$this->editor_url = '';

		$this->version = '0.2.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'barcode';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(
				'data'   => array('elementproperties', 'projectcard'),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();

		// Fulfillment tab on both the project and the customer (third party) cards.
		$this->tabs = array();
		$this->tabs[] = array('data' => 'project:+serialtracker:SerialtrackerTabTitle:serialtracker@serialtracker:$user->hasRight(\'serialtracker\', \'read\'):/serialtracker/project_fulfillment.php?id=__ID__');
		$this->tabs[] = array('data' => 'thirdparty:+serialtracker:SerialtrackerTabTitle:serialtracker@serialtracker:$user->hasRight(\'serialtracker\', \'read\'):/serialtracker/thirdparty_fulfillment.php?id=__ID__');

		$this->config_page_url = array();

		$this->hidden = false;
		$this->depends = array('modProjet');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("serialtracker@serialtracker");
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(14, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			// SAMPLE = 1 renders mock serials so the UX is visible before the live
			// MO/shipment/warranty linkage is verified and wired. Set to 0 once real
			// evidence resolution is in place.
			array('SERIALTRACKER_SAMPLE', 'chaine', '1', 'Render sample serials for UX preview (1) or live data (0)', 0),
			array('SERIALTRACKER_DEBUG',  'chaine', '0', 'Show debug output to admins only', 0),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1); // 50012201
		$this->rights[$r][1] = 'See the serial lifecycle tracker';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';

		$this->menu = array();
	}

	/**
	 *  Called when module is enabled.
	 *
	 *  @param  string  $options  Options
	 *  @return int                1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *  Called when module is disabled.
	 *
	 *  @param  string  $options  Options
	 *  @return int                1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
