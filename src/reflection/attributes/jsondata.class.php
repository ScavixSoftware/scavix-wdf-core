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
use ScavixWDF\WdfIncomingRequest;

/**
 * Allows to grab JSON data from the request and apply it to the method arguments.
 *
 * Sample usage:
 * <code php>
 * #[JsonData('first_result_id','results.0.id')]
 * function MyMethod($first_result_id)
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class JsonData extends WdfAttribute implements IRequestAttribute
{
    use RequestAttributeCommons;

    protected $name, $path, $default;

    function __construct($name, $path = '', $default = null)
    {
        // log_debug("JsonData: $name, path: $path, default: ". var_export($default, true));
        $this->name = $name;
        $this->path = $path;
        $this->default = $default;
    }

    function applyDefaults(&$args, $is_last = false)
    {
        if (!isset($args[$this->name]))
            $args[$this->name] = $this->default;
    }

    function getParsedData(WdfIncomingRequest $request)
    {
        $res = [];
        $raw = file_get_contents('php://input');
        $data = @json_decode($raw, true);
        if (!$data)
            return $res;

        if ($this->path)
        {
            // log_debug($data);
            if (is_array($data))
                $data = array_change_key_case($data, CASE_LOWER);
            $path = explode('.', strtolower($this->path));
            while (count($path) && isset($data[$path[0]]))
            {
                $data = $data[$path[0]];
                if (is_array($data))
                    $data = array_change_key_case($data, CASE_LOWER);
                array_shift($path);
                if (count($path) == 0)
                    $res[$this->name] = is_string($data) ? trim($data) : $data;
            }
        }
        else
            $res[$this->name] = $data;
        return $res;
    }
}
