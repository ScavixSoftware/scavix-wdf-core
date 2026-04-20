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
use ScavixWDF\Session\WdfSession;
use ScavixWDF\Wdf;
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

	if( !isset($CONFIG['session']['prefix']) )
		$CONFIG['session']['prefix'] = '';

	if( !isset($CONFIG['session']['object_store']))
		$CONFIG['session']['object_store'] = \ScavixWDF\Session\SessionStore::class;

	if( !isset($CONFIG['session']['ping_time']) )
		$CONFIG['session']['ping_time'] = 60;

    $dep = array_intersect(array_keys($CONFIG['session']), ['datasource','table','lifetime','iplock','handler','usephpsession']);
    if (count($dep) > 0)
        log_info("Deprecated config found. These keys may safely be removed: " . implode(", ", array_map(fn($d) => "sesison." . $d, $dep)));
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

	$CONFIG['session']['object_store'] = fq_class_name($CONFIG['session']['object_store']);

	if (!isset($_SESSION["system_internal_cache"]))
		$_SESSION["system_internal_cache"] = [];

    WdfSession::Get(); // explicitely start the session
    $CONFIG['session']['started'] = microtime(true);
}

function session_active()
{
    return !empty($GLOBALS['CONFIG']['session']['started']);
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
 * @deprecated Has never been used and is not useful at all. Just compare using "==="
 * @suppress PHP0416
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
 * @shortcut <WdfSession::Sanitize>
 */
function session_sanitize()
{
    return WdfSession::Get()->Sanitize();
}

/**
 * @deprecated Use <session_clear> instead
 */
function session_kill_all()
{
    session_clear();
}

/**
 * @shortcut <WdfSession::Clear>
 */
function session_clear()
{
    WdfSession::Get()->Clear();
}

/**
 * @deprecated This is useless now and just does nothing
 */
function session_keep_alive($request_key='PING')
{}

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

        if( !(Wdf::Request()->getController(false) instanceof WdfResource) )
            WdfSession::Get()->Update($keep_alive_only);
        $partitionCookies();
    }
    finally
    {
        Wdf::Measure(__FUNCTION__, $start);
    }
}

/**
 * @shortcut <WdfSession::RequestId>
 */
function request_id(bool $regenerate = false)
{
    return WdfSession::Get()->GetRequestId($regenerate);
}

/**
 * @shortcut <WdfSession::StoreObject>
 */
function store_object(&$obj,$id="")
{
    return WdfSession::Get()->StoreObject($obj, $id);
}

/**
 * @shortcut <WdfSession::DeleteObject>
 */
function delete_object($id)
{
    return WdfSession::Get()->DeleteObject($id);
}

/**
 * @shortcut <WdfSession::ObjectExists>
 */
function in_object_storage($id)
{
    return WdfSession::Get()->ObjectExists($id);
}

/**
 * @shortcut <WdfSession::RestoreObject>
 */
function restore_object($id)
{
    return WdfSession::Get()->RestoreObject($id);
}

/**
 * @shortcut <WdfSession::CreateObjectId>
 */
function create_storage_id(&$obj)
{
    return WdfSession::Get()->CreateObjectId($obj);
}

/**
 * @shortcut <WdfSession::RegenerateId>
 */
function regenerate_session_id()
{
    return WdfSession::Get()->RegenerateSessionId();
}

/**
 * @shortcut <WdfSession::GenerateSessionId>
 */
function generate_session_id()
{
    return WdfSession::Get()->GenerateSessionId();
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
