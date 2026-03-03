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
namespace ScavixWDF\Model\Query;

/**
 * @internal Helper class for the SQL query builder <Query>
 */
class ConditionTree
{
	public $_firstToken = "WHERE";
	public $_operator = "AND";
	public $_conditions = [];
	public $_maxConditions = -1;
	public $_current = false;
	public $_parent = false;

	function __construct($conditionCount = -1,$operator = "AND", $firstToken = "WHERE")
	{
		$this->_operator = $operator;
		$this->_maxConditions = $conditionCount;
		$this->_current = $this;
		$this->_firstToken = $firstToken;
	}

	function __fqFields(&$knownModels)
	{
		foreach( $this->_conditions as &$c )
			if( $c instanceof Condition)
				$c->__fqFields($knownModels);
	}

	function __generateSql()
	{
		if( \count($this->_conditions) < 1 )
			return "";

		$sql = [];
		foreach( $this->_conditions as $c )
		{
			if( \is_string($c) )
				$s = $c;
			elseif( $c instanceof Condition )
				$s = $c->__toSql();
			else
				$s = $c->__generateSql();
			if( $s )
				$sql[] = $s;
		}
		if( \count($sql) == 0 )
			return "";
        if( $this->_operator == "IF" )
        {
            if( \count($sql) != 1 )
                \ScavixWDF\WdfException::Raise("Cannot handle more that 1 conditions in matched 'if' tree, use andX/orX/...");
            if (!$this->_firstToken)
                return "";
            return "({$sql[0]})";
        }

		if( $this->_parent )
			$sql = "(".implode(" {$this->_operator} ",$sql).")";
		else
			$sql = " {$this->_firstToken} ".implode(" {$this->_operator} ",$sql);
		return $sql;
	}

    function __getArgs($log = false)
    {
        $args = [];
        foreach ($this->_conditions as $c)
        {
            if( $c instanceof ConditionTree )
                $args += $c->__getArgs($log);
            if( $c instanceof Condition )
                $args += $c->__getArgs($log);
        }
        return $args;
    }

	function __ensureClose()
	{
		if( $this->_current->_parent && $this->_current->_maxConditions > -1 &&
			\count($this->_current->_conditions) == $this->_current->_maxConditions )
		{
			$this->_current = $this->_current->_parent;
			$this->__ensureClose();
		}
	}

	function SetOperator($operator)
	{
		$this->Nest(-1,$operator);
	}

	function Add($condition)
	{
        if( $this->_current->_operator == "IF" && !$this->_current->_firstToken )
        {
            $this->_current->_conditions[] = "";
            $this->__ensureClose();
            return false;
        }
		$this->_current->_conditions[] = $condition;
		$this->__ensureClose();
        return true;
	}

	function Nest($conditionCount,$operator = "AND",$firstToken = "WHERE")
	{
		$mem = $this->_current;
		$this->_current->_conditions[] = new ConditionTree($conditionCount,$operator,$firstToken);
		$this->_current = $this->_current->_conditions[\count($this->_current->_conditions)-1];
		$this->_current->_parent = $mem;
	}

    function Close()
    {
        $this->_current->_maxConditions = \count($this->_current->_conditions);
        $this->__ensureClose();
    }
}