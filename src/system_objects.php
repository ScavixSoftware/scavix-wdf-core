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

    public static function Response()
    {
        return WdfResponse::Get();
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
            $fn = trim(substr_until(Wdf::Request()->getUrl(false), "?"), "/");
            $fn = rtrim($folder, "/") . "/" . str_replace("/", "_", $fn) . ".json";
            file_put_contents($fn, json_encode(self::GetMeasurements(), JSON_PRETTY_PRINT));
            umask($um);
        }
    }

    public static function PhpVersionIs(string $operator, string $version): bool
    {
        return version_compare(PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION, $version, $operator);
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

