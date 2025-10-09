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
 * Allows to grab data from the request path and apply it to the method arguments.
 *
 * Sample usage:
 * <code php>
 * #[PathData('/mycontroller/mymethod/{id}/{action}',action:'read')]
 * function MyMethod($id,$action)
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class PathData extends WdfAttribute implements IRequestAttribute
{
    use RequestAttributeCommons;

    protected $path;

    function __construct($path, ...$defaults)
    {
        $this->path = $path;
        $this->setDefaults($defaults);
    }

    function applyDefaults(&$args, $is_last = false)
    {
        foreach ($this->defaults as $k => $v)
            if (!isset($args[$k]))
                $args[$k] = $v;
    }

    function getParsedData(WdfIncomingRequest $request)
    {
        $path = $request->expandRoute($this->path);
        $req = $request->getRoute();
        $res = $this->getDataFromString($path, $req);
        if( empty($res) && count($this->detectedNames)==1 ) // if only one argument is requested assume it may receive all the rest of the path
            $res = $this->getDataFromString($path, $req,'.');
        return $res;
    }
}
