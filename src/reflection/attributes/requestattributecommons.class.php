<?php
/**
 * Scavix Web Development Framework
 *
 * Copyright (c) since 2025 Scavix Software GmbH & Co. KG
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
 * @copyright since 2025 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF\Reflection\Attributes;

use Attribute;
use ScavixWDF\IRequestAttribute;
use ScavixWDF\Reflection\WdfAttribute;

/**
 * Holds some common methods useful in RequestAttribute classes.
 */
trait RequestAttributeCommons
{
    protected $_error = '', $defaults = [], $regex = false, $detectedNames = false;

    function getError(): string
    {
        return $this->_error;
    }

    protected function returnError($message): array
    {
        $this->_error = $message;
        return [];
    }

    protected function setDefaults(array $defaults)
    {
        if (count(array_filter(array_keys($defaults), 'is_numeric')))
            log_warn("[{$this->path}] Invalid defaults, please use named arguments: ", $defaults);
        else
            $this->defaults = $defaults;
    }

    function __tostring()
    {
        return ifavail($this, 'Name', 'path', 'header');
    }

    function getDataFromString(string $path_definition, string $data_string, string $valid_chars_regex='[^\/]'): array
    {
        $this->detectedNames = [];
        $parts = [];
        foreach (preg_split("/({[a-z0-9_]+})/i", $path_definition, -1, PREG_SPLIT_DELIM_CAPTURE) as $part)
        {
            if (!empty($part) && $part[0] == '{')
            {
                $n = substr($part, 1, -1);
                if ($n == '_')
                    $parts[] = ".*";
                else
                {
                    $this->detectedNames[$n] = $n;
                    $parts[] = "(?'$n'$valid_chars_regex+?)";
                }
            }
            else
                $parts[] = preg_quote($part, "/");
        }
        $this->regex = "/^" . implode("", $parts) . "$/i";

        $defaults = array_intersect_key($this->defaults, $this->detectedNames);
        $diff = array_diff_key($this->defaults, $defaults);
        if (count($diff))
        {
            $this->_error = "wrong default values: " . json_encode($diff);
            $this->defaults = $defaults;
        }

        // log_debug("regex: ",$this->regex,"datastring $data_string");
        if (preg_match($this->regex, $data_string, $matches) === false)
            return [];
        return array_intersect_key($matches, $this->detectedNames);
    }
}
