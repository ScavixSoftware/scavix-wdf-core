<?php
/**
 * Scavix Web Development Framework
 *
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
 * @copyright since 2019 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */

namespace ScavixWDF\Base;

use PhpToken;

/**
 * Implements persistable closures.
 *
 * @see http://www.htmlist.com/development/extending-php-5-3-closures-with-serialization-and-reflection/
 * @todo Check if this is still needed in times of PHP8.1
 */
class WdfClosure
{
	protected $closure = NULL;
	protected $reflection = NULL;
    public $context = NULL;
	public $code = NULL;
	public $used_variables = [];

	public function __construct($function=null)
    {
        if ( !$function instanceOf \Closure )
			throw new \InvalidArgumentException();

		$this->closure = $function;
		$this->reflection = new \ReflectionFunction($function);
        $this->context = $this->reflection->getClosureThis();
		$this->used_variables = $this->reflection->getClosureUsedVariables();
		$this->code = $this->_fetchCode();
    }

    public function __invoke(...$args)
    {
        if ($this->context === null)
            return ($this->closure)(...$args);
        return $this->closure->call($this->context, ...$args);
    }

	/**
	 * @internal
	 */
	public function getClosure()
	{
		return $this->closure;
	}

	protected function _fetchCode()
	{
        $content = file_get_contents($this->reflection->getFileName());
        $start = $this->reflection->getStartLine();
        $end = $this->reflection->getEndLine();
        $tokens = array_filter(array_map(function ($t) use ($start, $end)
        {
            if( $t->line < $start || $t->line > $end )
                return null;
            return $t;
        }, PhpToken::tokenize($content)));

        $start = 0;
        $end = 0;
        $brackets = ['{', '}'];
        $def_okay = true;
        $d = 0;
        foreach( $tokens as $t )
        {
            if( !$start && $t->is([T_FUNCTION]) )
                $start = $t->pos;
            elseif( !$start && $t->is([T_FN]) )
            {
                $start = $t->pos;
                $brackets = ['(', ')'];
                $def_okay = false;
            }
            elseif ($start)
            {
                $end = $t->pos + strlen("{$t->text}");
                if ($t->text == $brackets[0])
                    $d++;
                elseif ($t->text == $brackets[1])
                {
                    $d--;
                    if ($d < 1 && $def_okay)
                        break;
                }
                if ($t->text == "=>")
                    $def_okay = true;
            }
        }
        $code = substr($content, $start, $end - $start);
		return $code;
	}

	/**
	 * @internal
	 */
	public function getCode()
	{
		return $this->code;
	}

	/**
	 *  @internal
	 */
	public function getParameters()
	{
		return $this->reflection->getParameters();
	}

	/**
	 * @internal
	 */
	public function getUsedVariables()
	{
		return $this->used_variables;
	}

	public function __sleep()
	{
		return ['code', 'used_variables', 'context'];
	}

	public function __wakeup()
	{
		extract($this->used_variables ?? []);
        eval('$_function = '.$this->code.';');
		if (isset($_function) && ($_function instanceOf \Closure))
		{
			$this->reflection = new \ReflectionFunction($_function);
            $this->closure = $this->reflection->getClosure();
		}
		else
			throw new \Exception();
	}
}