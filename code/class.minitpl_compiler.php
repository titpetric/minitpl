<?php

/*

Tit Petrič, Monotek d.o.o., (cc) 2008, tit.petric@monotek.net
http://creativecommons.org/licenses/by-sa/3.0/

*/

/** Template compiler class */
class minitpl_compiler
{
	function minitpl_compiler()
	{
		$this->_tag_php_open = "<"."?php";
		$this->_tag_php_close = "?".">\n";
		$this->_global_variables = array();
	}

	/** Compile template file into php code */
	function compile($filename, $output_filename, $find_path, $nocache)
	{
		$contents = file_get_contents($filename);
		$r = 0;
		if ($contents!==false && $contents!=="") {
			if (substr($contents,0,3)=="\xEF\xBB\xBF") {
				$contents = substr($contents,3);
			}
			while (preg_match_all("/\{include\ (.*?)\}/s", $contents, $matches)) {
				$matches = array_unique($matches[1]);
				foreach ($matches as $file) {
					$cn = "<!-- ".$file." -->";
					if (($fn = call_user_func($find_path, $file))!==false) {
						$cn = file_get_contents($fn.$file);
						if (substr($cn,0,3)=="\xEF\xBB\xBF") {
							$cn = substr($cn,3);
						}
					}
					$contents = str_replace("{include ".$file."}", $cn, $contents);
				}
			}
			$nocache = $nocache ? $this->_code("@unlink(__FILE__);") : "";
			$contents = str_replace("{*nocache*}",$nocache,$contents);
			$contents = $this->_strip_comments($contents);
			$contents = $this->_parse_constants($contents);
			$contents = $this->_parse_functions($contents);
			$contents = $this->_parse_expressions($contents);
			$contents = $this->_parse_variables($contents);
			if (!empty($this->_global_variables)) {
				$globals = array_unique($this->_global_variables);
				$contents = $this->_code('global '.implode(", ",$globals).';').$contents;
			}
			$contents = $this->_template_cleanup($contents);
			$this->_r_mkdir(dirname($output_filename));
			if ($f = @fopen($output_filename,"w")) {
				fwrite($f, $contents);
				fclose($f);
				$r = 1;
			}
		}
		return $r;
	}

	function _r_mkdir($dir)
	{
		if (file_exists($dir)) return;
		if (!file_exists(dirname($dir))) $this->_r_mkdir(dirname($dir));
		@mkdir($dir);
	}

	/** Insert system configuration, clean up code */
	function _template_cleanup($contents)
	{
		// set up variables
		$contents = $this->_code('$_v = &$this->_vars;').$contents;
		// strip unnecessary php tags
		$contents = str_replace($this->_tag_php_close.$this->_tag_php_open, "", $contents);
		// strip empty lines
//		$contents = preg_replace("/\n[\n]+/s","\n\n",$contents);
		// strip new line whitespace between php code
		$contents = str_replace($this->_tag_php_close."\n".$this->_tag_php_open.' ', "", $contents);
		$contents = str_replace("echo ;", "", $contents);
		return $contents;
	}

	/** Strip template style comments */
	function _strip_comments($contents)
	{
		$contents = preg_replace("/\{\*.+\*\}/sU", "", $contents);
		return $contents;
	}

	/** Replace constant definitions */
	function _parse_constants($contents)
	{
		$matches = array();
		if (preg_match_all("/\{(\_[a-zA-Z0-9\_]+)\}/", $contents, $matches)) {
			$matches = array_unique($matches[1]);
			foreach ($matches as $m) {
				$contents = str_replace("{".$m."}", $this->_code("echo ".$m.";"), $contents);
			}
		}
		return $contents;
	}

	/** Search and replace for function blocks and inline definitions */
	function _parse_functions($contents)
	{
		$inlines = $blocks = array();

		if (preg_match_all("/\{(block|inline)\ ([a-zA-Z0-9\_\-]+)\}(.*?)\{\/\\1\}/s", $contents, $matches)) {
			foreach ($matches[0] as $k=>$ma) {
				$m = array("content"=>trim($matches[3][$k]), "src"=>$ma);
				if ($matches[1][$k]=="block") {
					$blocks[$matches[2][$k]] = $m;
				} else {
					$inlines[$matches[2][$k]] = $m;
				}
			}
		}

		$lambda = time()."_".rand(0,999);
		foreach ($blocks as $name=>$code) {
			$block_code = "if (!function_exists('".$name."_".$lambda."')) { function ".$name."_".$lambda."(\$_v) {".$this->_tag_php_close.$code['content'].$this->_tag_php_open." } }";
			$contents = str_replace($code['src'], $this->_code($block_code), $contents);
		}

		foreach ($inlines as $name=>$code) {
			$contents = str_replace($code['src'], '', $contents);
		}

		$matches = array();
		while (preg_match_all("/\{inline\:([a-zA-Z0-9\_\-]+)\}/s", $contents, $matches)) {
			foreach ($matches[0] as $k=>$ma) {
				$contents = str_replace($ma, $inlines[$matches[1][$k]]['content'], $contents);
			}
		}

		foreach ($blocks as $name=>$code) {
			$contents = str_replace("{block:".$name."}", $this->_code($name."_".$lambda."(&\$_v);"), $contents);
		}
		return $contents;
	}

	/** Parse expression syntax: if, elseif, foreach, else, for, eval, eval_literal */
	function _parse_expressions($contents)
	{
		// foreach parsing
		if (preg_match_all("/\{foreach (.+)\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $k=>$exp) {
				$exp = trim(trim($exp,"()"));
				list($e_left, $e_right) = explode(" as ", $exp);
				$e_right = explode("=>", $e_right);
				$code = "if(!empty(".$this->_get_var($e_left)."))foreach(".$this->_get_var($e_left)." as ".$this->_get_var($e_right[0]);
				if (count($e_right)==2) {
					$code .= '=>'.$this->_get_var($e_right[1]);
				}
				$code .= '){';
				$contents = str_replace($matches[0][$k], trim($this->_code($code)), $contents);
			}
		}
		// if & for & elseif parsing
		if (preg_match_all("/\{(if|elseif|for|while) (.+)\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $k=>$v) {
				if ($v=="for") {
					$matches[2][$k] = trim($matches[2][$k],"()");
				}
				$code = $v."(".$this->_split_exp($matches[2][$k])."){";
				if ($v=="elseif") {
					$code = "}".$code;
				}
				$contents = str_replace($matches[0][$k], $this->_code($code), $contents);
			}
		}
		// eval & eval_literal parsing
		if (preg_match_all("/\{(eval|eval_literal) (.+)\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $k=>$type) {
				$code = rtrim(trim($matches[2][$k]),';');
				if ($type=="eval") {
					$code = $this->_split_exp($code);
				}
				$code .= ";";
				$contents = str_replace($matches[0][$k], $this->_code($code), $contents);
			}
		}
		$contents = str_replace("{else}", $this->_code("}else{"), $contents);
		$contents = str_replace(array("{/foreach}","{/while}","{/for}","{/if}"), trim($this->_code("}")), $contents);
		return $contents;
	}

	/** Parse variables */
	function _parse_variables($contents)
	{
		$mycontent = preg_replace("/\<\?php.+\?\>/sU","",$contents);
		// [a-zA-Z\_\$\"\'\[\]\ ]
		if (preg_match_all("/\{([^\{]+)\}/sU", $mycontent, $matches)) {
			foreach ($matches[1] as $k=>$v) {
				if (strstr($v,"\n")===false && $v{0}!=" ") {
					if ($v{0}!='$') {
						// shorthand variables {v}
						$v = '$'.$v;
					}
					$code = "";
					if (strstr($v,"|")!==false) {
						list($left,$right) = explode("|",$v);
						$left = $this->_split_exp($left);
						switch ($right) {
							case "toupper": $right = "strtoupper"; break;
							case "tolower": $right = "strtolower"; break;
							case "escape": $code = "echo htmlspecialchars(".$left.", ENT_QUOTES);"; break;
						}
						if ($code=='') {
							$code = "echo ".$right."(".$left.");";
						}
					} else {
						$code = "echo ".$this->_split_exp($v).";";
					}
					$contents = str_replace($matches[0][$k], $this->_code($code), $contents);
				}
			}
		}
		return $contents;
	}

	/** Split up variables from a php expression and replace them with actual variable locations */
	function _split_exp($exp) {
		$code = str_replace(".","__1","<"."?php if (".$exp.") { ?".">");
		$tokens = token_get_all($code);
		$objects = array();
		$variables = array();
		$waiting = false;
		$variable_continues = false;
		foreach ($tokens as $k=>$v) {
			if (is_array($v)) {
				if ($v[0] == T_OBJECT_OPERATOR) {
					$variable_continues = false;
					$objects[] = $variable;
				}
				if ($v[0] == T_VARIABLE) {
					if (!$variable_continues && isset($variable) && !in_array($variable,$objects)) {
						$variables[] = $variable;
					}
					$variable = $variable_continues ? $variable.$v[1] : $v[1];
					$waiting = true;
					if (strstr($variable,"__1")!==false) {
						$variable = str_replace("__1",".",$variable);
						$variable_continues = false;
						if (substr($variable,-1)==".") {
							$variable_continues = true;
						}
					} else if ($variable_continues) {
						$variable_continues = false;
					}
				}
				$v[0] = token_name($v[0]);
				$tokens[$k] = $v;
			}
		}
		if (isset($variable) && !in_array($variable,$variables) && !in_array($variable,$objects)) {
			$variables[] = $variable;
		}
		// globalize objects
		foreach ($objects as $object) {
			if ($object!='$this' && is_object(&$GLOBALS[substr($object,1)])) {
				$this->_global_variables[] = $object;
			} else {
				$variables[] = $object;
			}
		}
		usort($variables,array("minitpl_compiler", "_strlen_sort"));
		$variables = array_reverse($variables);
		foreach ($variables as $var) {
			$exp = str_replace($var,$this->_get_var($var),$exp);
		}
		return $exp;
	}

	/** Helper function for sorting array by string length */
	function _strlen_sort($a,$b)
	{
		if (strlen($a)==strlen($b)) {
			if ($a==$b) {
				return 0;
			}
			return ($a<$b) ? -1 : 1;
		}
		return (strlen($a)<strlen($b)) ? -1 : 1;
	}

	/** Helper function for replacing tags into actual variable locations */
	function _get_var($var) {
		$left_modifier = substr($var,1); // remove $
		$retval = $var;
		if ($var{0}!='"' && $var{0}!="'") {
			$retval = '$_v';
			if (strstr($left_modifier,'.')!==false) {
				// we have ourselves a table index
				$table_indices = explode('.',$left_modifier);
				foreach ($table_indices as $v) {
					$retval .= (($v{0}=='$') ? "[".$this->_get_var($v)."]" : "['".$v."']");
				}
			} else {
				$retval .= (($left_modifier{0}=='$') ? "[".$this->_get_var($left_modifier)."]" : "['".$left_modifier."']");
			}
		}
		return $retval;
	}

	/** Helper function for php code shorthand syntax, optimizing compiler size */
	function _code($s) {
		return $this->_tag_php_open." ".$s.$this->_tag_php_close;
	}
}
