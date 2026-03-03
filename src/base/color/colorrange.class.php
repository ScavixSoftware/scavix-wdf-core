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

namespace ScavixWDF\Base\Color;

/**
 * Represents a color range.
 *
 * Never construct a ColorRange directly, but use <Color::range>,
 * otherwise the classloader may fail.
 */
class ColorRange
{
    public $from, $to, $min, $max;

    function __toString()
    {
        if( $this->min && $this->max)
            return "ColorRange {$this->from}->{$this->to} ({$this->min}->{$this->max})";
        return "ColorRange {$this->from}->{$this->to}";
    }

    public function __construct(\ScavixWDF\Base\Color $from, \ScavixWDF\Base\Color $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * Sets values that act as min and max when querying data.
     *
     * @param int|float $min Minimum value
     * @param int|float $max Maximum value
     * @return ColorRange $this
     */
    public function setMinMax($min,$max)
    {
        $this->min = min($min,$max);
        $this->max = max($min,$max);
        return $this;
    }

    /**
     * Return the color corresponding to a value in the range.
     *
     * @param int|float $value The value (between min and max) to get the color for
     * @return \ScavixWDF\Base\Color
     */
    public function fromValue($value)
    {
        $t = max($this->max-$this->min,0.00000001);
        $v = $value - $this->min;
        return $this->fromPercent($v / $t * 100);
    }

    /**
     * Return the color corresponding to percent in the range.
     *
     * @param int|float $percent The percent (0-100) to get the color for
     * @return \ScavixWDF\Base\Color
     */
    public function fromPercent($percent)
    {
        $parts = [];
        foreach( ['r','g','b','a'] as $p )
        {
            $s = ($this->to->$p - $this->from->$p) / 100;
            $parts[$p] = $p == 'a'
                ?max(0,min(round($this->from->$p + ($percent * $s),4),1))
                :max(0,min((int)round($this->from->$p + ($percent * $s)),255));
        }
        extract($parts);
        return \ScavixWDF\Base\Color::rgba($r,$g,$b,$a);
    }
}
