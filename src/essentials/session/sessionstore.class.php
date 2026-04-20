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

use ScavixWDF\Wdf;
use ScavixWDF\WdfException;

/**
 * Stores objects in the SESSION.
 *
 * Well...this is the storage that does what we did not want anymore: Blow up the PHP Session.
 * It ist straight and some cases it can be useful, because it's configurationless.
 */
class SessionStore extends ObjectStore
{
    private $prefix = '';
    public function __construct()
    {
        $this->prefix = $GLOBALS['CONFIG']['session']['sessionstore']['prefix'] ?? $GLOBALS['CONFIG']['session']['prefix'] ?? '';
        if( !isset($_SESSION["{$this->prefix}object_access"]) )
            $_SESSION["{$this->prefix}object_access"] = [];
        parent::__construct();
    }

    /**
     * @override <ObjectStore::Store>
     */
    function Store(&$obj,$id="")
    {
        $start = microtime(true);
		$id = strtolower($id);
		if( $id == "" )
		{
			if( !isset($obj->_storage_id) )
				WdfException::Raise("Trying to store an object without storage_id!");
			$id = $obj->_storage_id;
		}
		else
			$obj->_storage_id = $id;

        $content = $this->serializer->Serialize($obj);
        $_SESSION["{$this->prefix}session"][$id] = $content;

		ObjectStore::$buffer[$id] = $obj;

        $_SESSION["{$this->prefix}object_access"][$obj->_storage_id] = time();
        Wdf::Measure(__METHOD__, $start);
    }

    /**
     * @override <ObjectStore::Delete>
     */
	function Delete($id)
    {
        $start = microtime(true);

		if( is_object($id) && isset($id->_storage_id) )
			$id = $id->_storage_id;

        if( isset($_SESSION["{$this->prefix}object_access"][$id]) )
            unset($_SESSION["{$this->prefix}object_access"][$id]);
        if( isset($_SESSION['object_id_storage'][$id]) )
            unset($_SESSION['object_id_storage'][$id]);

		$id = strtolower($id);
		if(isset($_SESSION["{$this->prefix}session"][$id]))
			unset($_SESSION["{$this->prefix}session"][$id]);
		unset(ObjectStore::$buffer[$id]);
        Wdf::Measure(__METHOD__,$start);
    }

    /**
     * @override <ObjectStore::Exists>
     */
	function Exists($id)
    {
        $start = microtime(true);

		if( is_object($id) && isset($id->_storage_id) )
			$id = $id->_storage_id;
		$id = strtolower($id);
		if( isset(ObjectStore::$buffer[$id]) )
			$res = true;
        else
            $res = isset($_SESSION["{$this->prefix}session"][$id]);
        Wdf::Measure(__METHOD__,$start);
		return $res;
    }

    /**
     * @override <ObjectStore::Restore>
     */
	function Restore($id)
    {
        $start = microtime(true);
		$id = strtolower($id);

		if( isset(ObjectStore::$buffer[$id]) )
			$res = ObjectStore::$buffer[$id];
        else
        {
            if(!isset($_SESSION["{$this->prefix}session"][$id]))
                return null;

            $data = $_SESSION["{$this->prefix}session"][$id];

            $res = $this->serializer->Unserialize($data);
            ObjectStore::$buffer[$id] = $res;

        }

        if( $res && is_object($res) && isset($res->_storage_id) )// update objects last access
            $_SESSION["{$this->prefix}object_access"][$res->_storage_id] = time();
        Wdf::Measure(__METHOD__,$start);
		return $res;
    }

    /**
     * @override <ObjectStore::Cleanup>
     */
    function Cleanup()
    {
        $start = microtime(true);

        if(isset($_SESSION["{$this->prefix}object_access"]))
        {
            foreach( $_SESSION["{$this->prefix}object_access"] as $id=>$time )
            {
                if( isset(ObjectStore::$buffer[$id]) || $time + 60 > time() )
                    continue;
                delete_object($id);
            }
        }
        Wdf::Measure(__METHOD__,$start);
    }

    /**
     * @override <ObjectStore::Update>
     */
    function Update($keep_alive=false)
    {
        $start = microtime(true);

        if( $keep_alive )
        {
            foreach( $_SESSION["{$this->prefix}object_access"] as $id=>$time )
                $_SESSION["{$this->prefix}object_access"][$id] = time();
            return;
        }

        foreach( ObjectStore::$buffer as $id=>&$obj )
		{
			try
			{
				$this->Store($obj,$id);
			}
			catch(\Exception $ex)
			{
				WdfException::Log("updating storage for object $id [".get_class($obj)."]",$ex);
			}
		}
        Wdf::Measure(__METHOD__.($keep_alive?"/KA":''),$start);
    }

    /**
     * @override <ObjectStore::Migrate>
     */
    function Migrate($old_session_id, $new_session_id)
    {
        // nothing to to because session variable is migrated by PHP itself
    }

    function Clear()
    {
        $_SESSION["{$this->prefix}session"] = [];
        $_SESSION["{$this->prefix}object_access"] = [];
    }
}
