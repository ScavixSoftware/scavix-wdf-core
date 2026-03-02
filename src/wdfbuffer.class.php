<?php
/**
 * Scavix Web Development Framework
 *
 * Copyright (c) since 2026 Scavix Software GmbH & Co. KG
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
 * @author Scavix Software GmbH & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright since 2026 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF;

class WdfBuffer implements \Iterator, \JsonSerializable
{
    protected string $context = '';
    protected $changed = false;
    protected $data = [];
    protected $session_name = false;
    protected $position = 0;

    function __construct($initial_data=[])
    {
        if( is_callable($initial_data) )
            $this->data = $initial_data();
        else
            $this->data = \is_array($initial_data)?$initial_data:[];
    }

    public function switchContext(string $context)
    {
        if ($context != $this->context)
            $this->clear();
        $this->context = $context;
        return $this;
    }

	/**
	 * @internal see <JsonSerializable>
	 */
    #[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
        return $this->dump();
    }

	/**
     * Maps this buffer to a $_SESSION variable.
	 *
	 * Mapping to the session means that from now on all data will be stored
	 * into $_SESSION[$name] and that getting data will transparently use that variable too.
     *
     * @param string $name Name of session variable
     * @return \ScavixWDF\WdfBuffer
     */
    function mapToSession($name=false)
    {
        if( !$this->session_name )
            $this->session_name = $name;
        return $this;
    }

	/**
     * Returns all data as assiciative array.
	 *
     * @return array
     */
    function dump()
    {
        if( $this->session_name && isset($_SESSION[$this->session_name]) )
            return array_merge($_SESSION[$this->session_name],$this->data);
        return $this->data;
    }

	/**
     * Returns true if some data has been changed.
	 *
	 * This is true, if <WdfBuffer::set> or <WdfBuffer::set> have been used
	 * and if they effectively did something.
	 *
     * @return bool
     */
    function hasChanged()
    {
        return $this->changed;
    }

	/**
     * Returns an array of data keys.
	 *
     * @return array
     */
    function keys()
    {
        $keys = array_keys($this->data);
        if( $this->session_name && isset($_SESSION[$this->session_name]) )
            $keys = array_unique(array_merge($keys,array_keys($_SESSION[$this->session_name])));
        return $keys;
    }

    /**
     * Returns true, if there's data stored with the given name.
	 *
	 * @param string $name The key for the data
     * @return bool
     */
    function has($name)
    {
        return isset($this->data[$name])
            || ($this->session_name && isset($_SESSION[$this->session_name][$name]));
    }

	/**
     * Stores data in the buffer.
	 *
	 * @param string $name The key for the data
	 * @param mixed $value The data to store
     * @return mixed The value given
     */
    function set($name, $value)
    {
        if( !$this->changed )
            $prev = $this->get($name,null);
        $this->data[$name] = $value;
        if( $this->session_name )
            $_SESSION[$this->session_name][$name] = $value;
        if( !$this->changed )
            $this->changed = ($prev !== $value);
        return $value;
    }

	/**
     * Removes data from the buffer.
	 *
	 * @param string $name The key for the data
     * @return mixed The removed value if present, else null
     */
    function del($name)
    {
        if( isset($this->data[$name]) )
        {
            $r = $this->data[$name];
            unset($this->data[$name]);
            $this->changed = true;
        }
        if( $this->session_name && isset($_SESSION[$this->session_name][$name]) )
        {
            unset($_SESSION[$this->session_name][$name]);
            $this->changed = true;
        }
        return isset($r)?$r:null;
    }

    /**
     * Removes all data from the buffer.
     *
     * @return void
     */
    function clear()
    {
        $this->changed = \count($this->data)>0;
        $this->data = [];

        if( $this->session_name && isset($_SESSION[$this->session_name]) )
        {
            $this->changed |= \count($_SESSION[$this->session_name])>0;
            $_SESSION[$this->session_name] = [];
        }
    }

	/**
     * Returns data from the buffer.
	 *
	 * @param string $name The key for the data
	 * @param mixed $default A default value, can be a callable too that will get the name and must return the value;
     * @return mixed The removed value if present, else null
     */
    function get($name, $default=null)
    {
        if( !isset($this->data[$name]) && $this->session_name && isset($_SESSION[$this->session_name][$name]) )
            $this->data[$name] = $_SESSION[$this->session_name][$name];
        if( isset($this->data[$name]) )
            return $this->data[$name];
        if( is_callable($default) )
            return $this->set($name,$default($name));
        return $default;
    }

    /**
     * @implements <Iterator::rewind>
     */
    public function rewind():void
    {
        $this->position = 0;
    }

    /**
	 * @implements <Iterator::current>
	 */
    #[\ReturnTypeWillChange]
    public function current():mixed
    {
        return $this->get($this->key());
    }

    /**
	 * @implements <Iterator::key>
	 */
    #[\ReturnTypeWillChange]
    public function key():mixed
    {
        return $this->keys()[$this->position];
    }

    /**
	 * @implements <Iterator::next>
	 */
    public function next():void
    {
        $this->position++;
    }

    /**
	 * @implements <Iterator::valid>
	 */
    public function valid():bool
    {
        return isset($this->keys()[$this->position]);
    }
}