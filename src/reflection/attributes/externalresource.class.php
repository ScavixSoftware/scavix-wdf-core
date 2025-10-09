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
use ScavixWDF\Reflection\ExternalResourceAttribute;

/**
 * Allows to require external resources for classes.
 *
 * Sample usage:
 * <code php>
 * #[ExternalResource('https://cdn.my.site/somelogic.js');
 * #[ExternalResource('https://cdn.my.site/sometheme.less');
 * class MyController extends Renderable
 * {
 * }
 * </code>
 */
#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class ExternalResource extends ExternalResourceAttribute
{
    function __construct($path)
    {
        parent::__construct($path);
    }
}
