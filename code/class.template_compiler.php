<?php

/*

Tit Petrič, Monotek d.o.o., (cc) 2008, tit.petric@monotek.net
http://creativecommons.org/licenses/by-sa/3.0/

*/

/** Template compiler class */
class template_compiler
{
	function template_compiler()
	{
		$this->_tag_php_open = "<"."?php";
		$this->_tag_php_close = "?".">\n";
		$this->_global_variables = array();
	}

	/** Compile template file into php code */
	function compile($filename, $output_filename, $find_path_callback)
	{
		$contents = $this->_include_file($filename);
		if ($contents!==false && $contents!=="") {
			while (preg_match_all("/\{include (.+)\}/sU", $contents, $matches)) {
				foreach ($matches[0] as $key=>$val) {
					if (($include_path = call_user_func_array($find_path_callback, $matches[1][$key]))!==false) {
						$contents = str_replace($val,$this->_include_file($include_path.$matches[1][$key]),$contents);
					} else {
						echo "Included file ".$matches[1][$key]." doesn't exist! Is it in the include path?\n";
						die;
					}
				}
			}
			$contents = $this->_parse_constants($contents);
			$contents = $this->_parse_functions($contents);
			$contents = $this->_parse_expressions($contents);
			$contents = $this->_parse_variables($contents);
			if (!empty($this->_global_variables)) {
				$globals = array_unique($this->_global_variables);
				$contents = $this->_code('global '.implode(", ",$globals).';').$contents;
			}
			$contents = $this->_template_cleanup($contents);
			if ($f = @fopen($output_filename,"w")) {
				fwrite($f, $contents);
				fclose($f);
				return true;
			}
		}
		return false;
	}

	/** Reads file, cleans up UTF-8 BOM */
	function _include_file($filename)
	{
		$ret=false;
		if ($filename!==false) {
			$ret = file_get_contents($filename);
			// strip comments
			$ret = str_replace(array("?".">","<"."?xml"), array($this->_code("echo '?'.'>';"),$this->_code("echo '<'.'?xml';")), $ret);
			$ret = preg_replace("/\{\*.+\*\}/sU", "", $ret);
			if (substr($ret,0,3)=="\xEF\xBB\xBF") {
				return substr($ret,3);
			}
		}
		if ($ret!==false) {
			return $ret;
		}
		return "";
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
		return $contents;
	}

	/** Replace constant definitions */
	function _parse_constants($contents)
	{
		$matches = array();
		if (preg_match_all("/\{(\_[a-zA-Z0-9\_]+)\}/", $contents, $matches)) {
			$matches = array_unique($matches[1]);
			foreach ($matches as $match) {
				$contents = str_replace("{".$match."}", $this->_code("echo ".$match.";"), $contents);
			}
		}
		return $contents;
	}

	/** Search and replace for function blocks and inline definitions */
	function _parse_functions($contents)
	{
		$inlines = $blocks = array();

		if (preg_match_all("/\{(block|inline)\ ([a-zA-Z0-9\_\-]+)\}(.*?)\{\/\\1\}/sU", $contents, $matches)) {
			foreach ($matches[0] as $k=>$match) {
				switch ($matches[1][$k]) {
					case "block":
						$blocks[$matches[2][$k]] = array("content"=>trim($matches[3][$k]), "src"=>$match);
						break;
					case "inline":
						$inlines[$matches[2][$k]] = array("content"=>trim($matches[3][$k]), "src"=>$match);
						break;
				}
			}
		}

		$lambda = time()."_".rand(0,999);
		foreach ($blocks as $name=>$code) {
			$block_code = "function ".$name."_".$lambda."(\$_v) {".$this->_tag_php_close.$code['content'].$this->_tag_php_open." }";
			$contents = str_replace($code['src'], $this->_code($block_code), $contents);
		}

		foreach ($inlines as $name=>$code) {
			$contents = str_replace($code['src'], '', $contents);
			$contents = str_replace("{inline:".$name."}", $code['content'], $contents);
		}
		// in case of nested inline blocks
		foreach ($inlines as $name=>$code) {
			$contents = str_replace("{inline:".$name."}", $code['content'], $contents);
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
				$code = "if(!empty(".$this->_split_exp($e_left)."))foreach(".$this->_split_exp($e_left)." as ".$this->_split_exp($e_right[0]);
				if (count($e_right)==2) {
					$code .= '=>'.$this->_split_exp($e_right[1]);
				}
				$code .= '){';
				$contents = str_replace($matches[0][$k], trim($this->_code($code)), $contents);
			}
		}
		// if & for & elseif parsing
		if (preg_match_all("/\{(if|elseif|for|while) (.+)\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $key=>$type) {
				if ($type=="for") {
					$matches[2][$key] = trim($matches[2][$key],"()");
				}
				$code = $type."(".$this->_split_exp($matches[2][$key])."){";
				if ($type=="elseif") {
					$code = "}".$code;
				}
				$contents = str_replace($matches[0][$key], $this->_code($code), $contents);
			}
		}
		// eval & eval_literal parsing
		if (preg_match_all("/\{(eval|eval_literal) (.+)\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $key=>$type) {
				$code = rtrim(trim($matches[2][$key]),';');
				if ($type=="eval") {
					$code = $this->_split_exp($code);
				}
				$code .= ";";
				$contents = str_replace($matches[0][$key], $this->_code($code), $contents);
			}
		}
		if (preg_match_all("/\{php\}(.+)\{\/php\}/sU", $contents, $matches)) {
			foreach ($matches[1] as $key=>$code) {
				$code = $this->_split_exp(trim($code),false);
				$contents = str_replace($matches[0][$key], $this->_code($code), $contents);
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
		if (preg_match_all("/\{([^\{]+)\}/sU", $mycontent, $matches)) {
			foreach ($matches[1] as $key=>$val) {
				if (strstr($val,"\n")===false && $val{0}!=" ") {
					if ($val{0}!='$') {
						// shorthand variables {v}
						$val = '$'.$val;
					}
					$code = "";
					if (strstr($val,"|")!==false) {
						list($left,$right) = explode("|",$val);
						$left = $this->_split_exp($left);
						switch ($right) {
							case "toupper": $right = "strtoupper"; break;
							case "tolower": $right = "strtolower"; break;
							case "escape": $code = "echo htmlspecialchars(".$left.", ENT_QUOTES);"; break;
						}
						if ($code=='') {
							$code = "echo ".$right."(".$left.");";
							if (strpos($code,'->')!==false) {
								$code = "echo ".$this->_split_exp($right)."(".$left.");";
							}
						}
					} else {
						$code = "echo ".$this->_split_exp($val).";";
					}
					$contents = str_replace($matches[0][$key], $this->_code($code), $contents);
				}
			}
		}
		return $contents;
	}

	/** Split up variables from a php expression and replace them with actual variable locations */
	function _split_exp($exp,$cond=true) {
		$code = str_replace(".","__1","<"."?php ".$exp." ?".">");
		if ($cond) {
			$code = str_replace(".","__1","<"."?php if (".$exp.") { ?".">");
		}
		$tokens = token_get_all($code);
		$objects = array();
		$variables = array();
		$waiting = false;
		$variable_continues = false;
		foreach ($tokens as $k=>$val) {
			if (is_array($val)) {
				if ($val[0] == T_OBJECT_OPERATOR) {
					$variable_continues = false;
					$objects[] = $variable;
				}
				if ($val[0] == T_VARIABLE) {
					if (!$variable_continues && isset($variable) && !in_array($variable,$objects)) {
						$variables[] = $variable;
					}
					$variable = $variable_continues ? $variable.$val[1] : $val[1];
					$waiting = true;
					if (strstr($variable,"__1")!==false) {
						$variable = str_replace("__1",".",$variable);
						if (substr($variable,-1)==".") {
							$variable_continues = true;
						} else {
							$variable_continues = false;
						}
					} else if ($variable_continues) {
						$variable_continues = false;
					}
				}
				$val[0] = token_name($val[0]);
				$tokens[$k] = $val;
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
		$variables = array_unique($variables);
		usort($variables,array("template_compiler", "_strlen_sort"));
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
		return (strlen($a)<strlen($b)) ? 1 : -1;
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
