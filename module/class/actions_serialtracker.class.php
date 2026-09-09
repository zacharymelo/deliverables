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
 *  \file       class/actions_serialtracker.class.php
 *  \ingroup    serialtracker
 *  \brief      Hook handler — injects the per-serial lifecycle list into project cards.
 *
 *  Mirrors Lead Tracker's proven approach: render via formObjectOptions (v22
 *  discards resprints from printCommonFooter), then a jQuery snippet relocates
 *  the hidden block onto the card. Positioned BELOW the card banner so it sits
 *  clearly apart from the Lead Tracker funnel for side-by-side comparison.
 */
class ActionsSerialtracker
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Hook contract method for the 'elementproperties' context. No business
	 *  objects, so always continue.
	 *
	 *  @param  array       $parameters
	 *  @param  object      $object
	 *  @param  string      $action
	 *  @param  HookManager $hookmanager
	 *  @return int          0 = continue
	 */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		return 0;
	}

	/**
	 *  Hook fired during the card form render. Injects the serial lifecycle list.
	 *
	 *  @param  array         $parameters
	 *  @param  CommonObject  $object
	 *  @param  string        $action
	 *  @param  HookManager   $hookmanager
	 *  @return int            0 on success
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		// v22 does not always forward the page object into the hook.
		$globalObj = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		if ((!is_object($object) || empty($object->element) || empty($object->id))
			&& $globalObj !== null && !empty($globalObj->element) && !empty($globalObj->id)) {
			$object = $globalObj;
		}

		if (!is_object($object) || empty($object->element) || empty($object->id)) {
			return 0;
		}

		// Project cards only.
		if ($object->element !== 'project') {
			return 0;
		}

		// Deduplication — some card pages call the hook more than once per request.
		static $rendered = array();
		$renderKey = $object->element.':'.$object->id;
		if (isset($rendered[$renderKey])) {
			return 0;
		}
		$rendered[$renderKey] = true;

		if (!$user->hasRight('serialtracker', 'read')) {
			return 0;
		}

		if (!class_exists('SerialLifecycleResolver')) {
			dol_include_once('/serialtracker/class/seriallifecycleresolver.class.php');
		}
		if (!class_exists('SerialLifecycleRenderer')) {
			dol_include_once('/serialtracker/class/seriallifecyclerenderer.class.php');
		}
		if (!class_exists('SerialLifecycleResolver') || !class_exists('SerialLifecycleRenderer')) {
			return 0;
		}

		$langs->loadLangs(array('serialtracker@serialtracker'));

		$resolver = new SerialLifecycleResolver($this->db);
		$sum      = $resolver->summaryForProject((int) $object->id);
		if (empty($sum) || (int) $sum['lines'] === 0) {
			return 0;
		}

		$renderer = new SerialLifecycleRenderer();
		$renderer->isSample = $resolver->isSample;

		// Slim one-line summary only — the full detail lives in the Fulfillment tab.
		$tabUrl = dol_buildpath('/serialtracker/project_fulfillment.php', 1).'?id='.((int) $object->id);

		$url = dol_buildpath('/serialtracker/css/serialtracker.css', 1);
		$out  = '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($url).'">'."\n";
		$out .= '<div id="serialtracker-holder" style="display:none;">';
		$out .= '<div class="serialtracker-wrap">';
		$out .= $renderer->renderSummaryLine($sum, $tabUrl);
		$out .= '</div>';
		$out .= '</div>'."\n";
		$out .= $this->relocationScript();

		$this->resprints = $out;
		return 0;
	}

	/**
	 *  jQuery snippet that moves the hidden block onto the card, just below the
	 *  banner. Inserted AFTER the arearef so it sits beneath the Lead Tracker bar
	 *  (which inserts before arearef) — the two widgets stay visually distinct.
	 *
	 *  @return string
	 */
	private function relocationScript()
	{
		return "<script>\n"
			."jQuery(function(){\n"
			." var holder=jQuery('#serialtracker-holder');\n"
			." if(!holder.length){return;}\n"
			." var wrap=holder.children('.serialtracker-wrap');\n"
			." if(wrap.length){\n"
			."  var anchor=jQuery('div.arearef').first();\n"
			."  if(anchor.length){anchor.after(wrap);}\n"
			."  else{var c=jQuery('div.fichecenter').first();\n"
			."   if(c.length){c.prepend(wrap);}\n"
			."   else{jQuery('div.tabBar').first().prepend(wrap);}}\n"
			." }\n"
			." holder.remove();\n"
			."});\n"
			."</script>\n";
	}
}
