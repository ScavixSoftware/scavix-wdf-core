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
 * Allows to grab data from the request headers and apply it to the method arguments.
 *
 * Sample usage:
 * <code php>
 * #[HeaderData('user_agent','{ua}')]
 * #[HeaderData('Authorization','Bearer: {token}')]
 * function MyMethod($ua, $token)
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class HeaderData extends WdfAttribute implements IRequestAttribute
{
    use RequestAttributeCommons;

    protected $header, $pattern;

    function __construct(string $header, string $pattern, ...$defaults)
    {
        $this->header = strtolower(str_replace('-', '_', $header));
        $this->pattern = $pattern;
        $this->setDefaults($defaults);
    }

    function getParsedData(WdfIncomingRequest $request)
    {
        $data = array_change_key_case($_SERVER, CASE_LOWER);
        if( function_exists('apache_request_headers') )
            $data += apache_request_headers();
        foreach ([$this->header, "http_{$this->header}"] as $this_header)
        {
            foreach ($data as $header => $value)
            {
                if (str_replace('-', '_', $header) == $this_header)
                {
                    $result = $this->getDataFromString($this->pattern, $value,'.');
                    if (\count($result))
                        return $result;
                }
            }
        }
        return [];
    }

    function applyDefaults(&$args, $is_last = false)
    {
        foreach ($this->defaults as $k => $v)
            if (!isset($args[$k]))
                $args[$k] = $v;
    }
}
