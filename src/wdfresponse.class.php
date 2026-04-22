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
use ScavixWDF\Base\Template;
use ScavixWDF\Reflection\ResourceAttribute;
use ScavixWDF\Wdf;

class WdfResponse
{
    private static WdfResponse $_instance;
    protected $skipResources = false;

    public static function &Get()
    {
        if (empty(self::$_instance))
        {
            self::$_instance = new WdfResponse();
            self::$_instance->resource_cache_prefix = 'resource_map/' . session_name() . "/" . getAppVersion('nc');
        }
        return self::$_instance;
    }

    #region Resource handling

    protected string $resource_cache_prefix;
    protected array $resource_map = [];
    protected $prepared_resources = false;

    protected function resMapRestore($name)
    {
        if (!empty($this->resource_map[$name]))
            return true;

        if ($data = cache_get("{$this->resource_cache_prefix}/$name", false, true, false))
        {
            // if any file have been modified, invalidate this cache entry
            foreach( $data as $item )
            {
                $mtime = filemtime($item['path'] ?? '');
                if( $mtime > ($item['mtime'] ?? 0) )
                    return false;
            };
            if (count($data) > 0)
            {
                $this->resource_map[$name] = ['items' => $data, 'saved' => true];
                return true;
            }
        }
        return false;
    }

    protected function resMapAdd($name, $data)
    {
        if (empty($data))
            return;
        if (!is_array($data))
            $data = [$data];
        if (empty($this->resource_map[$name]))
            $this->resource_map[$name] = ['items' => [], 'saved' => false];
        foreach ($data as $item)
        {
            if (($key = $item['key'] ?? '') && empty($this->resource_map[$name]['items'][$key]))
            {
                $this->resource_map[$name]['items'][$key] = $item;
                // log_debug("$name added $key");
            }
        }
        $this->resource_map[$name]['saved'] = false;
        $this->prepared_resources = false;
    }

    protected function resMapStore($name)
    {
        if (!empty($this->resource_map[$name]['items']) && !$this->resource_map[$name]['saved'])
            cache_set(
                "{$this->resource_cache_prefix}/$name",
                $this->resource_map[$name]['items'],
                3600, true, false
            );
    }

    /**
     * Searches resources in the filesystem.
     *
     * If $data is a string, it must be relative to any of the defined resource paths (as always).
     *
     * @param Renderable|string $data Object to search for or resource to add directly.
     * @return void
     */
    public function addResource(Renderable|string $data)
    {
        if ($this->skipResources)
            return;
        $start = microtime(true);
        if ($data instanceof Renderable)
        {
            $is_template = $data instanceof Template;
            $classname = get_class($data);
            $cnl = strtolower($classname);
            $rootcn = $cnl;
            $data_present = $this->resMapRestore($cnl);
            if (!$is_template && $data_present)
            {
                $start = Wdf::Measure(__METHOD__." - cache hit" , $start);
                return;
            }
            if (!$data_present )
            {
                $this->resMapAdd($cnl, array_map(fn($a) => resource_search($a->Path), ResourceAttribute::Collect(\get_class($data))));
                $start = Wdf::Measure(__METHOD__." - attributes" , $start);
            }

            if ($is_template)
            {
                /** @var Template $data */
                $fnl = strtolower(substr_until(basename($data->file), '.'));
                if (!$this->resMapRestore($fnl) && get_class_simple($data, true) != $fnl)
                {
                    if ($rf = resource_search("$fnl.css"))
                        $this->resMapAdd($fnl, [$rf]);
                    elseif ($rf = resource_search("$fnl.less"))
                        $this->resMapAdd($fnl, [$rf]);
                    if ($rf = resource_search("$fnl.js"))
                        $this->resMapAdd($fnl, [$rf]);

                    $this->resMapStore($fnl);
                    $start = Wdf::Measure(__METHOD__." - template" , $start);
                }
            }

            if ($data_present)
                return;

            $parents = [];
            do
            {
                $cnl = strtolower(substr_from($classname, '\\'));
                if ($rf = resource_search("$cnl.css"))
                    $parents[] = $rf;
                elseif ($rf = resource_search("$cnl.less"))
                    $parents[] = $rf;
                if ($rf = resource_search("$cnl.js"))
                    $parents[] = $rf;
                $classname = get_parent_class($classname);
            }
            while ($classname != "");

            $this->resMapAdd($rootcn, array_reverse($parents));

            $this->resMapStore($rootcn);
            Wdf::Measure(__METHOD__." - parents" , $start);
        }
        elseif (!empty($data))
        {
            if ($rf = resource_search($data))
            {
                // this is uncached onetime addition
                $this->resource_map['plain.string']['items'][$rf['key']] = $rf;
            }
            elseif(starts_iwith($data, 'http') && ($url = filter_var($data, \FILTER_VALIDATE_URL)))
            {
                // this is a ressource as full url
                $k = md5($url);
                $this->resource_map[$k]['items'][$url] = ['key' => $k, 'url' => $url, 'ext' => pathinfo($url, PATHINFO_EXTENSION), 'sort' => 0];
            }
        }
    }

    public function getResources()
    {
        if ($this->skipResources)
            return [];
        if (!$this->prepared_resources)
        {
            // map collected resources to a flat array
            $res = [];
            // log_debug("Final map keys",array_keys($this->resource_map));
            foreach ($this->resource_map ?? [] as $name=>$val)
            {
                // log_debug("checking $name", $val);
                foreach ($val['items'] ?? [] as $key => $resource)
                    if ($key && empty($res[$key]))
                    {
                        // log_debug("added $key");
                        $res[$key] = $resource;//compact('ext', 'key', 'url');
                    }
            }
            uasort($res, fn($a, $b) => $a['sort'] <=> $b['sort']);
            $this->prepared_resources = array_values($res);
            // log_debug("resources for ", Wdf::Request()->getEndpoint(), $res);
        }
        return $this->prepared_resources;
    }

    #endregion

    public function skipResources(bool $skip = true): static
    {
        $this->skipResources = $skip;
        return $this;
    }
}