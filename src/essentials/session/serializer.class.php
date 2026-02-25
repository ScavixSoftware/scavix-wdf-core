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
	public $Lines;
    public $Index;
    public $Format = "swdf-0";

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
		// $this->sleepmap = [];
		$r = $this->Ser_Inner($data, $stack);
        return "format:swdf-1\n$r";
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
			foreach( $data as $key=>$val )
			{
                $res .= "$key\n"; // format:swdf-1
                // $res .= $this->Ser_Inner($key,$stack); // format:swdf-0
				$res .= $this->Ser_Inner($val,$stack);
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
            if ($this->hasMethod($classname, '__serialize'))
                $vars = $data->__serialize();
            elseif ($this->hasMethod($classname, '__sleep'))
                $vars = array_intersect_key(get_object_vars($data), array_fill_keys($data->__sleep(), true));
            else
                $vars = get_object_vars($data);

            $res = ($data instanceof Model)
                ? "o:$id:" . \count($vars) . ":$classname:{$data->DataSourceName()}\n"
                : "o:$id:" . \count($vars) . ":$classname:\n";
            foreach( $vars as $n=>$v )
            {
                $res .= "$n\n"; // format:swdf-1
                // $res .= $this->Ser_Inner($n,$stack); // format:swdf-0
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
            $mem = [$this->Index, $this->Lines, $this->Stack, $this->Format];
            self::$unserializing_level++;
            $this->Index = 0;
            $this->Lines = explode("\n", trim($data));
            $this->Stack = [];

            if (\count($this->Lines) && substr($this->Lines[0], 0, 7) == "format:")
            {
                $this->Format = substr($this->Lines[0], 7);
                $this->Index = 1;
            }

            $res = $this->Unser_Inner();
            [$this->Index, $this->Lines, $this->Stack, $this->Format] = $mem;
            return $res;

        }
        finally
        {
            self::$unserializing_level--;
        }
    }

    private $existsBuffer = [];

    private function hasMethod($classname, $methodname)
    {
        if( !isset($this->existsBuffer["$classname::$methodname"]) )
            $this->existsBuffer["$classname::$methodname"] = method_exists($classname,$methodname);
        return $this->existsBuffer["$classname::$methodname"];
    }

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
                        if ($this->Format == "swdf-1")
                        {
                            $key = $this->Lines[$this->Index++];
                            if (is_numeric($key))
                                $key = \intval($key);
                        }
                        else
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
                    [$id, $len, $type, $alias] = explode(':', $line);
                    $datasource = $alias ? model_datasource($alias) : null;

                    if( $alias )
                        $this->Stack[$id] = new $type($datasource);
                    else
                    {
                        $this->Stack[$id] = WdfReflector::GetInstance($type)->newInstanceWithoutConstructor();
                        if( $this->hasMethod($type,'__constructed') )
                            $this->Stack[$id]->__constructed();
                    }

                    $data = $this->hasMethod($type, '__unserialize') ? [] : null;
					for($i=0; $i<$len; $i++)
					{
                        if ($this->Format == "swdf-1")
                            $field = $this->Lines[$this->Index++];
                        else
						    $field = $this->Unser_Inner();

						if( !\is_string($field) || $field == "" )
							continue;
                        if ($data === null)
						    $this->Stack[$id]->$field = $this->Unser_Inner();
                        else
                            $data[$field] = $this->Unser_Inner();
					}

                    if ($data !== null)
                        $this->Stack[$id]->__unserialize($data);
                    elseif ($this->hasMethod($type, '__wakeup'))
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
