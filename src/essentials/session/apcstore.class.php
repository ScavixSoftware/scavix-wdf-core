<?php
/**
 * Scavix Web Development Framework
 *
 * Copyright (c) 2017-2019 Scavix Software Ltd. & Co. KG
 * Copyright (c) since 2019 Scavix Software GmbH & Co. KG
 *
 * This library is free software; you can redistribute it
 * and/or modify it under the terms of the GNU Lesser General
 * Public License as published by the Free Software Foundation;
 * either version 3 of the License, or (at your option) any
 * later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library. If not, see <http://www.gnu.org/licenses/>
 *
 * @author Scavix Software Ltd. & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright 2017-2019 Scavix Software Ltd. & Co. KG
 * @author Scavix Software GmbH & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright since 2019 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF\Session;

use ScavixWDF\WdfException;

/**
 * Implements <ObjectStore> for use with APC.
 * @deprecated This is non functional. Do not use.
 * @suppress PHP0417
 */
class APCStore extends ObjectStore
{
    /**
     * @override <ObjectStore::Store>
     */
    function Store(&$obj,$id="")
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    /**
     * @override <ObjectStore::Delete>
     */
	function Delete($id)
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    /**
     * @override <ObjectStore::Exists>
     */
	function Exists($id)
    {
        WdfException::Raise("APC Store is obsolete and non functional");
        return false;
    }

    /**
     * @override <ObjectStore::Restore>
     */
	function Restore($id)
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    /**
     * @override <ObjectStore::Cleanup>
     */
    function Cleanup()
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    /**
     * @override <ObjectStore::Update>
     */
    function Update($keep_alive=false)
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    /**
     * @override <ObjectStore::Migrate>
     */
    function Migrate($old_session_id, $new_session_id)
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }

    function Clear()
    {
        WdfException::Raise("APC Store is obsolete and non functional");
    }
}
