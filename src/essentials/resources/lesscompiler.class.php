<?php
/**
 * Scavix Web Development Framework
 *
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
 * @author Scavix Software GmbH & Co. KG https://www.scavix.com <info@scavix.com>
 * @copyright since 2019 Scavix Software GmbH & Co. KG
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 */
namespace ScavixWDF;

use Less_Exception_Compiler;
use Less_Parser;
use Less_Tree_Declaration;

/**
 * This class creates a unique interface for LESS compilers.
 *
 * Some notes about LESS variables: There's a priority and some extensions to the LESS syntax.
 * This is the variable prio, highest first:
 *   1. Defined in less file with: @<varname>: force(<value>);
 *   2. Defined via <register_less_variable> function
 *   3. Defined in $CONFIG['less']['variables']
 *   4. Defined in less file with: @<varname>: register(<value>);
 *   5. Defined in less file with: @<varname>: <value>;
 * This implies, that variables are not overwritten, if they were defined as in 1-4.
 * Normal assignments (as in 5) will allow later overwriting as usual.
 *
 * More config bases options are:
 * - En-/Disable verbose logging: $CONFIG['less']['verbose'] = true|false (default: false)
 * - Add global less files (injected in every file): $CONFIG['less']['files'] = ['/path/to/file.less']
 *
 * Other LESS enhancements:
 * - Add "verbose_compilation = on" as comment somewhere in a less file to enable verbose logging
 * - Add "verbose_compilation = off" to disable it
 * - "background: lightenBackground(<color>[ <opacity>])" to have a light-colored background with opacity
 * - "background: darkenBackground(<color>[ <opacity>])" to have a dark-colored background with opacity
 * - "<img-prop>: dataUri(<res_filename>)" will embed the contents of a resource-file data uri (automatically using the best encoding)
 */
class LessCompiler implements \JsonSerializable
{
    private $id, $verbose, $registered = [], $forced = [];
    private $libFunctions = [];
	private $registeredVars = [];
	private $allParsedFiles = [];
    private $debug_buffer = [];

    function __construct()
    {
        $this->id = random_int(999, 9999);
        $this->verbose = cfg_getd('less', 'verbose', false);
        $this->registerFunctions();
    }

    #region Less features

    private function registerFunctions()
    {
        $this->registerFunction('lighten_background', function(...$args){ return $this->enhancedBackgound('lighten',...$args); });
        $this->registerFunction('darken_background', function(...$args){ return $this->enhancedBackgound('darken',...$args); });
        $this->registerFunction('data_uri', function (...$args)
        {
            if (count($args) != 1)
                $this->error("'data_uri': Missing file argument");

            $name = $args[0]->value ?? 'none';
            $this->debug("dataUri($name)");
            if( $name == 'none' )
                return "none";

            $name = str_ireplace("resfile/", "", $name);
            $fn = resFile(trim($name,"\"' "), true);
            $this->debug("file $fn");
            if( !file_exists($fn) )
                return "none";

            $mime = system_guess_mime($fn);
            if( "image/svg+xml" == $mime )
            {
                $c = trim(file_get_contents($fn));

                $c = preg_replace('|.*(<svg.+/svg>).*|is', '$1', $c);
                $c = preg_replace('/<!--.*-->/is', '', $c);
                $c = preg_replace('/[\r\n\t]/', ' ', $c);
                $c = preg_replace('/\s\s+/', ' ', $c);
                $c = preg_replace('/>\s</is', '><', $c);

                $c = base64_encode($c);
                return "url(\"data:$mime;base64,$c\")";

                // this seems to produce invaid data in some rare cases
                // could not find out why. at this point the base64 overhead
                // over urlencoded is about 20%, we need to live with that.
                // $c = str_replace(
                //     ['"',"%"  ,"#"  ,'{'  ,'}'  ,'<'  ,'>'  , '&'  ,' '],
                //     ["'","%25","%23","%7B","%7D","%3C","%3E", "%26", "%20"],
                //     $c);
                // return "url(\"data:$mime,$c\")";
            }
            $c = base64_encode(file_get_contents($fn));
            return "url(\"data:$mime;base64,$c\")";
        });

        $this->registerFunction('register', function ($a)
        {
            $n = system_get_caller_by_type(Less_Tree_Declaration::class)?->name;
            if( is_string($n) )
                $this->registered[$n] = $this->getVariableValue($a);
            return $a;
        });
        $this->registerFunction('force', function ($a)
        {
            $n = system_get_caller_by_type(Less_Tree_Declaration::class)?->name;
            if (is_string($n))
                $this->forced[$n] = $this->getVariableValue($a);
            return $a;
        });
    }

    private function getVariableValue( \Less_Tree $var ) {
		switch ( get_class( $var ) ) {
			case \Less_Tree_Color::class:
				return $this->rgb2html( $var->rgb );
			case \Less_Tree_Variable::class:
			case \Less_Tree_Keyword::class:
				return $var->value;
			case \Less_Tree_Anonymous::class:
				$return = [];
				if ( is_array( $var->value ) ) {
					// in compilation phase, Less_Tree_Anonymous::$val can be a Less_Tree[]
					foreach ( $var->value as $value ) {
						/** @var \Less_Tree $value */
						$return[ $value->name ] = $this->getVariableValue( $value );
					}
				}
				return count( $return ) === 1 ? $return[0] : $return;
			case \Less_Tree_Url::class:
				// Based on Less_Tree_Url::genCSS()
				// Recurse to serialize the Less_Tree_Quoted value
				return 'url(' . $this->getVariableValue( $var->value ) . ')';
			case \Less_Tree_Declaration::class:
				return $this->getVariableValue( $var->value );
			case \Less_Tree_Value::class:
				$values = [];
				foreach ( $var->value as $sub_value ) {
					$values[] = $this->getVariableValue( $sub_value );
				}
				return count( $values ) === 1 ? $values[0] : $values;
			case \Less_Tree_Quoted::class:
				return $var->quote . $var->value . $var->quote;
			case \Less_Tree_Dimension::class:
				$value = $var->value;
				if ( $var->unit && $var->unit->numerator ) {
					$value .= $var->unit->numerator[0];
				}
				return $value;
			case \Less_Tree_Expression::class:
				$values = [];
				foreach ( $var->value as $item ) {
					$values[] = $this->getVariableValue( $item );
				}
				return implode( ' ', $values );
			case \Less_Tree_Operation::class:
				throw new Exception( 'getVariables() require Less to be compiled. please use $parser->getCss() before calling getVariables()' );
			case \Less_Tree_Unit::class:
			case \Less_Tree_Comment::class:
			case \Less_Tree_Import::class:
			case \Less_Tree_Ruleset::class:
			default:
				throw new Exception( "type missing in switch/case getVariableValue for " . get_class( $var ) );
		}
	}

	private function rgb2html( $r, $g = -1, $b = -1 ) {
		if ( is_array( $r ) && count( $r ) == 3 ) {
			[ $r, $g, $b ] = $r;
		}

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

    private function enhancedBackgound($mode, ...$args)
    {
        switch (count($args))
        {
            case 0:
                $color = \Less_Tree_Color::fromKeyword('white');
                $opacity = '0.7';
                break;
            case 1:
                $color = $args[0];
                $opacity = '0.7';
                break;
            case 2:
                $color = $args[0];
                $opacity = $args[1];
                break;
            default:
                $this->error("'{$mode}_background': Invalid number of arguments:", $args);
                break;
        }
        if (!($color instanceof \Less_Tree_Color))
            $this->error("'{$mode}_background': Invalid first argument ", $color);

        $color = $color->toRGB();
        $svg_col = Base\Color::hex($mode == 'lighten' ? 'white' : 'black')->setAlpha($opacity);
        return implode(", ", [
            "linear-gradient(to right,$color 0%,$color 100%)",
            "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1 1' style='background:$svg_col'%3E%3C/svg%3E%0A\")"
        ]) . ";background-blend-mode:$mode;";
    }

    #endregion

    #region Logging

    /**
     * Set verbosity on/off.
     *
     * @param bool $on True=on, False=off
     */
    function setVerbose($on = true)
    {
        if ($this->verbose && !$on)
            $this->debug("verbose=off");
        $this->verbose = $on;
        if ($on)
            $this->debug("verbose=on");
    }

    function debug(...$args)
    {
        if( $this->verbose )
            log_debug("[LESS][$this->id]",...$args);
        else
            $this->debug_buffer[] = $args;
    }

    function error(...$args)
    {
        foreach ($this->debug_buffer as $info)
            log_debug("[LESS][$this->id]", ...$info);
        log_debug("[LESS][$this->id]",...$args);
    }

    #endregion

    #region Internal compilation logic

    private function processCompilation($fname, $content = null)
    {
        $this->debug_buffer = [];
        try
        {
            if (!$this->id || is_numeric($this->id))
            {
                $a = explode("/", $fname);
                $b = explode("/", getcwd());
                while (count($a) && count($b) && $a[0] == $b[0])
                {
                    array_shift($a);
                    array_shift($b);
                }
                $this->id = implode("/", $a);
            }

            // ensure contents
            $content = $content ?: file_get_contents($fname);

            // prepare import files/folders
            $pi = pathinfo($fname);
            $importDirs = $_SESSION['resources_less_dirs'] ?? [];
            $importDirs[] = Less_Parser::AbsPath($pi['dirname']) . '/';

            $this->allParsedFiles = [];
            $content = $this->preprocessLess($fname, $content);

            // prepare parser
            $parser = new Less_Parser($this->getParserOptions());
            $parser->SetImportDirs(array_fill_keys($importDirs, ''));
            foreach ($this->libFunctions as $name => $func)
                $parser->registerFunction($name, $func);

            // preprocess and compile less of all included files
            foreach (array_reverse(cfg_getd('less', 'files', [])) as $f)
                $this->parseImportedFile($parser, $f);
            $raw_less = $this->preprocessImports($parser, $fname, $content);

            $parser->parse($raw_less, $fname);
            $this->addParsedFile($fname);

            // finally apply variables, respecting the priority as documented in class comment
            return $this->applyVariables($parser);
        }
        catch (Less_Exception_Compiler $ex)
        {
            $this->error($ex->getMessage(),$ex->getTraceAsString());
        }
        return '';
    }

    private function getParserOptions()
    {
        return [
            'relativeUrls' => false,
            'indentation' => "\t",
            'compress' => !isDev(),
            'sourceMap'=> false,
        ];
    }

    private function parseImportedFile(Less_Parser $parser, $lessFile, $lessUri=null)
    {
        if( !file_exists($lessFile) )
        {
            $this->error("Import not found ".$lessFile);
            return;
        }
        if( isset($this->allParsedFiles[$lessFile]) )
        {
            $this->debug("Import already parsed ".($lessUri?:$lessFile));
            return;
        }
        $this->debug("Importing " . ($lessUri ?: $lessFile));
        $lc = $this->preprocessLess($lessFile);
        $lc = $this->preprocessImports($parser, $lessFile, $lc);
        $parser->parse($lc, $lessUri ?: $lessFile);
        $this->addParsedFile($lessFile);
    }

    private function preprocessLess($lessFile, $less = '')
    {
        // mediawiki less compiler is very strict on function names, we need to replace camelCase to snake_case
        $less = str_replace(
            [
                'lightenBackground',
                'darkenBackground',
                'dataUri'
            ],
            [
                'lighten_background',
                'darken_background',
                'data_uri'
            ],
            $less ?: file_get_contents($lessFile)
        );
        if( stripos($less,"verbose_compilation = on") !== false )
            $this->setVerbose();
        elseif( stripos($less,"verbose_compilation = off") !== false )
            $this->setVerbose(false);

        return $less;
    }

    private function preprocessImports(Less_Parser $parser, $lessFile, $less = '')
    {
        $less = preg_replace_callback('/@import\s+["\']([^"\']+)["\']/', function ($match) use ($parser, $lessFile)
        {
            $fn = dirname($lessFile) . '/' . $match[1];
            if (strpos($fn, '.') === false)
                $rel = realpath("$fn.less") ?: realpath("$fn.css");
            else
                $rel = realpath($fn);
            $this->parseImportedFile($parser, $rel, basename($rel));
            return '';
        }, $less ?: file_get_contents($lessFile));
        return $less;
    }

    private function applyVariables(Less_Parser $parser)
    {
        $out = $parser->getCss();
        $vars = $parser->getVariables();

        $apply = function ($variables, $logstring) use ($parser, &$vars)
        {
            $keys = array_map(fn($k) => str_replace('@@', '@', "@$k"), array_keys($variables));
            $variables = array_combine($keys, array_values($variables));
            if (count($variables))
            {
                $this->debug($logstring, $variables);
                $parser->ModifyVars($variables);
                $vars = array_merge($vars, $variables);
            }
        };

        $apply($this->registered, "Setting less-registered variables");
        $apply(cfg_getd('less', 'variables', []), "Setting code-configured variables");
        $apply($_SESSION['resources_less_variables'] ?? [], "Setting code-registered variables");
        $apply($this->forced, "Setting less-forced variables");

        foreach( $vars as $n=>$value )
        {
            foreach (force_array($value) as $v)
            {
                if (!is_string($v) || !preg_match_all('/(@[^\s]+)/', $v, $matches))
                    continue;
                foreach ($matches[1] as $m)
                {
                    if (isset($vars[$m]))
                        continue;
                    $this->debug("Missing variable reference '$m', using default");
                    $vars[$m] = '0';
                    $parser->ModifyVars([$m => '0']);
                }
            }
        }

        $this->debug("Final variables: ".json_encode($vars));

        $out = $parser->getCss();
        $out = preg_replace_callback('/generate_variable_index\(([^\)]+)\);*/', function ($match) use ($vars)
        {
            $node = trim($match[1], '\"\' ');
            $res = [];
            foreach ($vars as $k => $v)
            {
                $n = ltrim($k, '@');
                $res[$k] = "--{$n}: $v;";
            }
            return "$node\n{\n\t" . implode("\n\t", $res) . "\n}";
        }, $out);

        $this->debug("Final css", $out);
        return $out;
    }

    protected function addParsedFile( $file )
    {
        if( $file = realpath($file) )
		    $this->allParsedFiles[$file] = filemtime( $file );
	}

    #endregion

    #region External interface

    /**
     * @internal Compiles a LESS file to $outfile
     */
    function compileFile($fname, $outFname = null)
    {
        $this->debug("Compiling file '$fname'");
        return $this->processCompilation($fname);
    }

    /**
     * @internal Compiles LESS code
     */
    function compile($string, $name = null)
    {
        $this->debug("Compiling string");
        return $this->processCompilation($name, $string);
    }

    /**
     * Code taken from the mediawiki leafo-"drop-in replacement"
     * @param mixed $in
     * @param mixed $force
     * @return array|string|null
     */
    public function cachedCompile($in, $force = false)
    {
        $root = null;
        if (is_string($in))
            $root = $in;
        elseif (is_array($in) && isset($in['root']))
        {
            if ($force || !isset($in['files']))
                $root = $in['root'];
            elseif (isset($in['files']) && is_array($in['files']))
            {
                foreach ($in['files'] as $fname => $ftime)
                {
                    if (!file_exists($fname) || filemtime($fname) > $ftime)
                    {
                        $root = $in['root'];
                        break;
                    }
                }
            }
        }
        else
            return null;

        if ($root !== null)
        {
            $out = [];
            $out['root'] = $root;
            $out['compiled'] = $this->compileFile($root);
            $out['files'] = $this->allParsedFiles;
            $out['updated'] = time();
            return $out;
        }
        else
            return $in;
    }

    /**
     * @internal JSON serialization
     */
    public function jsonSerialize(): mixed
    {
        return ['LessCompiler'=>$this->id];
    }

    public function setVariables( $variables )
    {
        $this->registeredVars = $variables;
	}

    public function registerFunction($name, $func)
    {
        $this->libFunctions[$name] = $func;
    }

    #endregion
}