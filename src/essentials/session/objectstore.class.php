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

/**
 * ObjectStore class is used to store objects without blowing up the PHP session.
 *
 * When objects are serialized into the $_SESSION variable, PHP startup time increases
 * dramatically once a fair number of objects is reached. To avoid this we use a special
 * ObjectStore. In fact, we implemented different stores for different scenarios, all inherited from this
 * base class.
 */
abstract class ObjectStore
{
    protected $serializer;
    public static $buffer = [];

    protected function __construct()
    {
        $this->serializer = Serializer::Get();
        if( !isset($_SESSION['object_ids']) )
            $_SESSION['object_ids'] = [];
    }

    /**
     * Stores an object.
     *
     * @param mixed $obj Object to be stored
     * @param string $id Optional id
     * @return void
     */
	abstract function Store(&$obj,$id="");

    /**
     * Removes an object from the store.
     *
     * @param string $id The object ID
     * @return void
     */
	abstract function Delete($id);

    /**
     * Checks, if an object is stored.
     *
     * @param string $id The object ID
     * @return bool true or false
     */
	abstract function Exists($id);

    /**
     * Loads an object from the store.
     *
     * @param string $id The object ID
     * @return mixed The object or false
     */
	abstract function Restore($id);

    /**
     * @internal Cleans things up
     */
    abstract function Cleanup();

    /**
     * Updates used objects.
     *
     * @param bool $keep_alive If true, updates the 'used' timestamps too
     * @return void
     */
    abstract function Update($keep_alive=false);

    /**
     * Changes the session ID.
     *
     * @param string $old_session_id The old session ID
     * @param string $new_session_id The new session ID
     * @return void
     */
    abstract function Migrate($old_session_id, $new_session_id);

    abstract function Clear();

    /**
     * @internal Creates a unique object ID
     */
    function CreateId(&$obj)
    {
        $start = microtime(true);
		if( Serializer::isUnserializing() )
		{
			log_trace("create_storage_id while unserializing object of type ".get_class_simple($obj));
			$obj->_storage_id = "to_be_overwritten_by_unserializer";
			return $obj->_storage_id;
		}

		$cn = strtolower(get_class_simple($obj));
		if( !isset($_SESSION['object_ids'][$cn]) )
			$_SESSION['object_ids'][$cn] = 1;
		else
			$_SESSION['object_ids'][$cn]++;

        $obj->_storage_id = $cn.$_SESSION['object_ids'][$cn];
        \ScavixWDF\Wdf::Measure(__METHOD__,$start);
        return $obj->_storage_id;
    }
}
