<?php
/**
 * Scavix Web Development Framework
 *
 * Copyright (c) 2012-2019 Scavix Software Ltd. & Co. KG
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
 * @author Scavix Software Ltd. & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright 2012-2019 Scavix Software Ltd. & Co. KG
 * @author Scavix Software GmbH & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright since 2019 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF;

use Exception;
use ScavixWDF\Base\Renderable;
use ScavixWDF\Model\ResultSet;

if( !defined('FRAMEWORK_LOADED') || FRAMEWORK_LOADED != 'uSI7hcKMQgPaPKAQDXg5' ) die('');

define("TASK_PRIORITY_HIGHEST", 1);
define("TASK_PRIORITY_HIGH", 2);
define("TASK_PRIORITY_NORMAL", 3);
define("TASK_PRIORITY_LOW", 4);
define("TASK_PRIORITY_LOWEST", 5);

/**
 * WDF internal replacement for $GLOBALS usage.
 */
class Wdf
{
    public static $Logger = [];
    public static $Timer = [];
    public static $DataSources = [];
    public static $Hooks = [];
    public static $Modules = [];
    public static $ClassAliases = [];
    /**
     * @deprecated Use Wdf::Request() instead.
     */
    public static $Request;
    public static $ClientIP;
    public static $SessionHandler;
    public static $ObjectStore;
    public static $Translation;

    private static $once_buffer = [];

    /**
     * Helper to easily check if something was already done.
     *
     * @param mixed $id An ID value
     * @return bool True, if has already been called with $id, else false
     */
    public static function Once($id)
    {
        if( isset(self::$once_buffer[$id]) )
            return false;
        self::$once_buffer[$id] = true;
        return true;
    }

    protected static $buffers = [];
    protected static $locks = false;

    /**
     * Checks if there's a buffer present.
     *
     * @param string $name Buffer identifier
     * @return bool True if present, else false
     */
    public static function HasBuffer($name)
    {
        return isset(self::$buffers[$name]);
    }

    /**
     * Creates a buffer that can be used instead of $GLOBALS variable.
     * Optionally, buffers can be mapped to a SESSION variable.
     *
     * @param string $name Buffer identifier
     * @param array|callable|string $initial_data Array with initial data or callback returning this initial data or name of initial context
     * @return \ScavixWDF\WdfBuffer
     */
    public static function GetBuffer($name, $initial_data = [])
    {
        if (!isset(self::$buffers[$name]))
            self::$buffers[$name] = new WdfBuffer(is_string($initial_data) ? [] : $initial_data);
        if (is_string($initial_data))
            self::$buffers[$name]->switchContext($initial_data);
        return self::$buffers[$name];
    }

    private static function getFsLockFolder()
    {
        if (PHP_OS_FAMILY != "Linux")
            return '';
        static $buffered = null;
        if ($buffered === null)
        {
            $dir = '/run/lock/wdf-'.md5(__SCAVIXWDF__);
            $um = umask(0);
            @mkdir($dir, 0777, true);
            umask($um);
            $buffered = is_dir($dir) ? $dir : '';
        }
        return $buffered;
    }

    /**
     * Sets up a LOCK for a given name.
     *
     * On Linux system uses the /run/lock folder to create a lock file. If this
     * succeeds returns true. If not and a timeout is given will try for that amount
     * of seconds. If still fails trhows an exception if $exceptiononfailure is true or returns false.
     * In all other OS see <system_get_lock>().
     *
     * @param string $name Lock name
     * @param int $timeout Seconds to wait/retry (default 10)
     * @param bool $exceptiononfailure If true will throw an exception if lock cannot be created (default: true)
     * @return bool True on success, else false
     */
    public static function GetLock($name, $timeout = 10, $exceptiononfailure = true)
    {
        if ($dir = self::getFsLockFolder())
        {
            $lock = md5($name);
            $um = umask(0);
            $end = time() + $timeout;
            do
            {
                $fp = @fopen("$dir/$lock", "x+");
                if (!$fp)
                {
                    if ($timeout > 0)
                        usleep(100000);
                    continue;
                }
                fwrite($fp, getmypid());
                fflush($fp);
                fclose($fp);

                if (self::$locks === false)
                {
                    self::$locks = [];
                    register_shutdown_function(function ()
                    {
                        foreach (Wdf::$locks as $lock => $fp)
                            @unlink('/run/lock/wdf-' . md5(__SCAVIXWDF__) . '/' . $lock);
                    });
                }

                self::$locks[$lock] = $fp;
                umask($um);
                return true;
            }
            while (time() < $end);

            foreach (glob("$dir/???*") as $f)
            {
                if (!system_process_running(trim(@file_get_contents($f))))
                    @unlink($f);
            }
            umask($um);
            if (($timeout <= 0) || !$exceptiononfailure)
                return false;
            else
                WdfException::Raise("Timeout while awaiting the lock '$name'");
        }
        return system_get_lock($name, \ScavixWDF\Model\DataSource::Get(), $timeout);
    }

    /**
     * Releases a LOCK.
     *
     * @param string|array $name The LOCK name as single string or array of lock names to release
     * @return bool True if successful, else false
     */
    public static function ReleaseLock($name)
    {
        $locks = array_filter(force_array($name));
        $ret   = false;
        $dir = self::getFsLockFolder();
        foreach ($locks as $lockname)
        {
            if ($dir)
            {
                $lock = md5($lockname);
                if (isset(self::$locks[$lock]))
                {
                    @unlink("$dir/$lock");
                    unset(self::$locks[$lock]);
                    $ret = true;
                }
            }
            elseif (system_release_lock($lockname, \ScavixWDF\Model\DataSource::Get()))
                $ret = true;
        }
        return $ret;
    }

    public static function Request()
    {
        return WdfIncomingRequest::Get();
    }


    private static $timeouts = [], $nextTimeoutId = 1, $alarmHandlerInstalled = false;
    public static function SetTimeout(int $delay_seconds, callable $callback)
    {
        if (!function_exists('pcntl_async_signals'))
        {
            if (PHP_SAPI == 'cli')
                log_warn(__METHOD__, "pcntl_async_signals doesn't exist, cannot start timeout handling", system_get_caller());
            return;
        }

        $id = self::$nextTimeoutId++;
        self::$timeouts[$id] = [
            'due' => time() + $delay_seconds,
            'cb' => $callback
        ];
        if (!self::$alarmHandlerInstalled)
        {
            pcntl_async_signals(true);
            self::$alarmHandlerInstalled = pcntl_signal(SIGALRM, function ($signo)
            {
                foreach (self::$timeouts as $id => ['due' => $due, 'cb' => $cb])
                {
                    if (time() >= $due)
                    {
                        /** @var callable $cb */
                        $delay = $cb();
                        if ($delay > 0)
                            self::$timeouts[$id]['due'] = time() + $delay;
                        else
                            unset(self::$timeouts[$id]);
                    }
                }
                if (count(self::$timeouts))
                    pcntl_alarm(1);
                else
                {
                    pcntl_signal(SIGALRM, SIG_IGN);
                    self::$alarmHandlerInstalled = false;
                }
            });
            pcntl_alarm(1);
        }
        return $id;
    }

    public static function ClearTimeout($id)
    {
        if( isset(self::$timeouts[$id]) )
            unset(self::$timeouts[$id]);
    }

    private static $packages = [];

    public static function RegisterPackage($name, ?callable $init_function = null)
    {
        self::$packages[$name] = $init_function;
    }

    public static function InitPackages()
    {
        foreach (self::$packages as $name => $init_function)
        {
            try
            {
                if (is_callable($init_function))
                    $init_function();
            }
            catch(WdfException $ex)
            {
                log_warn("Package $name threw an error: " . $ex->getMessage() . " (" . $ex->getCaller() . ")");
            }
            execute_hooks(HOOK_POST_MODULE_INIT, [$name]);
            self::$Modules[$name] = true;
        }
    }

    private static $measurements = null, $first_measurement = null, $last_measurement = null;

    public static function StartMeasuring()
    {
        self::$measurements = [];
        self::$first_measurement = microtime(true);
    }

    public static function Measure($name, $started = null)
    {
        if (self::$measurements === null)
            return;
        if ($started === null)
            $started = self::$last_measurement ?? microtime(true);
        self::$last_measurement = $now = microtime(true);
        if( self::$first_measurement == null )
            self::$first_measurement = $now;
        if( isset(self::$measurements[$name]) )
        {
            self::$measurements[$name][0]++;
            self::$measurements[$name][1] += ($now-$started)*1000;
        }
        else
            self::$measurements[$name] = [1,($now-$started)*1000];
        return $now;
    }

    public static function GetMeasurements()
    {
        if (self::$measurements === null)
            return [];
        uasort(self::$measurements, function($a, $b) {
                return $b[1] <=> $a[1];
            });
        return array_map(function ($item)
        {
            $r = array_combine(['cnt', 'sum'], $item);
            $r['avg'] = $r['cnt'] == 0 ? 0 : ($r['sum'] / $r['cnt']);
            return $r;
        }, self::$measurements);
    }

    public static function DumpMeasurements($folder)
    {
        if (self::$first_measurement !== null && !empty(self::$measurements))
        {
            self::Measure(Wdf::Request()->getUrl(), self::$first_measurement);
            $um = umask(0);
            @mkdir($folder, 0777, true);
            $fn = rtrim($folder, "/") . "/" . str_replace("/", "_", self::Request()->getEndpoint()) . ".json";
            file_put_contents($fn, json_encode(self::GetMeasurements(), JSON_PRETTY_PRINT));
            umask($um);
        }
    }

    public static function PhpVersionIs(string $operator, string $version): bool
    {
        return version_compare(PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION, $version, $operator);
    }
}

class WdfIncomingRequest
{
    private static WdfIncomingRequest $_instance;
    private $_url, $_currentController, $_currentEvent, $_raw_data;
    private $_route, $_routeArgs, $_parsedArguments, $_usingDefaultPage, $_usingDefaultEvent;

    public static function &Get()
    {
        if (empty(self::$_instance))
        {
            self::$_instance = new WdfIncomingRequest();
            self::$_instance->parseRequest();
            self::$_instance->parseArguments();

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
            $ref = \ScavixWDF\Reflection\WdfReflector::GetInstance($this->_currentController);
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
        $ref = \ScavixWDF\Reflection\WdfReflector::GetInstance($this->_currentController);
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
            is_object($this->_currentController)
            ? get_class_simple($this->_currentController)
            : $this->_currentController
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
        return $this->_usingDefaultPage;
    }

    function isDefaultEvent()
    {
        return $this->_usingDefaultEvent;
    }

    function getRoute()
    {
        return implode("/", $this->_route);
    }

    function getMethod()
    {
        return strtolower(ifavail($_SERVER, 'REQUEST_METHOD'));
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
        return $class;
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
        return is_in($this->getRequestClass(), 'image', 'audio', 'video');
    }

    #endregion

    #region Tools

    function samePage($data='')
    {
        if( $this->_url )
        {
            if( !$data )
                return $this->_url;
            if( is_array($data) )
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
        return $glue ? implode($glue, $this->_routeArgs) : array_shift($this->_routeArgs);
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

            if( count($path)>0 )
            {
                if( $path[0]=='~' ) $path[0] = cfg_get('system','default_page');
                $controller_name = fq_class_name($path[0]);
                if( class_exists($controller_name) || in_object_storage($path[0]) )
                {
                    $controller = $path[0];
                    if( count($path)>1 )
                    {
                        $offset = 2;
                        if( in_object_storage($path[0]) || system_method_exists($controller_name,$path[1]) )
                            $event = $path[1];
                        else
                            $offset = 1;

                        if( count($path)>$offset )
                        {
                            foreach( array_slice($path,$offset) as $ra )
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
        if (is_array($this->_parsedArguments) )
            return;
        $cc = in_object_storage($this->_currentController)
            ? restore_object($this->_currentController)
            : fq_class_name($this->_currentController);

        $ref = \ScavixWDF\Reflection\WdfReflector::GetInstance($cc);
        $params = $ref->GetMethodAttributes($this->_currentEvent,[IRequestAttribute::class]);
        $args = [];

        // todo: check if the event is really callable with even if no attributes present
        if( count($params) > 0 )
        {
            $last = 0; // last is used for deprecated indexed-path arguments
            foreach( $params as $i=> $prm )
            {
                if ($prm instanceof \ScavixWDF\Reflection\RequestParamAttribute)
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
        if (!is_string($this->_currentController))
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
            $this->_currentEvent = call_user_func([$this->_currentController,'__translate_event'],$this->_currentEvent);

        if( !$this->eventExists() )
            $this->_currentEvent = cfg_get('system','default_event');

        if( !isset($GLOBALS['wdf_route']) ) // compat
            $GLOBALS['wdf_route'] = [$this->_currentController, $this->_currentEvent]; // compat

        if( !isset($this->_route) )
            $this->_route = [$this->_currentController, $this->_currentEvent];
    }

    private function eventExists()
    {
        if (!is_object($this->_currentController))
            return false;
        if (system_method_exists($this->_currentController, $this->_currentEvent))
            return true;
        return false;
    }

    #endregion
}

/**
 * Implements buffering methods.
 */
class WdfBuffer implements \Iterator, \JsonSerializable
{
    protected string $context = '';
    protected $changed = false;
    protected $data = [];
    protected $session_name = false;
    protected $position = 0;

    function __construct($initial_data=[])
    {
        if( is_callable($initial_data) )
            $this->data = $initial_data();
        else
            $this->data = is_array($initial_data)?$initial_data:[];
    }

    public function switchContext(string $context)
    {
        if ($context != $this->context)
            $this->clear();
        $this->context = $context;
        return $this;
    }

	/**
	 * @internal see <JsonSerializable>
	 */
    #[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
        return $this->dump();
    }

	/**
     * Maps this buffer to a $_SESSION variable.
	 *
	 * Mapping to the session means that from now on all data will be stored
	 * into $_SESSION[$name] and that getting data will transparently use that variable too.
     *
     * @param string $name Name of session variable
     * @return \ScavixWDF\WdfBuffer
     */
    function mapToSession($name=false)
    {
        if( !$this->session_name )
            $this->session_name = $name;
        return $this;
    }

	/**
     * Returns all data as assiciative array.
	 *
     * @return array
     */
    function dump()
    {
        if( $this->session_name && isset($_SESSION[$this->session_name]) )
            return array_merge($_SESSION[$this->session_name],$this->data);
        return $this->data;
    }

	/**
     * Returns true if some data has been changed.
	 *
	 * This is true, if <WdfBuffer::set> or <WdfBuffer::set> have been used
	 * and if they effectively did something.
	 *
     * @return bool
     */
    function hasChanged()
    {
        return $this->changed;
    }

	/**
     * Returns an array of data keys.
	 *
     * @return array
     */
    function keys()
    {
        $keys = array_keys($this->data);
        if( $this->session_name && isset($_SESSION[$this->session_name]) )
            $keys = array_unique(array_merge($keys,array_keys($_SESSION[$this->session_name])));
        return $keys;
    }

    /**
     * Returns true, if there's data stored with the given name.
	 *
	 * @param string $name The key for the data
     * @return bool
     */
    function has($name)
    {
        return isset($this->data[$name])
            || ($this->session_name && isset($_SESSION[$this->session_name][$name]));
    }

	/**
     * Stores data in the buffer.
	 *
	 * @param string $name The key for the data
	 * @param mixed $value The data to store
     * @return mixed The value given
     */
    function set($name, $value)
    {
        if( !$this->changed )
            $prev = $this->get($name,null);
        $this->data[$name] = $value;
        if( $this->session_name )
            $_SESSION[$this->session_name][$name] = $value;
        if( !$this->changed )
            $this->changed = ($prev !== $value);
        return $value;
    }

	/**
     * Removes data from the buffer.
	 *
	 * @param string $name The key for the data
     * @return mixed The removed value if present, else null
     */
    function del($name)
    {
        if( isset($this->data[$name]) )
        {
            $r = $this->data[$name];
            unset($this->data[$name]);
            $this->changed = true;
        }
        if( $this->session_name && isset($_SESSION[$this->session_name][$name]) )
        {
            unset($_SESSION[$this->session_name][$name]);
            $this->changed = true;
        }
        return isset($r)?$r:null;
    }

    /**
     * Removes all data from the buffer.
     *
     * @return void
     */
    function clear()
    {
        $this->changed = count($this->data)>0;
        $this->data = [];

        if( $this->session_name && isset($_SESSION[$this->session_name]) )
        {
            $this->changed |= count($_SESSION[$this->session_name])>0;
            $_SESSION[$this->session_name] = [];
        }
    }

	/**
     * Returns data from the buffer.
	 *
	 * @param string $name The key for the data
	 * @param mixed $default A default value, can be a callable too that will get the name and must return the value;
     * @return mixed The removed value if present, else null
     */
    function get($name, $default=null)
    {
        if( !isset($this->data[$name]) && $this->session_name && isset($_SESSION[$this->session_name][$name]) )
            $this->data[$name] = $_SESSION[$this->session_name][$name];
        if( isset($this->data[$name]) )
            return $this->data[$name];
        if( is_callable($default) )
            return $this->set($name,$default($name));
        return $default;
    }

    /**
     * @implements <Iterator::rewind>
     */
    public function rewind():void
    {
        $this->position = 0;
    }

    /**
	 * @implements <Iterator::current>
	 */
    #[\ReturnTypeWillChange]
    public function current():mixed
    {
        return $this->get($this->key());
    }

    /**
	 * @implements <Iterator::key>
	 */
    #[\ReturnTypeWillChange]
    public function key():mixed
    {
        return $this->keys()[$this->position];
    }

    /**
	 * @implements <Iterator::next>
	 */
    public function next():void
    {
        $this->position++;
    }

    /**
	 * @implements <Iterator::valid>
	 */
    public function valid():bool
    {
        return isset($this->keys()[$this->position]);
    }
}

/**
 * We use this to test access to controllers.
 * All controllers must implement this interface
 */
interface ICallable {}


/**
 * Defines an objects that handles log-string creation itself.
 */
interface ILogWritable
{
    public function __toLogString(): string;
}

interface IRequestAttribute
{
    function getParsedData(\ScavixWDF\WdfIncomingRequest $request);
    function getError(): string;
    function applyDefaults(&$args, $is_last = false);
}


/**
 * Transparently wraps Exceptions thus providing a way to catch them easily while still having the original
 * Exception information.
 *
 * Using static <WdfException::Raise>() method you can pass in multiple arguments. ScavixWDF will try to detect
 * if there's an exception object given and use it (the first one detected) as inner exception object.
 * <code php>
 * WdfException::Raise('My simple test');
 * WdfException::Raise('My simple test2',$obj_to_debug_1,'and',$obj_to_debug_2);
 * try{ $i=42/0; }catch(Exception $ex){ WdfException::Raise('That was stupid!',$ex); }
 * <code>
 */
class WdfException extends Exception
{
    use WdfThrowable;
}

trait WdfThrowable
{
    public $details = '';
    private $caller = null;

	private function ex()
	{
		$inner = $this->getPrevious();
		return $inner?$inner:$this;
	}

	/**
	 * Use this to throw exceptions the easy way.
	 *
	 * Can be used from derivered classes too like this:
	 * <code php>
	 * ToDoException::Raise('implement myclass->mymethod()');
	 * </code>
     * @param mixed ...$args Messages to be concatenated
	 * @return void
     * @suppress PHP1402
	 */
	public static function Raise(...$args)
	{
        [$message, $msgs, $inner_exception] = self::_prepareArgs(...$args);

        /**
         * @var WdfException $classname
         */
		$classname = get_called_class();
        if ($inner_exception)
        {
            $code = $inner_exception->getCode();
            if (!is_numeric($code))
            {
                $message = "[$code] $message";
                $code = 0;
            }
            $ex = new $classname($message, intval($code), $inner_exception);
        }
		else
			$ex = new $classname($message);

        $ex->details = implode("\t",$msgs);
        $ex->caller = system_get_caller();
        throw $ex;
	}

    protected static function _prepareArgs(...$args)
    {
        $msgs = [];
		$inner_exception = false;
		foreach( $args as $m )
		{
			if( !$inner_exception && ($m instanceof Exception) )
				$inner_exception = $m;
			else
				$msgs[] = logging_render_var($m);
		}
        $message = array_shift($msgs);
        if (!$message)
            $message = $inner_exception ? $inner_exception->getMessage() : "";
        return [$message, $msgs, $inner_exception];
    }

	/**
	 * Use this to easily log an exception the nice way.
	 *
	 * Ensures that all your exceptions are logged the same way, so they are easily readable.
	 * sample:
	 * <code php>
	 * try{
	 *  some code
	 * }catch(Exception $ex){ WdfException::Log("Weird:",$ex); }
	 * </code>
	 * Note that Raise method will log automatically, so this is mainly useful when silently catching exceptions.
     * @param mixed ...$args Messages to be concatenated
	 * @return void
	 */
	public static function Log(...$args)
	{
		call_user_func_array('log_error', $args);
	}

	/**
	 * Returns exception message.
	 *
	 * Check if there's an inner exception and combines this and that messages into one if so.
	 * @return string Combined message
	 */
	public function getMessageEx()
	{
		$inner = $this->getPrevious();
		return $this->getMessage().($inner?"\nOriginal message: ".$inner->getMessage():'');
	}

	/**
	 * Calls this or the inner exceptions getFile() method.
	 *
	 * See http://www.php.net/manual/en/exception.getfile.php
	 * @return string Returns the filename in which the exception was created
	 */
	public function getFileEx(){ return $this->ex()->getFile(); }

	/**
	 * Calls this or the inner exceptions getCode() method.
	 *
	 * See http://www.php.net/manual/en/exception.getcode.php
	 * @return string Returns the exception code as integer
	 */
	public function getCodeEx(){ return $this->ex()->getCode(); }

	/**
	 * Calls this or the inner exceptions getLine() method.
	 *
	 * See http://www.php.net/manual/en/exception.getline.php
	 * @return string Returns the line number where the exception was created
	 */
	public function getLineEx(){ return $this->ex()->getLine(); }

	/**
	 * Calls this or the inner exceptions getTrace() method.
	 *
	 * See http://www.php.net/manual/en/exception.gettrace.php
	 * @return array Returns the Exception stack trace as an array
	 */
	public function getTraceEx(){ return $this->ex()->getTrace(); }

    public function getCaller(){ return $this->caller; }
}

/**
 * Thrown when something still needs investigation
 *
 * We use this like this: `ToDoException::Raise('Not yet implemented')`
 */
class ToDoException extends WdfException {}

/**
 * Thrown from all database related system parts
 *
 * All code in the model essential (essentials/model.php + essentials/model/*) use this instead of WdfException.
 * Just to have everyting nicely wrapped.
 */
class WdfDbException extends WdfException
{
    public static $DISABLE_LOGGING = false;

    private $statement;

    private static function _prepare(string $message, ?Model\ResultSet $statement = null)
    {
        if( isDev() )
            $msg = "SQL Error: $message";
        else
            $msg = "SQL Error occured";

        if( $statement )
        {
            $trim_sql = function($s)
            {
                $lines = explode("\n",$s);
                foreach( ["\t"," "] as $ws )
                {
                    $pre = [];
                    foreach( $lines as $l )
                        $pre[] = strspn($l,$ws)?:999;
                    $min = min($pre);
                    if( $min == 999 )
                        continue;
                    foreach( $lines as $i=>&$l )
                        $l = preg_replace("/^{$ws}{{$min}}/","",$l);
                    break;
                }
                return implode("\n",$lines);
            };

            $sql = $trim_sql($statement->GetSql());
            $args = $statement->GetArgs();
            $msql = $trim_sql($statement->GetMergedSql());

            $details = "Message: $message\nSQL: $sql";
            if( $args && count($args) )
                $details .= "\nArguments: ".json_encode($args);
            if( $msql != $sql )
                $details .= "\nMerged: $msql";

            return [$msg, $details];
        }


        return [$msg, ''];
    }

    /**
     * @internal Wraps a \PDOException, optionally with the triggering statement
     */
    public static function RaisePdoEx(\PDOException $ex, ?Model\ResultSet $statement = null)
    {
        list($msg, $details) = self::_prepare($ex->getMessage(),$statement);
        $res = new WdfDbException($msg);
        $res->details = $details;
        $res->statement = $statement;
        throw $res;

    }

    /**
     * @internal Raises an Exception for a failed DB Statement.
     */
    public static function RaiseStatement($statement)
	{
        if(!($statement instanceof ResultSet))
            $statement = new ResultSet($statement->_ds, $statement);

        list($msg, $details) = self::_prepare(json_encode($statement->ErrorInfo()),$statement);
        $ex = new WdfDbException($msg);
        $ex->details = $details;
        $ex->statement = $statement;
		throw $ex;
	}

    /**
     * Returns the SQL string used
     *
     * @return string SQL
     */
    function getSql()
    {
        if( $this->statement )
            return $this->statement->GetSql();
        return '(undefined)';
    }

    /**
     * Returns the arguments used
     *
     * @return array The arguments
     */
    function getArguments()
    {
        if( $this->statement )
            return $this->statement->GetArgs();
        return [];
    }

    /**
     * Returns a string merged from the SQL statement and the arguments
     *
     * @return string Merged SQL statement
     */
    function getMergedSql()
    {
        if( $this->statement )
            return $this->statement->GetMergedSql();
        return '(undefined)';
    }

    /**
     * Returns an array with error information
     *
     * @return array
     */
    function getErrorInfo()
    {
        if( $this->statement )
            return $this->statement->ErrorInfo();
        return ['','',"Error preparing the SQL statement. Most likely there's an error in the SQL syntax."];
    }

    /**
     * @internal Helper method to detect if this represents an Exception indicating a duplicate key
     */
    function isDuplicateKeyException($key = false)
    {
        list($c1,$c2,$msg) = $this->getErrorInfo();
        $regex = "/Duplicate entry '.*' for key".($key ? " '".$key."'" : '')."/i";
        return (preg_match($regex, $msg, $dummy) != false);
    }

    /**
     * @internal Helper method to detect if this represents an Exception indicating a missing table.
     */
    function isTableNotExistException($table = false)
    {
        list($c1,$c2,$msg) = $this->getErrorInfo();
        $regex = "/Table '.*".($table ? $table : '')."' doesn't exist/i";
        return (preg_match($regex, $msg, $dummy) != false);
    }
}

/**
 * Thrown when some process reached a state where graceful but immidiate termination is required.
 *
 * We use this like this: `TerminationException::WithCode('HTTP_500','Server responded with 500, cannot do that now')`
 */
class TerminationException extends \Error
{
    use WdfThrowable;
    private $verbose, $reason;

    private static function _make(string $reason, bool $verbose, ...$args)
    {
        [$message, $msgs, $inner_exception] = self::_prepareArgs(...$args);
        $message = $message?"$reason: $message":$reason;
        if( $inner_exception )
			$ex = new TerminationException($message,$inner_exception->getCode(),$inner_exception);
		else
			$ex = new TerminationException($message);

        $ex->details = implode("\t",$msgs);
        $ex->verbose = $verbose;
        $ex->reason = $reason;
        return $ex;
    }

    /**
     * Raises a silent TerminationException.
     *
     * @param string $reason The reason for the Exception
     * @param array $args Additional arguments to be logged.
     * @return void
     */
    static function Silent(string $reason, ...$args)
    {
        throw self::_make($reason, isDev(), ...$args);
    }

    /**
     * Raises a verbose TerminationException.
     *
     * @param string $reason The reason for the Exception
     * @param array $args Additional arguments to be logged.
     * @return void
     */
    static function Verbose(string $reason, ...$args)
    {
        throw self::_make($reason, true, ...$args);
    }

    /**
     * Returns the reason string.
     *
     * @return string The reason this exception was thrown of.
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * Writes the exception to the log if it is verbose.
     * @return void
     */
    public function writeLog()
    {
        if (!$this->verbose)
            return;
        log_debug($this->getMessageEx());
    }
}

