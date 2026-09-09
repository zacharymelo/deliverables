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
 *  \file       class/serialwarrantyadapter.class.php
 *  \ingroup    serialtracker
 *  \brief      Isolation layer for warranty / returns / support-ended resolution.
 *
 *  DELIBERATELY the ONLY place that knows about the warranty & returns tables.
 *  Those modules are being overhauled, so nothing else in serialtracker couples
 *  to their schema. forSerial() returns null ("deferred") until the overhaul
 *  settles; then implement it here and the whole back-half lights up — no other
 *  file changes.
 *
 *  Known schema as of 2026-09 (subject to the overhaul, DO NOT rely on yet):
 *    llxiw_svc_warranty:  serial_number, fk_product, fk_soc, fk_commande,
 *                         fk_expedition, warranty_type, start_date, expiry_date,
 *                         coverage_days, status ('active'|...)
 *    llxiw_customer_return_line: serial_number, fk_product, fk_expedition, ...
 *
 *  Target return shape (when implemented):
 *    array(
 *      'active'       => bool,   // currently under warranty
 *      'ended'        => bool,   // support/warranty ended
 *      'start'        => int,    // unix ts (warranty start)
 *      'ended_ts'     => int,    // unix ts (support ended), 0 if not
 *      'expiry_str'   => string, // e.g. "exp 2027-03-27" (step subtitle)
 *      'status_label' => string, // tooltip status
 *    )
 *  or null when there is no warranty record / resolution is deferred.
 */
class SerialWarrantyAdapter
{
	/**
	 *  Resolve warranty/support state for one serial. Deferred for now.
	 *
	 *  @param  DoliDB  $db
	 *  @param  string  $serialNumber  The lot/batch serial string
	 *  @param  int     $productId
	 *  @return array|null              Null = deferred / no record
	 */
	public static function forSerial($db, $serialNumber, $productId)
	{
		// Overhaul in progress — do not read the warranty/returns tables yet.
		return null;
	}
}
