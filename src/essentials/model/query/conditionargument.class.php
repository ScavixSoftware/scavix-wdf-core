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
 * @internal Encapsulates a condition argument
 */
class ConditionArgument
{
    public $name, $value, $pattern;

    public function __construct(string $name, $value, $pattern = '')
    {
        $this->name = $name;
        $this->value = $value;
        $this->pattern = $pattern;
    }

    function __toString()
    {
        if ($this->pattern)
            return str_replace("?", $this->name, $this->pattern);
        return $this->name;
    }
}