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

/**
 * Allows to grab input-data from the request and apply it to the method arguments.
 *
 * Sample usage:
 * <code php>
 * #[Integer('id',0)]
 * function MyMethod($id)
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class Integer extends RequestParam
{
    function __construct($name, $default = null, $path = '')
    {
        parent::__construct($name, 'integer', $default, null, $path);
    }
}
