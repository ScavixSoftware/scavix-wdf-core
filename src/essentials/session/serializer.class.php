<?php
/**
 * Scavix Web Development Framework
 *
 * Copyright (c) 2007-2012 PamConsult GmbH
 * Copyright (c) 2013-2019 Scavix Software Ltd. & Co. KG
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
 * @author PamConsult GmbH http://www.pamconsult.com <info@pamconsult.com>
 * @copyright 2007-2012 PamConsult GmbH
 * @author Scavix Software Ltd. & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright 2012-2019 Scavix Software Ltd. & Co. KG
 * @author Scavix Software GmbH & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright since 2019 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF\Session;

use Closure;
use DateTime;
use Exception;
use PDOStatement;
use ScavixWDF\Base\DateTimeEx;
use ScavixWDF\Model\DataSource;
use ScavixWDF\Model\Model;
use ScavixWDF\Reflection\WdfReflector;
use ScavixWDF\WdfException;
use SimpleXMLElement;

/**
 * Serializer/Unserializer
 *
 * We have our very own that support some specialities like database reconnection, datetime formats, reflection,...
 * As we implemented our own object storage and serialize it in one run, we can be sure that
 * the referential integrity will be given.
 */
class Serializer
{
    private static ?Serializer $_instance = null;
    public static function Get()
    {
        if( self::$_instance === null)
            self::$_instance = new Serializer();
        return self::$_instance;
    }

	public $Stack;
	public $sleepmap;
	public $Lines;
    public $Index;

    public static $unserializing_level = 0;

	/**
	 * Serializes a value
	 *
	 * Can be anything from complex object to bool value
	 * @param mixed $data Value to serialize
	 * @return string Serialized data string
	 */
	function Serialize(&$data)
	{
		$stack  = new \SplObjectStorage();
		$this->sleepmap = [];
		$r = $this->Ser_Inner($data, $stack);
        return $r;
	}

	private function Ser_Inner(&$data,$stack)
	{
		if( \is_string($data) )
		{
            if( strpos($data,"\n") === false )
                return "s:". $data ."\n";
			return "S:". json_encode($data) ."\n";
		}
		elseif( \is_int($data) )
		{
			return "i:$data\n";
		}
		elseif( \is_array($data) )
		{
			$res = "a:".\count($data)."\n";
			$keys = array_keys($data);
			foreach( $keys as $key )
			{
				$res .= $this->Ser_Inner($key,$stack);
				$res .= $this->Ser_Inner($data[$key],$stack);
			}
			return $res;
		}
		elseif( \is_bool($data) )
		{
			return "b:".($data?'1':'0')."\n";
		}
		elseif( \is_float($data) )
		{
			return "f:$data\n";
		}
		elseif( empty($data) )
		{
			return "n:\n";
		}
		else
		{
			if( $data instanceof DataSource )
				return "m:".$data->_storage_id."\n";
			if( $data instanceof PDOStatement || $data instanceof Closure )
				return "n:\n";
			if( $data instanceof DateTimeEx )
			{
				$dtres = $data->format('c');
				if( substr($dtres,0,4)=="-001" )
					return "x:\n";
				return "x:$dtres\n";
			}
			if( $data instanceof DateTime )
			{
				$dtres = $data->format('c');
				if( substr($dtres,0,4)=="-001" )
					return "d:\n";
				return "d:$dtres\n";
			}
			if( $data instanceof WdfReflector )
				return "y:".$data->getName()."\n";
			if( $data instanceof SimpleXMLElement )
				return "z:".addcslashes($data->asXML(),"\n")."\n";

            $index = $stack[$data] ?? false;
			if( $index !== false  )
				return "r:$index\n";
			$id = \count($stack);
			$stack[$data] = $id;

			$classname = \get_class($data);
            if (!isset($this->sleepmap[$classname]))
            {
                $this->sleepmap[$classname] = method_exists($data, '__sleep')
                    ? array_fill_keys($data->__sleep(), true)
                    : false;
            }

            $vars = get_object_vars($data);
            if( $this->sleepmap[$classname] )
                $vars = array_diff_key($vars, $this->sleepmap[$classname]);
            $res = ($data instanceof Model)
                ? "o:$id:" . \count($vars) . ":$classname:{$data->DataSourceName()}\n"
                : "o:$id:" . \count($vars) . ":$classname:\n";
            foreach( $vars as $n=>$v )
            {
                $res .= $this->Ser_Inner($n,$stack);
                $res .= $this->Ser_Inner($v,$stack);
            }

			return $res;
		}
	}

	/**
	 * Restores something from a serialized data string
	 *
	 * Note that of course all types used in that string must be known to the unserializing application!
	 * @param string $data Serialized data
	 * @return mixed Whatever was serialized
	 */
    function Unserialize($data)
    {
        try
        {
            $mem = [$this->Index, $this->Lines, $this->Stack];
            self::$unserializing_level++;
            $this->Index = 0;
            $this->Lines = explode("\n", trim($data));
            $this->Stack = [];
            $res = $this->Unser_Inner();
            [$this->Index, $this->Lines, $this->Stack] = $mem;
            return $res;

        }
        finally
        {
            self::$unserializing_level--;
        }
    }

    private $existsBuffer = [];

	private function Unser_Inner()
	{
		$orig_line = $this->Lines[$this->Index++];
		if( $orig_line == "" )
			return null;
		$type = $orig_line[0];
		$line = substr($orig_line, 2);

        // backwards compatibility!
        if( $type == 'k' || $type == 'f' || $type == 'v')
		{
            if( isset($line[1]) && $line[1]==':' )
            {
            	$type = $line[0];
                $line = substr($line, 2);
            }
		}

		try
		{
			switch( $type )
			{
                case "$":
                    $res = str_replace(["\\r","\\n"], ["\r","\n"], $line);
                    return $res;
				case 'S':
					$res = json_decode($line);
                    return $res;
				case 's':
                    return $line;
				case 'i':
					return \intval($line);
				case 'a':
					$res = [];
					for($i=0; $i<$line; $i++)
					{
						$key = $this->Unser_Inner();
						$res[$key] = $this->Unser_Inner();
					}
                    return $res;
				case 'd':
					if( !$line )
						return null;
					return new DateTime($line);
				case 'x':
					if( !$line )
						return null;
					return new DateTimeEx($line);
				case 'y':
					return new WdfReflector($line);
				case 'z':
					return simplexml_load_string(stripcslashes($line));
				case 'o':
                    [$id, $len, $type, $alias] = explode(':',$line);
					$datasource = $alias?model_datasource($alias):null;

                    if( $alias )
                        $this->Stack[$id] = new $type($datasource);
                    else
                    {
                        $this->Stack[$id] = WdfReflector::GetInstance($type)->newInstanceWithoutConstructor();
                        if( !isset($this->existsBuffer["$type::__constructed"]) )
                            $this->existsBuffer["$type::__constructed"] = method_exists($type,'__constructed');
                        if( $this->existsBuffer["$type::__constructed"] )
                            $this->Stack[$id]->__constructed();
                    }
					for($i=0; $i<$len; $i++)
					{
						$field = $this->Unser_Inner();
						if( !\is_string($field) || $field == "" )
							continue;
						$this->Stack[$id]->$field = $this->Unser_Inner();
					}

                    if( !isset($this->existsBuffer["$type::__wakeup"]) )
                        $this->existsBuffer["$type::__wakeup"] = method_exists($type,'__wakeup');
                    if( $this->existsBuffer["$type::__wakeup"] )
						$this->Stack[$id]->__wakeup();

					return $this->Stack[$id];

				case 'r':
					if( !isset($this->Stack[\intval($line)]) )
						WdfException::Raise("Trying to reference unknown object.");
					if( $this->Stack[\intval($line)] instanceof DataSource )
						return model_datasource($this->Stack[\intval($line)]->_storage_id);
					return $this->Stack[\intval($line)];
				case 'm':
					return model_datasource($line);
				case 'n':
					return null;
				case 'f':
					return \floatval($line);
				case 'b':
					return $line==1;
				default:
					WdfException::Raise("Unserialize found unknown datatype '$type'. Line was $orig_line");
			}
		}
		catch(Exception $ex)
		{
			WdfException::Log($ex);
			return null;
		}
	}
}
