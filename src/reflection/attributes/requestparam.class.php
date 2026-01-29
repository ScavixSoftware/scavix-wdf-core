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
use ScavixWDF\Reflection\RequestParamAttribute;
use ScavixWDF\Wdf;

/**
 * Allows to grab input-data from the request and apply it to the method arguments.
 *
 * Sample usage:
 * <code php>
 * #[RequestParam('id','int','')]
 * function MyMethod($id)
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class RequestParam extends RequestParamAttribute
{
    protected $path_definition = '';

    function __construct(string $name, $type = null, $default = null, $filter = null, $path = '')
    {
        parent::__construct($name, $type, $default, $filter);
        $this->path_definition = $path;
    }

    function applyDefaults(&$args, $is_last = false)
    {
        $name = $GLOBALS['CONFIG']['requestparam']['ignore_case'] ? strtolower($this->Name) : $this->Name;
        if (isset($args[$name]))
            return;

        if( $this->path_definition )
        {
            $pd = new PathData($this->path_definition);
            foreach ($pd->getParsedData(Wdf::Request()) as $n => $v)
                if (!isset($args[$name]))
                {
                    $args[$name] = $v;
                    // log_debug(get_class($this),"got $name from path: $v");
                }
        }
        parent::applyDefaults($args, $is_last);
    }
}
