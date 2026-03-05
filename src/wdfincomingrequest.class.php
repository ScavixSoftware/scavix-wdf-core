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
namespace ScavixWDF;

use ScavixWDF\Base\Renderable;
use ScavixWDF\IRequestAttribute;
use ScavixWDF\Reflection\RequestParamAttribute;
use ScavixWDF\Reflection\WdfReflector;
use ScavixWDF\Wdf;
use ScavixWDF\WdfResource;

class WdfIncomingRequest
{
    private static WdfIncomingRequest $_instance;
    private bool $parsingDone = false;
    private $_url, $_currentController, $_currentEvent, $_raw_data;
    private $_route, $_routeArgs, $_parsedArguments, $_usingDefaultPage, $_usingDefaultEvent;

    public static function &Get()
    {
        if (empty(self::$_instance))
            self::$_instance = new WdfIncomingRequest();

        if (!self::$_instance->parsingDone)
        {
            if (PHP_SAPI == 'cli' || session_active())
            {
                self::$_instance->parsingDone = true;
                self::$_instance->parseRequest();
                self::$_instance->parseArguments();
            }
        }
        return self::$_instance;
    }

    public function Invoke($pre_execute_hook_type)
    {
        $start = microtime(true);
        try
        {
            $this->instanciateController();
            if (!$this->eventExists())
                return '';

            execute_hooks($pre_execute_hook_type, [$this->_currentController, $this->_currentEvent, $this->_parsedArguments]);
            $ref = WdfReflector::GetInstance($this->_currentController);
            $meth = $ref->getMethod($this->_currentEvent);
            $args = [];
            foreach ($meth->getParameters() as $param)
            {
                $n = $param->getName();
                if (isset($this->_parsedArguments[$n]))
                    $args[$n] = $this->_parsedArguments[$n];
                elseif ($param->isVariadic())
                    $args += $this->_parsedArguments;
                else
                    $args[$n] = null;
            }
            return $meth->invokeArgs($this->_currentController, $args);
        }
        finally
        {
            Wdf::Measure(__METHOD__, $start);
        }
    }

    #region Getters

    function __get($name)
    {
        if( is_in($name,'URL','CurrentController','CurrentEvent','Route','RouteArgs','ParsedArguments','UsingDefaultPage','UsingDefaultEvent') )
        {
            if( isDev())
                log_warn("Deprecated access to Property '$name' from ".system_get_caller().". Use getter instead.");
            $n = "_".strtolower($name[0]).substr($name, 1);
            return $this->$n;
        }
    }

    function getRequestedCodePath()
    {
        $ref = WdfReflector::GetInstance($this->_currentController);
        $meth = $ref->getMethod($this->_currentEvent);
        return $meth->getFileName() . ":" . $meth->getStartLine();
    }

    function getEndpoint()
    {
        return $this->getController(true) . "/" . $this->getEvent();
    }

    function getUrl(bool $absolute=true)
    {
        $url = $this->samePage();
        if( $absolute )
            return $url;
        return "/" . ltrim(str_replace($GLOBALS['CONFIG']['system']['url_root'], '', $url), '/');
    }

    function getController(bool $as_string=true)
    {
        return $as_string ? strtolower(
            \is_object($this->_currentController)
            ? get_class_simple($this->_currentController)
            : "{$this->_currentController}"
        ) : $this->_currentController;
    }

    function getEvent()
    {
        return strtolower("{$this->_currentEvent}");
    }

    function getArgs()
    {
        return $this->_parsedArguments;
    }

    function getInputArguments()
    {
        if (empty($this->_raw_data))
        {
            $this->_raw_data = array_merge($_FILES, $_GET, $_POST);
            if ($GLOBALS['CONFIG']['requestparam']['ignore_case'])
                $this->_raw_data = array_change_key_case($this->_raw_data, CASE_LOWER);
        }
        return $this->_raw_data;
    }

    function getHeader($name)
    {
        return Wdf::GetBuffer(__METHOD__)->get($name,function($name)
        {
            $name = str_replace('-', '_', strtolower($name));
            $data = $_SERVER;
            if( function_exists('apache_request_headers') )
                $data += apache_request_headers();
            $data = array_change_key_case($data, CASE_LOWER);
            foreach (["$name", "http_{$name}", "http_x_{$name}"] as $this_header)
            {
                foreach ($data as $header => $value)
                {
                    if (str_replace('-', '_', $header) == $this_header)
                        return $value;
                }
            }
            return null;
        });
    }

    function isDefaultController()
    {
        return !!$this->_usingDefaultPage;
    }

    function isDefaultEvent()
    {
        return !!$this->_usingDefaultEvent;
    }

    function getRoute()
    {
        return implode("/", $this->_route ?? []);
    }

    function getMethod()
    {
        return strtolower(ifavail($_SERVER, 'REQUEST_METHOD') ?: '');
    }

    function isMethod(string $name)
    {
        return strcasecmp($name, $this->getMethod()) === 0;
    }

    function getRequestClass()
    {
        static $class = null;
        if ($class == null)
        {
            if (PHP_SAPI == "cli")
                $class = 'cli';
            elseif ($this->getHeader('Sec-Fetch-Mode') == 'navigate')
                $class = 'page';
            else
            {
                $dest = $this->getHeader('Sec-Fetch-Dest');
                switch( $dest )
                {
                    case 'image':
                    case 'style':
                    case 'script':
                    case 'audio':
                    case 'video':
                    case 'iframe':
                        $class = $dest;
                        break;
                    case 'empty':
                        $class ='ajax';
                        break;
                    default:
                        if (($accept = $this->getHeader('Accept')) && str_contains($accept, 'image/'))
                            $class = 'image';
                        elseif ($this->getHeader('requested_with') == 'xmlhttprequest')
                            $class = 'ajax';
                        else
                            $class = 'other';
                        break;
                }
            }
        }
        return "$class";
    }

    function isPageLoad()
    {
        return is_in($this->getRequestClass(), 'page', 'iframe');
    }

    function isAjax()
    {
        return $this->getRequestClass() == 'ajax';
    }

    function isStaticAsset()
    {
        return is_in($this->getRequestClass(), 'image', 'audio', 'video', 'style', 'script');
    }

    #endregion

    #region Tools

    function samePage($data='')
    {
        if( $this->_url )
        {
            if( !$data )
                return $this->_url;
            if( \is_array($data) )
                $data = http_build_query($data);
            return substr_until($this->_url, '?') . "?{$data}";
        }
        return buildQuery($this->getController(true), $this->getEvent(), $data);
    }

    /**
     * @deprecated Use PathData attribute to define arguments within URL-Path
     */
    function hasRouteArgs()
    {
        return !empty($this->_routeArgs);
    }

    /**
     * @deprecated Use PathData attribute to define arguments within URL-Path
     */
    function shiftRouteArg(string $glue='')
    {
        if( \is_array($this->_routeArgs) )
            return $glue ? implode($glue, $this->_routeArgs) : array_shift($this->_routeArgs);
        return null;
    }

    function expandRoute(string $route)
    {
        $c = $this->getController(true);
        $e = $this->getEvent();
        return str_replace(
            ['~~', '~'],
            ["$c/$e", $c],
            trim($route, '/')
        );
    }

    #endregion

    #region Internal logic

    private function __construct()
    {
        $this->_url = $GLOBALS['CONFIG']['system']['same_page'];
    }

    private function parseRequest()
    {
        if( isset($_REQUEST['wdf_route']) )
        {
            if( isset($GLOBALS['CONFIG']['wdf_route_parser']) )
            {
                $path = $GLOBALS['CONFIG']['wdf_route_parser'];
                $path = $path(explode("/",$_REQUEST['wdf_route']));
            }
            else
                $path = explode("/",$_REQUEST['wdf_route']);

            $this->_route = array_filter($path);
            $GLOBALS['wdf_route'] = $path; // compat
            unset($_REQUEST['wdf_route']);
            unset($_GET['wdf_route']);

            if( \count($path)>0 )
            {
                if( $path[0]=='~' ) $path[0] = cfg_get('system','default_page');
                $controller_name = fq_class_name($path[0]);
                if( class_exists($controller_name) || in_object_storage($path[0]) )
                {
                    $controller = $path[0];
                    if( \count($path)>1 )
                    {
                        $offset = 2;
                        if( in_object_storage($path[0]) || system_method_exists($controller_name,$path[1]) )
                            $event = $path[1];
                        else
                            $offset = 1;

                        if( \count($path)>$offset )
                        {
                            foreach( \array_slice($path,$offset) as $ra )
                                if( $ra !== '' )
                                {
                                    $this->_routeArgs[] = $ra;
                                    $GLOBALS['routing_args'][] = $ra; // compat
                                }
                        }
                    }
                }
            }
        }

        if( empty($controller) )
        {
            $controller = cfg_get('system', 'default_page');
            $this->_usingDefaultPage = true;
        }
        if( empty($event) )
        {
            $event = cfg_get('system', 'default_event');
            $this->_usingDefaultEvent = true;
        }

        $pattern = '/[^A-Za-z0-9\-_\\\\]/';
        $this->_currentController = substr(preg_replace($pattern, "", $controller), 0, 256);
        $this->_currentEvent = substr(preg_replace($pattern, "", $event), 0, 256);
    }

    private function parseArguments()
    {
        if (\is_array($this->_parsedArguments) )
            return;
        $cc = in_object_storage($this->_currentController)
            ? restore_object($this->_currentController)
            : fq_class_name($this->_currentController);

        $ref = WdfReflector::GetInstance($cc);
        $params = $ref->GetMethodAttributes($this->_currentEvent,[IRequestAttribute::class]);
        $args = [];

        // todo: check if the event is really callable with even if no attributes present
        if( \count($params) > 0 )
        {
            $last = 0; // last is used for deprecated indexed-path arguments
            foreach( $params as $i=> $prm )
            {
                if ($prm instanceof RequestParamAttribute)
                    $last = $i;
                foreach( $prm->getParsedData($this) as $k=>$v )
                    $args[$k] = $v;
                if (($err = $prm->getError()) && isDev())
                    log_warn("[Argument '{$prm}']:", $err);
            }
            foreach ($params as $i => $prm)
                $prm->applyDefaults($args, $i == $last);
        }
        $this->_parsedArguments = $args;
    }

    private function instanciateController()
    {
        if (!\is_string($this->_currentController))
            return;

        execute_hooks(HOOK_PRE_CONSTRUCT, [$this->_currentController, $this->_currentEvent]);
        $controller_name = fq_class_name($this->_currentController);

        if( in_object_storage($controller_name) )
            $this->_currentController = restore_object($controller_name);
        elseif( class_exists($controller_name) )
            $this->_currentController = new $controller_name();
        else
        {
            log_fatal("ACCESS DENIED: Unknown controller '$controller_name'", "REQ=", $_REQUEST);
            system_die_http(404);
        }

        if( $this->isAjax() )
        {
            if( !($this->_currentController instanceof Renderable) && !($this->_currentController instanceof WdfResource) )
            {
                log_fatal("ACCESS DENIED: '$controller_name' is no Renderable", "REQ=", $_REQUEST);
                die("__SESSION_TIMEOUT__");
            }
        }
        else if( !($this->_currentController instanceof ICallable) )
        {
            log_fatal("ACCESS DENIED: '$controller_name' is no ICallable", "REQ=", $_REQUEST);
            system_die_http(404);
        }


        if( system_method_exists($this->_currentController,'__translate_event') )
            $this->_currentEvent = \call_user_func([$this->_currentController,'__translate_event'],$this->_currentEvent);

        if( !$this->eventExists() )
            $this->_currentEvent = cfg_get('system','default_event');

        if( !isset($GLOBALS['wdf_route']) ) // compat
            $GLOBALS['wdf_route'] = [$this->_currentController, $this->_currentEvent]; // compat

        if( !isset($this->_route) )
            $this->_route = [$this->_currentController, $this->_currentEvent];
    }

    private function eventExists()
    {
        if (!\is_object($this->_currentController))
            return false;
        if (system_method_exists($this->_currentController, $this->_currentEvent))
            return true;
        return false;
    }

    #endregion
}