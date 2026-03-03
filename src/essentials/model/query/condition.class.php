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
class Condition
{
	public $_operator;
	public $_op1;
	public $_op2;
	public $_pre;
	public $_suf;

	function __construct($operator="AND",$op1="",$op2 = "?",$prefix="",$suffix="")
	{
		$this->_operator = " $operator ";
		$this->_op1 = $op1;
		$this->_op2 = $op2;
		$this->_pre = $prefix;
		$this->_suf = $suffix;

        if ($op1 == "?" || $op2 == "?")
            \ScavixWDF\WdfDbException::Raise("Stop calling with ?, use ConditionArgument class instead",$operator,$op1,$op2,$prefix,$suffix);
	}

	function __toSql()
	{
        if ($this->_operator == " SQL ")
            return "{$this->_op1}";

        if (\is_array($this->_op2))
        {
            $op2 = [];
            foreach ($this->_op2 as $o)
                $op2[] = "$o";
            return "{$this->_op1}{$this->_operator}(" . implode(",", $op2) . ")";
        }
		return "{$this->_pre}{$this->_op1}{$this->_operator}{$this->_op2}{$this->_suf}";
	}

	function __fqFields(&$knownModels)
	{
		return;
		foreach( $knownModels as &$km )
		{
			if( !($this->_op1 instanceof ConditionArgument) )
			{
				$this->_op1 = $km->FullQualifiedFieldName($this->_op1);
				continue;
			}
			if( !($this->_op2 instanceof ConditionArgument) )
				$this->_op2 = $km->FullQualifiedFieldName($this->_op2);
		}
	}

    function __getArgs($log = false)
    {
        $args = [];
        $this->__fillArgs($args, $this->_op1, $log);
        $this->__fillArgs($args, $this->_op2, $log);
        if( $log )
            log_debug(__METHOD__, $args);
        return $args;
    }

    private function __fillArgs(&$args, $obj, $log = false)
    {
        if( $log )
            log_debug(__METHOD__, $obj, $args);
        if ($obj instanceof ConditionArgument)
        {
            $args[$obj->name] = $obj->value;
        }
        elseif (\is_array($obj))
            foreach ($obj as $o)
                $this->__fillArgs($args, $o, $log);
    }
}