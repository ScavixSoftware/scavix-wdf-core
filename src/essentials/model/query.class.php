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
namespace ScavixWDF\Model;

use PDO;
use ScavixWDF\Model\Query\Condition;
use ScavixWDF\Model\Query\ConditionArgument;
use ScavixWDF\Model\Query\ConditionTree;
use ScavixWDF\WdfDbException;

/**
 * @internal SQL common query builder
 * @suppress PHP0413
 */
class Query
{
	public $_object = false;
	/**
	 * @var DataSource|bool
	 */
	public $_ds = false;
	public $_knownmodels = [];

	public $_initialSequence = false;
	public $_where = false;

	public $_values = [];

	/**
	 * @var ResultSet|bool
	 */
	public $_statement = false;

    function __construct(&$obj,&$datasource,$conditions_separator="WHERE")
	{
        $this->_object = $obj;
        $this->_ds = $datasource;
        $this->_where = new ConditionTree(-1,"AND",$conditions_separator);
        $this->_knownmodels = [$obj];
	}

    public function __clone()
	{
		if( $this->_where )
            $this->_where = unserialize(serialize($this->_where));
	}

	function __toString()
	{
		return $this->_initialSequence . $this->__generateSql();
	}

    protected $argNamePrefix = 'a';
    protected $argNameLength = 2;
    protected $argNameCounter = 0;
    protected function argName()
    {
        if( $this->argNameCounter > 99 && $this->argNameLength == 2 )
        {
            $this->argNamePrefix = \chr(\ord($this->argNamePrefix) + 1);
            $this->argNameCounter = 0;
            if( $this->argNamePrefix == 'z' )
                $this->argNameLength = 9;
        }
        return ":{$this->argNamePrefix}" . str_pad("" . $this->argNameCounter++, $this->argNameLength, "0", STR_PAD_LEFT);
    }

	public function GetSql()
	{
		if( !$this->_statement )
			return $this->__toString();
		return $this->_statement->GetSql();
	}

	public function GetArgs()
	{
        if (!$this->_statement)
        {
            if ($this->_where)
                return $this->_where->__getArgs();
            return [];
        }
		return $this->_statement->GetArgs();
	}

	public function GetPagingInfo($key=false)
	{
		if( !$this->_statement )
			return "";
		return $this->_statement->GetPagingInfo($key);
	}

	function __execute($injected_sql=false, $injected_arguments=[], $ctor_args=null)
	{
		$sql = $injected_sql?$injected_sql:$this->__toString();
		if( $injected_arguments )
            $this->_values = \is_array($injected_arguments)?$injected_arguments:[$injected_arguments];
        else
            $this->_values = $this->_where->__getArgs();

		$this->_statement = $this->_ds->Prepare($sql);
        $exec = $this->_statement->ExecuteWithArguments($this->_values);
		if( !$exec )
        {
            // log_debug("Query:",$this);
			WdfDbException::RaiseStatement($this->_statement);
        }
		$res = $this->_statement->fetchAll(PDO::FETCH_CLASS,\get_class($this->_object),$ctor_args);
		return $res;
	}

	protected function &__conditionTree()
	{
        if( !$this->_where )
            $this->_where = new ConditionTree();
		return $this->_where;
	}

	protected function __fqFields(&$property)
	{
		if( !$property )
			return;
		if( !\is_array($property) )
			$property->__fqFields($this->_knownmodels);
		else
			foreach( $property as &$p )
				if( system_method_exists($p, '__fqFields') )
					$p->__fqFields($this->_knownmodels);
	}

	protected function __generateSql()
	{
		if( \count($this->_knownmodels) > 0 )
			$this->__fqFields($this->_where);
		$sql = $this->_where->__generateSql();
		return $sql;
	}

	function where($defaultOperator = "AND")
	{
		$this->_where = new ConditionTree(-1,$defaultOperator);
	}

	function andAll()
	{
		$this->__conditionTree()->SetOperator("AND");
	}

	function orAll()
	{
		$this->__conditionTree()->SetOperator("OR");
	}

	function andX($count)
	{
		$this->__conditionTree()->Nest($count,"AND");
	}

	function orX($count)
	{
		$this->__conditionTree()->Nest($count,"OR");
	}

    function end()
    {
        $this->__conditionTree()->Close();
    }

    function if($condition)
	{
		$this->__conditionTree()->Nest(1,"IF",!!$condition);
	}

	function sql($sql,$arguments=[])
	{
        $args = [];
        if (strpos($sql, "?") !== false)
        {
            // Detect questionmarks inside text-literals and abstract the whole literal as argument.
            // This avoids the questionmark to be recognized as argument placeholder.
            $sql = preg_replace_callback('/([\'"])([^\1]*)\1/U', function ($m) use (&$args)
            {
                if (strpos($m[2], "?") === false)
                    return $m[0];
                $n = $this->argName();
                $args[$n] = new ConditionArgument($n, trim($m[0], $m[1]));
                return $n;
            }, $sql);
            $sql = preg_replace_callback('/\?/', function ($m) use (&$args, &$arguments)
            {
                $n = $this->argName();
                $args[$n] = new ConditionArgument($n, array_shift($arguments));
                return $n;
            }, $sql);
        }
        else
        {
            foreach ($arguments as $n => $v)
            {
                if (!starts_with($n, ':'))
                    $n = ":$n";
                $args[$n] = new ConditionArgument($n, $v);
            }
        }
        $this->__conditionTree()->Add(new Condition("SQL", $sql, $args));
	}

    protected function stdOp($op, $property, $value, $value_is_sql=false, $prefix='', $suffix='')
    {
        if ($value instanceof ColumnAttribute || $value_is_sql)
            $this->__conditionTree()->Add(new Condition($op, $property, $value, $prefix, $suffix));
        else
        {
            $value = ($value instanceof ConditionArgument) ? $value : new ConditionArgument($this->argName(), $value);
            $this->__conditionTree()->Add(new Condition($op, $property, $value, $prefix, $suffix));
        }
    }

	function equal($property,$value,$value_is_sql=false)
	{
        $this->stdOp('=', $property, $value, $value_is_sql);
	}

	function notEqual($property,$value,$value_is_sql=false)
	{
        $this->stdOp('!=', $property, $value, $value_is_sql);
	}

	function greaterThan($property,$value,$value_is_sql=false)
	{
		$this->stdOp('>', $property, $value, $value_is_sql);
	}

	function greaterThanOrEqualTo($property,$value,$value_is_sql=false)
	{
		$this->stdOp('>=', $property, $value, $value_is_sql);
	}

	function lowerThan($property,$value,$value_is_sql=false)
	{
		$this->stdOp('<', $property, $value, $value_is_sql);
	}

	function lowerThanOrEqualTo($property,$value,$value_is_sql=false)
	{
		$this->stdOp('<=', $property, $value, $value_is_sql);
	}

	function binary($property,$value,$value_is_sql=false)
	{
        $this->stdOp('=', $property, $value, $value_is_sql,"BINARY ");
	}

	function notBinary($property,$value,$value_is_sql=false)
	{
		$this->stdOp('!=', $property, $value, $value_is_sql,"BINARY ");
	}

	function like($property,$value,$flipped=false)
	{
        if ($flipped)
            $this->stdOp('LIKE', new ConditionArgument($this->argName(), $property), $value);
        else
            $this->stdOp('LIKE', $property, $value);
	}

	function rlike($property,$value,$flipped=false)
	{
        if ($flipped)
            $this->stdOp('RLIKE', new ConditionArgument($this->argName(), $property), $value);
        else
            $this->stdOp('RLIKE', $property, $value);
	}

	public function in($property,$values)
	{
		if( \count($values) == 0 )
			return;
        $args = [];
        foreach( \is_array($values)?$values:[$values]  as $value )
            $args[] = new ConditionArgument($this->argName(), $value);
        $this->__conditionTree()->Add(new Condition('IN', $property, $args));
	}

	public function notIn($property,$values)
	{
		if( \count($values) == 0 )
			return;
        $args = [];
        foreach( \is_array($values)?$values:[$values]  as $value )
            $args[] = new ConditionArgument($this->argName(), $value);
        $this->__conditionTree()->Add(new Condition('NOT IN', $property, $args));
	}

	public function isNull($property)
	{
        $this->stdOp('IS', $property, null);
	}

	public function notNull($property)
	{
        $this->stdOp('IS NOT', $property, NULL);
	}

	public function newerThan($property,$value,$interval)
	{
        $this->stdOp('>', $property, new ConditionArgument($this->argName(), $value, "NOW() - INTERVAL ? $interval"));
	}

	public function olderThan($property,$value,$interval)
	{
        $this->stdOp('<', $property, new ConditionArgument($this->argName(), $value, "NOW() - INTERVAL ? $interval"));
	}

	public function noop()
	{
        $this->sql('1=1', []);
	}
}
