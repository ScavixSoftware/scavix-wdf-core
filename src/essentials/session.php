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

use ScavixWDF\Session\Serializer;
use ScavixWDF\Wdf;
use ScavixWDF\WdfException;
use ScavixWDF\WdfResource;

require_once(__DIR__.'/session/serializer.class.php');

/**
 * Initializes the session essential.
 *
 * @return void
 */
function session_init()
{
	global $CONFIG;

    classpath_add(__DIR__ . '/session', true, 'system');

	if( !isset($CONFIG['session']['session_name']) )
		$CONFIG['session']['session_name'] = isset($CONFIG['system']['application_name'])?$CONFIG['system']['application_name']:'WDF_SESSION';

	if( !isset($CONFIG['session']['datasource']) )
		$CONFIG['session']['datasource'] = 'internal';

	if( !isset($CONFIG['session']['table']) )
		$CONFIG['session']['table'] = 'sessions';

	if( !isset($CONFIG['session']['prefix']) )
		$CONFIG['session']['prefix'] = '';

	if( !isset($CONFIG['session']['lifetime']) )
		$CONFIG['session']['lifetime'] = '10';

	// Bind sessions to one ip address
	if( !isset($CONFIG['session']['iplock']))
		$CONFIG['session']['iplock'] = false;

	// Classname of the Session Handler
	if( !isset($CONFIG['session']['handler']))
		$CONFIG['session']['handler'] = 'PhpSession';

	if( !isset($CONFIG['session']['object_store']))
		$CONFIG['session']['object_store'] = 'SessionStore';
}

/**
 * @internal Starts the session handler.
 */
function session_run()
{
	global $CONFIG;

    if (ini_get('session.use_cookies'))
    {
        // Force SameSite=none in session cookies, will be overwritten in system_exit with 'partitioned' for php<8.5
        $cookie_params = session_get_cookie_params();
        if (isset($cookie_params['samesite']))
        {
            $cookie_params['samesite'] = 'none';
            $cookie_params['httponly'] = true;
            if (isSSL())
            {
                $cookie_params['secure'] = true;
                if ('iframe' == strtolower(ifavail($_SERVER, 'SEC_FETCH_DEST', 'HTTP_SEC_FETCH_DEST', 'REDIRECT_HTTP_SEC_FETCH_DEST') ?: ''))
                    if (Wdf::PhpVersionIs(">=", '8.5'))
                        $cookie_params['partitioned'] = true;
            }
            else
                unset($cookie_params['samesite']);
            session_set_cookie_params($cookie_params);
        }
    }

	// check for backwards compatibility
	if( isset($CONFIG['session']['usephpsession']))
	{
		if( ($CONFIG['session']['usephpsession'] && $CONFIG['session']['handler'] != "PhpSession") ||
			(!$CONFIG['session']['usephpsession'] && $CONFIG['session']['handler'] == "PhpSession") )
			WdfException::Raise('Do not use $CONFIG[\'session\'][\'usephpsession\'] anymore! See session_init() for details.');
	}

	$CONFIG['session']['handler'] = fq_class_name($CONFIG['session']['handler']);
	$CONFIG['session']['object_store'] = fq_class_name($CONFIG['session']['object_store']);

    Wdf::$SessionHandler = new $CONFIG['session']['handler']();
    Wdf::$SessionHandler->store = Wdf::$ObjectStore = new $CONFIG['session']['object_store']();

    if( !isset($_SESSION[$GLOBALS['CONFIG']['session']['prefix']."object_access"]) )
        $_SESSION[$GLOBALS['CONFIG']['session']['prefix']."object_access"] = [];

	if (!isset($_SESSION["system_internal_cache"]))
		$_SESSION["system_internal_cache"] = [];
}

/**
 * Checks if the unserializer is doing something.
 *
 * @deprecated Use Serializer::isUnserializing() instead.
 * @return bool true if running, else false
 */
function unserializer_active()
{
    return Serializer::isUnserializing();
}

/**
 * Tests two objects for equality.
 *
 * Checks reference-equality or storage_id equality (if storage_id is set)
 * @param object $o1 First object to compare
 * @param object $o2 Second object to compare
 * @param bool $compare_classes Seems to be deprecated
 * @return bool true if eual, else false
 */
function equals(&$o1, &$o2, $compare_classes = true)
{
    if ($o1 === $o2)
        return true;

    if ($compare_classes)
    {
        $iso1 = is_object($o1);
        $iso2 = is_object($o2);
        if ((!$iso1 && $iso2) || ($iso1 && !$iso2))
            return false;
        if (!$iso1 && !$iso2)
            return ($o1 === $o2);
    }

    if (($o1 instanceof Closure) || !($o2 instanceof Closure))
        return false;
    if (!($o1 instanceof Closure) && ($o2 instanceof Closure))
        return false;
    if (($o1 instanceof Closure) && ($o2 instanceof Closure) && $o1 == $o2)
        return true;

    return
        ($o1->_storage_id ?? '-1')
        ==
        ($o2->_storage_id ?? '-2');
}

/**
 * @shortcut <SessionBase::Sanitize>
 */
function session_sanitize()
{
    if (isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler))
	    return Wdf::$SessionHandler->Sanitize();
}

/**
 * Truncates the session.
 *
 * @return void
 */
function session_kill_all()
{
    if (isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler))
	    Wdf::$SessionHandler->KillAll();
}

/**
 * @internal Keeps session alive
 */
function session_keep_alive($request_key='PING')
{
    if (isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler))
	    return Wdf::$SessionHandler->KeepAlive($request_key);
}

/**
 * @internal Keeps used stored objects alive
 */
function session_update($keep_alive_only = false)
{
    static $session_update_done = false;
    if ($session_update_done)
        return;
    $session_update_done = true;
    $start = microtime(true);
    try
    {
        $partitionCookies = function ()
        {
            if (headers_sent() || Wdf::Request()->getRequestClass() != 'iframe')
                return;
            $replace_headers = true;
            foreach (headers_list() as $header)
            {
                if (!starts_iwith($header, 'set-cookie:'))
                    continue;
                if (stripos($header, "partitioned") === false)
                    $header .= ends_with($header, ";") ? " Partitioned" : "; Partitioned";
                header("$header", $replace_headers);
                $replace_headers = false;
            }
        };

        if (current_controller(false) instanceof WdfResource)
        {
            $partitionCookies();
            return;
        }

        if (Wdf::$ObjectStore)
        {
            if( $keep_alive_only )
                Wdf::$ObjectStore->Update(true);
            else
            {
                if (Wdf::Request()->isPageLoad())
                {
                    Wdf::$ObjectStore->Cleanup();
                    Wdf::$ObjectStore->Update();
                }
                elseif( !Wdf::Request()->isStaticAsset() )
                    Wdf::$ObjectStore->Update();
            }
        }
        if (isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler))
        {
            $res = Wdf::$SessionHandler->Update();
            $partitionCookies();
            return $res;
        }
        $partitionCookies();
        return false;
    }
    finally
    {
        Wdf::Measure(__FUNCTION__, $start);
    }
}

/**
 * @shortcut <SessionBase::RequestId>
 */
function request_id()
{
    if (isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler))
        return Wdf::$SessionHandler->RequestId();
    return md5("".microtime(true));
}

/**
 * @shortcut <SessionBase::Store>
 */
function store_object(&$obj,$id="")
{
    if( !isset(Wdf::$ObjectStore) )
		return false;
	$res = Wdf::$ObjectStore->Store($obj,$id);
    return $res;
}

/**
 * @shortcut <SessionBase::Delete>
 */
function delete_object($id)
{
    if( !isset(Wdf::$ObjectStore) )
		return false;
	return Wdf::$ObjectStore->Delete($id);
}

/**
 * @shortcut <SessionBase::Exists>
 */
function in_object_storage($id)
{
	if( !isset(Wdf::$ObjectStore) )
		return false;
	return Wdf::$ObjectStore->Exists($id);
}

/**
 * @shortcut <SessionBase::Restore>
 */
function &restore_object($id)
{
	$res = Wdf::$ObjectStore->Restore($id);
    return $res;
}

/**
 * @shortcut <SessionBase::CreateId>
 */
function create_storage_id(&$obj)
{
	if( isset(Wdf::$ObjectStore) && is_object(Wdf::$ObjectStore) )
		return Wdf::$ObjectStore->CreateId($obj);
	return false;
}

/**
 * @shortcut <SessionBase::RegenerateId>
 */
function regenerate_session_id()
{
    if( isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler) )
	    return Wdf::$SessionHandler->RegenerateId();
    return false;
}

/**
 * @shortcut <SessionBase::GenerateSessionId>
 */
function generate_session_id()
{
    if( isset(Wdf::$SessionHandler) && is_object(Wdf::$SessionHandler) )
	    return Wdf::$SessionHandler->GenerateSessionId();
    return md5(time() . random_int(10000, 99999));
}

/**
 * @shortcut <Serializer::Serialize>
 */
function session_serialize($value)
{
    try
    {
        if(class_exists('ScavixWDF\Session\Serializer'))
            return Serializer::Get()->Serialize($value);
    } catch(Exception $exc)
    {
        error_log($exc->getTraceAsString());
    }
    return false;
}

/**
 * @shortcut <Serializer::Unserialize>
 */
function session_unserialize($value)
{
	return Serializer::Get()->Unserialize($value);
}

/**
 * Checks if there's need to use GET arguments for session (because of missing cookies), for example if running in an iframe.
 *
 * @deprecated This is very insecure. As we support partitioned cookies this should never be needed/used. Returns false always.
 * @return false
 */
function session_needs_url_arguments()
{
    return false;
}
