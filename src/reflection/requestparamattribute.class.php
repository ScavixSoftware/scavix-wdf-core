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
namespace ScavixWDF\Reflection;

use Attribute;
use ScavixWDF\IRequestAttribute;
use ScavixWDF\Localization\Localization;
use ScavixWDF\Reflection\Attributes\RequestParam;

/**
 * Allows to automatically pass REQUEST parameters to methods arguments.
 *
 * <at>attribute[RequestParam('joe','string',false)]
 * in the doccomment will make the following usable:
 * function SomeMethod($joe){ log_debug($joe); }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RequestParamAttribute extends WdfAttribute implements IRequestAttribute
{
    use \ScavixWDF\Reflection\Attributes\RequestAttributeCommons;

    public $Name = null;
    public $Type = null;
    public $Default = null;
    public $Filter = null;

    function __construct($name, $type = null, $default = null, $filter = null)
    {
        $this->Name = $name;
        $this->Type = $type;
        $this->Default = $default;
        $this->Filter = $filter;

        if (!is_null($this->Type) && is_null($this->Filter))
        {
            switch (strtolower($this->Type))
            {
                case 'string':
                    $this->Filter = FILTER_UNSAFE_RAW;
                    break;
            }
        }
    }

    /**
     * Checks if the argument is optional
     *
     * This is done by checking if there's a default value specified.
     * @return bool true or false
     */
    function IsOptional()
    {
        return isset($this->Default);
    }

    function applyDefaults(&$args, $is_last = false)
    {
        $name = $GLOBALS['CONFIG']['requestparam']['ignore_case'] ? strtolower($this->Name) : $this->Name;
        if (isset($args[$name]))
            return;
        if (!is_null($this->Default))
            $args[$this->Name] = $this->Default;
        elseif (is_in(get_class($this), RequestParamAttribute::class, RequestParam::class))
        {
            if (\ScavixWDF\Wdf::Request()->hasRouteArgs())
            {
                $argdata = \ScavixWDF\Wdf::Request()->shiftRouteArg($is_last ? '/' : '');
                log_warn("Skipped use of indexed path argument '{$this->Name}={$argdata}'. Use a PathData attribute instead!");
            }
        }
    }

    function getParsedData(\ScavixWDF\WdfIncomingRequest $request)
    {
        $name = $GLOBALS['CONFIG']['requestparam']['ignore_case'] ? strtolower($this->Name) : $this->Name;
        $request_data = $request->getInputArguments();

        if (!isset($request_data[$name]))
            return [];
        if (is_null($this->Type))
            return [$this->Name => $request_data[$name]];

        switch (strtolower($this->Type))
        {
            case 'object':
                if (!in_object_storage($request_data[$name]))
                    return $this->returnError('object not found');
                return [$this->Name => restore_object($request_data[$name])];
            case 'array':
            case 'file':
                if (isset($request_data[$name]) && is_array($request_data[$name]))
                    return [$this->Name => $request_data[$name]];
                else
                    $this->returnError('invalid array value: ' . $request_data[$name]);
            case 'string':
            case 'text':
                if (is_array($request_data[$name]))
                    return $this->returnError("invalid {$this->Type} value");
                if ($this->Filter)
                    return [$this->Name => preg_replace('/\x00|<[^>]*>?/', '', "{$request_data[$name]}")];      // see https://stackoverflow.com/questions/69207368/constant-filter-sanitize-string-is-deprecated
                else
                    return [$this->Name => $request_data[$name]];
            case 'email':
                return [$this->Name => filter_var($request_data[$name], FILTER_SANITIZE_EMAIL)];
            case 'url':
            case 'uri':
                return [$this->Name => filter_var($request_data[$name], FILTER_SANITIZE_URL)];
            case 'int':
            case 'integer':
                if (intval($request_data[$name]) . "" != $request_data[$name])
                    return $this->returnError('invalid int value: ' . $request_data[$name]);
                return [$this->Name => intval($request_data[$name])];
            case 'float':
            case 'double':
            case 'currency':

                static $ci = false;
                if ($ci === false)
                {
                    if (isset($GLOBALS['CONFIG']['requestparam']['ci_detection_func']) && function_exists($GLOBALS['CONFIG']['requestparam']['ci_detection_func']))
                        $ci = $GLOBALS['CONFIG']['requestparam']['ci_detection_func']();
                    else
                        $ci = Localization::detectCulture();
                }

                if (strtolower($this->Type) == 'currency')
                    $v = $ci->CurrencyFormat->StrToCurrencyValue($request_data[$name]);
                else
                    $v = $ci->NumberFormat->StrToNumber($request_data[$name]);

                if ($v === false)
                    return $this->returnError('invalid float value: ' . $request_data[$name]);
                else
                    return [$this->Name => $request_data[$name]];
            case 'bool':
            case 'boolean':
                switch( strtolower($request_data[$name]) )
                {
                    case '1': case 'true': case 'yes': case 'on':
                        return [$this->Name => true];
                    case '0': case 'false': case 'no': case 'off':
                        return [$this->Name => false];
                    default:
                        return $this->returnError('invalid boolean value: ' . $request_data[$name]);
                }
        }
        return [];
    }
}
