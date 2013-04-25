<?php

/*

Tit Petrič, Monotek d.o.o., (cc) 2008, tit.petric@monotek.net
http://creativecommons.org/licenses/by-sa/3.0/

*/

/** Template class */
class minitpl
{
	/** Holds search paths */
	var $_paths;
	/** Compile location, relative or absolute */
	var $_compile_location, $_compile_absolute;
	/** Defaults */
	var $_defaults;

	/** Hold assigned values, filenames, stack */
	private $stack = array();
	private $filename;
	private $vars;

	/** Default constructor */
	function minitpl($paths=false)
	{
		$this->set_compile_location("cache/", false);
		$this->set_paths($paths);
		$this->_defaults = array(array("ldelim","{"), array("rdelim","}"));
		$this->_nocache = false;
	}

	function add_default($k,$v)
	{
		$this->_defaults[] = array($k,$v);
	}

	function _default_vars()
	{
		if (empty($this->stack)) {
			$this->vars = array();
		}
		$this->filename = false;
		foreach ($this->_defaults as $v) {
			$this->assign($v[0],$v[1]);
		}
	}

	function push()
	{
		$this->stack[] = $this->filename;
	}

	function pop()
	{
		list($this->filename) = array_splice($this->stack, -1);
	}

	/** Template loader */
	function load($filename)
	{
		$r = 0;
		$this->_default_vars();
		if (($path = $this->_find_path($filename))!==false) {
			$f_original = $path.$filename;
			$f_compiled = $this->_compile_path($path).$filename;
			if (file_exists($f_compiled)) {
				$r = 1;
				if (file_exists($f_original) && (filemtime($f_original) > filemtime($f_compiled))) {
					$r = $this->compile($f_original, $f_compiled);
				}
			} else {
				$r = $this->compile($f_original, $f_compiled);
			}
			$this->filename = $f_compiled;
			if (!$r) {
				echo "Template file ".$filename." doesn't exist! Is the compile dir writeable?\n";
				die;
			}
		}
		return (bool)$r;
	}

	/** Compile template */
	function compile($s,$d)
	{
		include_once(dirname(__FILE__).'/class.minitpl_compiler.php');
		$c = new minitpl_compiler;
		return $c->compile($s,$d,array(&$this,"_find_path"),$this->_nocache);
	}

	/** Sets searchable template paths */
	function set_paths($paths=false) {
		if ($paths===false) {
			$paths = array("templates/");
		}
		if (is_string($paths)) {
			$paths = func_get_args();
		}
		$this->_paths = $paths;
	}

	/** Sets compile location */
	function set_compile_location($path, $is_absolute)
	{
		$this->_compile_location = rtrim($path,"/")."/";
		$this->_compile_absolute = $is_absolute;
	}

	/** Compile path calculation */
	function _compile_path($path)
	{
		if ($this->_compile_absolute) {
			return $this->_compile_location.$path;
		}
		return $path.$this->_compile_location;
	}

	/** Finds first path with existing template file */
	function _find_path($filename) {
		foreach ($this->_paths as $path) {
			// even if only compiled template exists, it's ok
			if (file_exists($path.$filename) || file_exists($this->_compile_path($path).$filename)) {
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
			foreach ($key as $k=>$v) {
				$this->vars[$value.$k] = $v;
			}
		} else {
			// $key is a string, do stuff depending on value and prefix
			$concat = ($key{0}=='.');
			if ($concat) {
				$key = substr($key,1);
			}
			$this->vars[$key] = ($concat ? (is_array($value) ? array_merge($this->vars[$key],$value) : $this->vars[$key].$value) : $value);
		}
		return ""; // {$this->assign} calls, ouch
	}

	/** Render the template to standard output */
	function render()
	{
		include($this->filename);
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