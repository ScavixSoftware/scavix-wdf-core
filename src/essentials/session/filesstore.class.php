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
 * Stores objects in the filesystem.
 *
 * This is by far the fastets <ObjectStore> implementation. As we use it mostly,
 * it is most commonly updated!
 */
class FilesStore extends ObjectStore
{
    protected $options = [];
    protected $serializer;
    protected $path = false;

    protected function getPath($sid=false)
    {
        if( $sid )
            $directory = $this->path = $this->options['path'].$sid;
        elseif($this->path)
            return $this->path;     // already checked/created
        else
            $directory = $this->path = $this->options['path'].session_id();

        if( is_file($directory) )
            unlink($directory);
        if( !file_exists($directory) )
        {
            $origumask = umask(0);
            if (!@mkdir($directory, 0777, true))
            {
                $err = error_get_last();
                if( $err && isset($err['message']) && stripos($err['message'],"File exists") !== false )
                    log_error('unable to create ' . $directory, $err);
            }
            umask($origumask);
        }
        return $directory;
    }

    protected function getFile($id)
    {
        return $this->getPath()."/$id";
    }

    public function __construct($path = null, $ttl = null)
    {
        $this->options = $GLOBALS['CONFIG']['session']['filesstore'] ?? [];

        if ($path)
            $this->options['path'] = $path;
        elseif (empty($this->options['path']))
            $this->options['path'] = system_app_temp_dir('filesstore', false);

        if ($ttl)
            $this->options['ttl'] = $ttl;
        elseif (empty($this->options['ttl']))
            $this->options['ttl'] = ceil(($GLOBALS['CONFIG']['session']['ping_time'] ?? 60) * 1.5);


        system_ensure_path_ending($this->options['path']);
        $this->serializer = new Serializer();

        if( !isset($_SESSION['object_ids']) )
            $_SESSION['object_ids'] = [];
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

        ObjectStore::$buffer[$id] = $obj;
        Wdf::Measure(__METHOD__,$start);
    }

    /**
     * @override <ObjectStore::Delete>
     */
	function Delete($id)
    {
        $start = microtime(true);
		if( is_object($id) && isset($id->_storage_id) )
			$id = $id->_storage_id;

        if( isset(ObjectStore::$buffer[$id]) )
            unset(ObjectStore::$buffer[$id]);
		@unlink($this->getFile($id));
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

        $lid = strtolower($id);
		if( isset(ObjectStore::$buffer[$id]) )
            $res = true;
		elseif( isset(ObjectStore::$buffer[$lid]) )
			$res = true;
        else
            $res = file_exists($this->getFile($id)) || file_exists($this->getFile($lid));
        Wdf::Measure(__METHOD__,$start);
		return $res;
    }

    /**
     * @override <ObjectStore::Restore>
     */
	function Restore($id)
    {
        $start = microtime(true);
		$lid = strtolower($id);

		if( isset(ObjectStore::$buffer[$id]) )
			$res = ObjectStore::$buffer[$id];
		elseif( isset(ObjectStore::$buffer[$lid]) )
			$res = ObjectStore::$buffer[$lid];
        else
        {
            $res = null;
            foreach ([$id,$lid] as $i)
            {
                $data = @file_get_contents($this->getFile($i));
                if ($data)
                {
                    $res = $this->serializer->Unserialize($data);
                    ObjectStore::$buffer[$i] = $res;
                    break;
                }
            }
        }
        Wdf::Measure(__METHOD__,$start);
		return $res;
    }

    /**
     * @override <ObjectStore::CreateId>
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
        Wdf::Measure(__METHOD__,$start);
        return $obj->_storage_id;
    }

    /**
     * @override <ObjectStore::Cleanup>
     */
    function Cleanup()
    {
        $start = microtime(true);
        $this->withIndex(function (&$items, &$requests)
        {
            $items = array_filter($items, function ($item, $id)
            {
                if ($item['eol'] > time())
                    return true;
                // log_debug("old entry $id");
                @unlink($this->getFile($id));
                return false;
            },ARRAY_FILTER_USE_BOTH);
            $requests = array_filter($requests, function ($req, $id)
            {
                $ret = $req['eol'] > time();
                // if(!$ret)
                //     log_debug("old request_id $id", time(), $req);
                return $ret;
            },ARRAY_FILTER_USE_BOTH);

            return true;
        });
        Wdf::Measure(__METHOD__,$start);

        $start = microtime(true);
        clearstatcache();
        $p = $this->options['path'];
        $ttl = $this->options['ttl'] * 2; // double the standard object TTL to be sure
        foreach( glob($p.'*',GLOB_ONLYDIR) as $d )
        {
            if( $d == "$p." || $d == "$p.." )
                continue;
            $time = @filemtime($d);
            if( !$time || (time() - $time <= $ttl) )
                continue;
            foreach( glob($d.'/*') as $f )
                if( $f != "$d/." && $f != "$d/.." )
                    @unlink($f);
            @rmdir($d);
        }
        Wdf::Measure(__METHOD__,$start);
    }

    private function withIndex($callback)
    {
        $path = $this->getPath();
        $eolfile = "$path/index.json";
        if ($lock = Wdf::GetLock($eolfile, 1, false))
        {
            $index = json_decode(@file_get_contents($eolfile) ?: '[]', true);
            $items = $index['items'] ?? [];
            $requests = $index['requests'] ?? [];
            if ($res = $callback($items, $requests))
                file_put_contents($eolfile, json_encode(compact('items', 'requests'), JSON_PRETTY_PRINT));
            Wdf::ReleaseLock($eolfile);
        }
        else
            @touch($path); // at least touch the path to prevent it from beeing cleaned up
    }

    /**
     * @override <ObjectStore::Update>
     */
    function Update($keep_alive=false)
    {
        $start = microtime(true);
        if( $keep_alive )
        {
            $this->withIndex(function (&$items, &$requests)
            {
                $rid = request_id();
                if (!isset($requests[$rid]))
                {
                    if( isDev() )
                        log_debug("Request $rid not found");
                    return null;
                }
                $ids = $requests[$rid]['items'] ?? [];
                $requests[$rid]['eol'] = time() + $this->options['ttl'];
                if (empty($ids))
                    return true;
                foreach ($ids as $id)
                {
                    if (is_array($id))
                        continue;
                    $ttl = $items[$id]['ttl'] ?? $this->options['ttl'];
                    $items[$id] = [
                        'ttl' => $ttl,
                        'eol' => time() + $ttl,
                    ];
                }
                return true;
            });
            Wdf::Measure(__METHOD__.'/KA',$start);
        }
        else
        {
            $this->withIndex(function (&$items, &$requests)
            {
                if (count(ObjectStore::$buffer) < 1)
                    return false;
                foreach (ObjectStore::$buffer as $id => $obj)
                {
                    $content = $this->serializer->Serialize($obj);
                    $filename = $this->getFile($id);
                    if (@file_put_contents($filename, $content) === false)
                    {
                        // security fallback for....no idea
                        usleep(100 * 1000);
                        $this->path = null;
                        $filename = $this->getFile($id);
                        @file_put_contents($filename, $content);
                    }
                    $ttl = $items[$id]['ttl'] ?? $this->options['ttl'];
                    $items[$id] = [
                        'ttl' => $ttl,
                        'eol' => time() + $ttl,
                    ];
                }
                $requests[request_id()] = [
                    'eol' => time() + $this->options['ttl'],
                    'items' => array_keys(ObjectStore::$buffer),
                    'debug' => ['ep' => Wdf::Request()->getEndpoint(), 'ref' => Wdf::Request()->getHeader('REFERER')]
                ];
                return true;
            });
            Wdf::Measure(__METHOD__,$start);
        }
        touch($this->getPath());
    }

    /**
     * @override <ObjectStore::Migrate>
     */
    function Migrate($old_session_id, $new_session_id)
    {
        $start = microtime(true);
        @rename($this->getPath($old_session_id),$this->getPath($new_session_id));
        $this->path = false;
        Wdf::Measure(__METHOD__,$start);
    }
}
