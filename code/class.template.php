<?php

/*

Tit Petrič, Monotek d.o.o., (cc) 2008, tit.petric@monotek.net
http://creativecommons.org/licenses/by-sa/3.0/

*/

/** Template class */
class template
{
	/** Holds assigned values */
	var $_vars;
	/** Holds search paths */
	var $_paths;

	/** Default constructor */
	function template($paths=false)
	{
		$this->set_paths($paths);
	}

	/** Template loader */
	function load($filename)
	{
		if (($path = $this->_find_path($filename))!==false) {
			$file_original = $path.$filename;
			$file_compiled = $path."cache/".$filename;
			if (file_exists($file_compiled)) {
				if (file_exists($file_original) && (filemtime($file_original) > filemtime($file_compiled))) {
					$this->compile($file_original, $file_compiled);
				}
			} else {
				$this->compile($file_original, $file_compiled);
			}
			$this->_load_file = $file_compiled;
			if (!file_exists($this->_load_file)) {
				echo "Template file ".$file_compiled." doesn't exist! Is the compile dir writeable?\n";
				die;
			}
		} else {
			echo "Template file ".$filename." not found in path. Error!\n";
			die;
		}
	}

	/** Compile template */
	function compile($s,$d)
	{
		include_once(dirname(__FILE__).'/class.template_compiler.php');
		$c = new template_compiler;
		return $c->compile($s, $d, array(&$this, "_find_path"));
	}

	/** Sets searchable template paths */
	function set_paths($paths=false) {
		if ($paths===false) {
			$paths = array("templates/");
		}
		if (is_string($paths)) {
			$paths = array($paths);
		}
		$this->_paths = $paths;
	}

	/** Finds first path with existing template file */
	function _find_path($filename) {
		foreach ($this->_paths as $path) {
			// even if only compiled template exists, it's ok, we will just use that
			if (file_exists($path.$filename) || file_exists($path."cache/".$filename)) {
				return $path;
			}
		}
		return false;
	}

	/** Assign data to the template */
	function assign($key,$value='')
	{
		if (is_array($key)) {
			// $key is an array, use $value as prefix if set
			if ($value != '') {
				$value .= '_';
			}
			foreach ($key as $keyname=>$keyval) {
				$this->_vars[$value.$keyname] = $keyval;
			}
		} else {
			// $key is a string, do stuff depending on value and prefix
			$concat = ($key{0}=='.');
			if ($concat) {
				$key = substr($key,1);
			}
			if (strstr($key,'.')!==false) {
				list($array_count,$array_indices) = $this->_explode_helper('.',$key);
				$refval = &$this->_vars;
				foreach ($array_indices as $v) {
					$array_count--;
					if (!isset($refval[$v])) {
						$refval[$v] = (($array_count == 0) ? $value : array());
					} else {
						if ($array_count==0) {
							if (is_array($value)) {
								$refval[$v] = ($concat ? array_merge($refval[$v],$value) : $value);
							} else {
								$refval[$v] = ($concat ? $refval[$v].$value : $value);
							}
						}
					}
					$refval = &$refval[$v];
				}
			} else if (is_array($value)) {
				$this->_vars[$key] = ($concat ? array_merge($this->_vars[$key],$value) : $value);
			} else {
				$this->_vars[$key] = ($concat ? $this->_vars[$key].$value : $value);
			}
		}
		return ""; // {$this->assign} calls, ouch
	}

	/** Render the template to standard output */
	function render()
	{
		include($this->_load_file);
	}

	/** Render the template and return text */
	function get()
	{
		ob_start();
		$this->render();
		$s = ob_get_contents();
		ob_end_clean();
		return $s;
	}
}
