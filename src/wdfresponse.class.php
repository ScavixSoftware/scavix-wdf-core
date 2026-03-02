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

    public static function &Get()
    {
        if (empty(self::$_instance))
        {
            self::$_instance = new WdfResponse();
        }
        return self::$_instance;
    }

    private $resource_map = [], $processed_classes = [];

    public function getResources()
    {
        $res = [];
        foreach ($this->resource_map ?? [] as $key => $val)
        {
            foreach ($val['items'] ?? [] as $url)
            {
                $key = get_requested_file($url);
                if (empty($res[$key]))
                {
                    $ext = pathinfo(($key == '' ? $url : $key), PATHINFO_EXTENSION);
                    $res[$key] = compact('ext', 'key', 'url');
                }
            }
        }
        return array_values($res);
    }

    private function resMapRestore($name)
    {
        if (!empty($this->resource_map[$name]))
            return true;

        $key = 'resource/' . getAppVersion('nc') . '/' . $name;
        if ($data = cache_get($key, false, true, false))
        {
            // $this->processed_classes[$classname] = true;
            $this->resource_map[$name] = ['items' => $data, 'saved' => true];
            return true;
        }
        return false;
    }

    private function resMapAdd($name, $data)
    {
        if (empty($data))
            return;
        if (!is_array($data))
            $data = [$data];
        if (empty($this->resource_map[$name]))
            $this->resource_map[$name] = ['items' => $data, 'saved' => false];
        else
        {
            $this->resource_map[$name]['items'] = array_unique(array_merge($this->resource_map[$name]['items'], $data));
            $this->resource_map[$name]['saved'] = false;
        }

    }

    private function resMapStore($name)
    {
        if (!empty($this->resource_map[$name]['items']) && !$this->resource_map[$name]['saved'])
        {
            $key = 'resource/' . getAppVersion('nc') . '/' . $name;
            cache_set($key, $this->resource_map[$name]['items'], 3600, true, false);
        }
    }

    public function addResource(Renderable|string $data)
    {
        $start = microtime(true);
        if ($data instanceof Renderable)
        {
            $is_template = $data instanceof Template;
            $classname = get_class_simple($data);
            $cnl = strtolower($classname);
            $rootcn = $cnl;
            $data_present = $this->resMapRestore($cnl);
            if (!$is_template && $data_present)
            {
                $start = Wdf::Measure(__METHOD__." - cache hit" , $start);
                return;
            }
            // $this->processed_classes[$classname] = true;
            if (!$data_present )
            {
                $this->resMapAdd($cnl, ResourceAttribute::ResolveAll(ResourceAttribute::Collect(\get_class($data))));
                $start = Wdf::Measure(__METHOD__." - attributes" , $start);
            }

            if ($is_template)
            {
                /** @var Template $data */
                $fnl = strtolower(substr_until(basename($data->file), '.'));
                if (!$this->resMapRestore($fnl) && get_class_simple($data, true) != $fnl)
                {
                    if (resourceExists("$fnl.css"))
                        $this->resMapAdd($fnl, resFile("$fnl.css"));
                    elseif (resourceExists("$fnl.less"))
                        $this->resMapAdd($fnl, resFile("$fnl.less"));
                    if (resourceExists("$fnl.js"))
                        $this->resMapAdd($fnl, resFile("$fnl.js"));

                    $this->resMapStore($fnl);
                    $start = Wdf::Measure(__METHOD__." - template" , $start);
                }
            }

            if ($data_present)
                return;

            $parents = [];
            do
            {
                if (resourceExists("$cnl.css"))
                    $parents[] = resFile("$cnl.css");
                elseif (resourceExists("$cnl.less"))
                    $parents[] = resFile("$cnl.less");
                if (resourceExists("$cnl.js"))
                    $parents[] = resFile("$cnl.js");
                $classname = substr_from(get_parent_class(fq_class_name($classname)), '\\');
                $cnl = strtolower($classname);
            }
            while ($classname != "");

            $this->resMapAdd($rootcn, array_reverse($parents));

            $this->resMapStore($rootcn);
            Wdf::Measure(__METHOD__." - parents" , $start);
        }
        elseif (!empty($data))
        {
            $this->resource_map['plain.string']['items'][] = $data;
        }
    }
}