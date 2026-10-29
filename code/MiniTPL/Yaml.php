<?php

namespace MiniTPL;

/*

Tit Petric <black@titpetric.com>, MIT license, see LICENSE.

 */

/**
 * Yaml reads the subset of yaml that template data uses.
 *
 * MiniTPL carries no dependencies, so the command line reads yaml itself
 * instead of pulling a yaml library in. The reader supports mappings,
 * sequences, nesting by indentation, plain and quoted scalars, inline
 * [lists] and {maps}, | and > block scalars, and comments. Anchors,
 * aliases, tags and documents after the first "---" are not supported.
 */
class Yaml {
	/** Text of every line, without the line ending */
	protected $lines = array();
	/** Source line number of every line, for error messages */
	protected $numbers = array();
	/** Number of lines in the document */
	protected $count = 0;
	/** The line being read */
	protected $index = 0;
	/** The line holding the value being read, for error messages */
	protected $line = null;

	/** Parse yaml text into php values */
	static function parse($yaml) {
		$reader = new Yaml();
		return $reader->read($yaml);
	}

	/** Parse a yaml file into php values */
	static function parse_file($filename) {
		$contents = @file_get_contents($filename);
		if ($contents === false) {
			throw new \RuntimeException("Unable to read yaml file '" . $filename . "'");
		}

		return self::parse($contents);
	}

	/** Read a yaml document */
	function read($yaml) {
		$yaml = str_replace(array("\r\n", "\r"), "\n", (string)$yaml);
		$this->lines = explode("\n", $yaml);
		$this->numbers = array();
		foreach ($this->lines as $number => $line) {
			$this->numbers[] = $number + 1;
		}

		$this->count = count($this->lines);
		$this->index = 0;

		// A second document starts after the content seen so far. Only the
		// first one is read, which is where template data lives.
		$seen = false;
		for ($i = 0; $i < $this->count; $i++) {
			$text = trim($this->lines[$i]);
			if ($text === "" || substr($text, 0, 1) === "#" || substr($text, 0, 1) === "%") {
				continue;
			}

			if ($text === "---") {
				if ($seen) {
					$this->count = $i;
					break;
				}

				// A document that opens with a marker starts with a blank
				// line, so the parser below does not read it as content.
				$this->lines[$i] = "";
				continue;
			}

			if ($text === "...") {
				$this->count = $i;
				break;
			}

			$seen = true;
		}

		$value = $this->_block(0);
		$this->_skip();
		if ($this->index < $this->count) {
			$this->_fail("unexpected content", $this->index);
		}

		return $value;
	}

	/** Read the block that starts at the current line, at the given indentation */
	function _block($indent) {
		$this->_skip();
		if ($this->index >= $this->count) {
			return null;
		}

		$line_indent = $this->_indent($this->index);
		if ($line_indent > $indent) {
			// The first line of a block sets the indentation of the document.
			$indent = $line_indent;
		}

		if ($this->_is_item($this->_text($this->index))) {
			return $this->_sequence($indent);
		}

		return $this->_mapping($indent);
	}

	/**
	 * Read a mapping.
	 *
	 * $first holds an already parsed pair, for a sequence item written as
	 * "- key: value", whose lines continue at the indentation of the key.
	 */
	function _mapping($indent, $first = false) {
		$map = array();

		if ($first !== false) {
			list($key, $rest) = $first;
			$map[$key] = $this->_value($indent, $rest);
		}

		while (true) {
			$this->_skip();
			if ($this->index >= $this->count) {
				break;
			}

			$line_indent = $this->_indent($this->index);
			if ($line_indent < $indent) {
				break;
			}

			if ($line_indent > $indent) {
				$this->_fail("unexpected indentation", $this->index);
			}

			$pair = $this->_split_key($this->_text($this->index));
			if ($pair === false) {
				$this->_fail("expected a 'key: value' pair", $this->index);
			}

			$this->line = $this->index;
			$this->index++;
			list($key, $rest) = $pair;
			$map[$key] = $this->_value($indent, $rest);
		}

		return $map;
	}

	/** Read a sequence */
	function _sequence($indent) {
		$list = array();

		while (true) {
			$this->_skip();
			if ($this->index >= $this->count) {
				break;
			}

			$line_indent = $this->_indent($this->index);
			if ($line_indent < $indent) {
				break;
			}

			if ($line_indent > $indent) {
				$this->_fail("unexpected indentation", $this->index);
			}

			$text = $this->_text($this->index);
			if (!$this->_is_item($text)) {
				break;
			}

			$rest = ltrim(substr($text, 1));
			$item_indent = $indent + strlen($text) - strlen($rest);
			$this->line = $this->index;
			$this->index++;

			if ($rest === "") {
				$this->_skip();
				if ($this->index < $this->count && $this->_indent($this->index) > $indent) {
					$list[] = $this->_block($this->_indent($this->index));
				} else {
					$list[] = null;
				}

				continue;
			}

			$pair = $this->_split_key($rest);
			if ($pair !== false) {
				$list[] = $this->_mapping($item_indent, $pair);
				continue;
			}

			$list[] = $this->_scalar($rest);
		}

		return $list;
	}

	/** Read the value of a key from the text that follows its colon */
	function _value($indent, $rest) {
		$rest = trim($rest);

		if ($rest === "") {
			$this->_skip();
			if ($this->index < $this->count) {
				$line_indent = $this->_indent($this->index);

				if ($line_indent > $indent) {
					return $this->_block($line_indent);
				}

				// A sequence may sit at the indentation of its key:
				//   items:
				//   - one
				if ($line_indent === $indent && $this->_is_item($this->_text($this->index))) {
					return $this->_sequence($indent);
				}
			}

			return null;
		}

		if ($this->_is_block_scalar($rest)) {
			return $this->_block_scalar($indent, $rest);
		}

		return $this->_scalar($rest);
	}

	/** Split "key: value" into a key and the text after the colon */
	function _split_key($text, $flow = false) {
		$length = strlen($text);
		if ($length === 0) {
			return false;
		}

		if ($text[0] === '"' || $text[0] === "'") {
			$end = $this->_quote_end($text, 0);
			if ($end === false) {
				return false;
			}

			$after = $end + 1;
			while ($after < $length && $text[$after] === " ") {
				$after++;
			}

			if ($after >= $length || $text[$after] !== ":") {
				return false;
			}

			return array($this->_unquote(substr($text, 0, $end + 1)), substr($text, $after + 1));
		}

		$in_single = false;
		$in_double = false;
		$depth = 0;
		for ($i = 0; $i < $length; $i++) {
			$c = $text[$i];
			if ($in_single) {
				if ($c === "'") {
					if ($i + 1 < $length && $text[$i + 1] === "'") {
						$i++;
					} else {
						$in_single = false;
					}
				}

				continue;
			}

			if ($in_double) {
				if ($c === "\\") {
					$i++;
				} elseif ($c === '"') {
					$in_double = false;
				}

				continue;
			}

			if ($c === "'") {
				$in_single = true;
				continue;
			}

			if ($c === '"') {
				$in_double = true;
				continue;
			}

			if ($c === "[" || $c === "{") {
				$depth++;
				continue;
			}

			if ($c === "]" || $c === "}") {
				$depth--;
				continue;
			}

			if ($c === ":" && $depth === 0 && ($flow || $i + 1 === $length || $text[$i + 1] === " ")) {
				$key = trim(substr($text, 0, $i));
				if ($key === "") {
					return false;
				}

				return array($key, substr($text, $i + 1));
			}
		}

		return false;
	}

	/** Read a scalar: a quoted string, a flow collection or a plain value */
	function _scalar($text) {
		$text = trim($this->_strip_comment($text));

		$length = strlen($text);
		if ($length === 0) {
			return null;
		}

		if ($text[0] === '"' || $text[0] === "'") {
			$end = $this->_quote_end($text, 0);
			if ($end === false) {
				$this->_fail("unterminated quoted string");
			}

			if (trim(substr($text, $end + 1)) !== "") {
				$this->_fail("unexpected content after a quoted string");
			}

			return $this->_unquote($text);
		}

		if ($text[0] === "[" || $text[0] === "{") {
			return $this->_flow($text);
		}

		$lower = strtolower($text);
		if ($text === "~" || $lower === "null") {
			return null;
		}

		if ($lower === "true") {
			return true;
		}

		if ($lower === "false") {
			return false;
		}

		if (preg_match('/^[-+]?[0-9]+$/', $text)) {
			return (int)$text;
		}

		if (preg_match('/^[-+]?([0-9]+\.[0-9]*|\.[0-9]+)([eE][-+]?[0-9]+)?$/', $text) || preg_match('/^[-+]?[0-9]+[eE][-+]?[0-9]+$/', $text)) {
			return (float)$text;
		}

		return $text;
	}

	/** Read an inline [list] or {map} */
	function _flow($text) {
		if ($text[0] === "[") {
			$end = $this->_flow_end($text, "[", "]");
			$inner = trim(substr($text, 1, $end - 1));
			$list = array();
			if ($inner === "") {
				return $list;
			}

			foreach ($this->_split_flow($inner) as $part) {
				$list[] = $this->_scalar($part);
			}

			return $list;
		}

		$end = $this->_flow_end($text, "{", "}");
		$inner = trim(substr($text, 1, $end - 1));
		$map = array();
		if ($inner === "") {
			return $map;
		}

		foreach ($this->_split_flow($inner) as $part) {
			$pair = $this->_split_key($part, true);
			if ($pair === false) {
				$this->_fail("expected a 'key: value' pair in a flow mapping");
			}

			$map[$this->_key($this->_scalar($pair[0]))] = $this->_scalar($pair[1]);
		}

		return $map;
	}

	/** Find the closing bracket of a flow collection, at the top level */
	function _flow_end($text, $open, $close) {
		$length = strlen($text);
		$depth = 0;
		$in_single = false;
		$in_double = false;

		for ($i = 0; $i < $length; $i++) {
			$c = $text[$i];
			if ($in_single) {
				if ($c === "'") {
					if ($i + 1 < $length && $text[$i + 1] === "'") {
						$i++;
					} else {
						$in_single = false;
					}
				}

				continue;
			}

			if ($in_double) {
				if ($c === "\\") {
					$i++;
				} elseif ($c === '"') {
					$in_double = false;
				}

				continue;
			}

			if ($c === "'") {
				$in_single = true;
			} elseif ($c === '"') {
				$in_double = true;
			} elseif ($c === $open) {
				$depth++;
			} elseif ($c === $close) {
				$depth--;
				if ($depth === 0) {
					if (trim($this->_strip_comment(substr($text, $i + 1))) !== "") {
						$this->_fail("unexpected content after a flow collection");
					}

					return $i;
				}
			}
		}

		$this->_fail("unterminated flow collection");
	}

	/** Split the inside of a flow collection on top level commas */
	function _split_flow($text) {
		$parts = array();
		$start = 0;
		$length = strlen($text);
		$depth = 0;
		$in_single = false;
		$in_double = false;

		for ($i = 0; $i < $length; $i++) {
			$c = $text[$i];
			if ($in_single) {
				if ($c === "'") {
					if ($i + 1 < $length && $text[$i + 1] === "'") {
						$i++;
					} else {
						$in_single = false;
					}
				}

				continue;
			}

			if ($in_double) {
				if ($c === "\\") {
					$i++;
				} elseif ($c === '"') {
					$in_double = false;
				}

				continue;
			}

			if ($c === "'") {
				$in_single = true;
			} elseif ($c === '"') {
				$in_double = true;
			} elseif ($c === "[" || $c === "{") {
				$depth++;
			} elseif ($c === "]" || $c === "}") {
				$depth--;
			} elseif ($c === "," && $depth === 0) {
				$parts[] = substr($text, $start, $i - $start);
				$start = $i + 1;
			}
		}

		$parts[] = substr($text, $start);
		return $parts;
	}

	/** Whether a value follows a | or > block scalar header */
	function _is_block_scalar($text) {
		return preg_match('/^[|>]([+-][0-9]?|[0-9][+-]?)?$/', $text) === 1;
	}

	/** Read a | literal or > folded block scalar */
	function _block_scalar($parent_indent, $header) {
		$literal = $header[0] === "|";
		$chomp = "";
		if (strpos($header, "-") !== false) {
			$chomp = "-";
		} elseif (strpos($header, "+") !== false) {
			$chomp = "+";
		}

		$lines = array();
		$content_indent = null;

		while ($this->index < $this->count) {
			$line = $this->lines[$this->index];
			if (trim($line) === "") {
				$lines[] = "";
				$this->index++;
				continue;
			}

			$indent = $this->_indent($this->index);
			if ($indent <= $parent_indent) {
				break;
			}

			if ($content_indent === null) {
				$content_indent = $indent;
			}

			if ($indent < $content_indent) {
				$this->_fail("a block scalar line is less indented than the first", $this->index);
			}

			$lines[] = substr($line, $content_indent);
			$this->index++;
		}

		// Empty lines at the end belong to the scalar, and to its chomping.
		$trailing = 0;
		while (count($lines) > 0 && end($lines) === "") {
			array_pop($lines);
			$trailing++;
		}

		if (count($lines) === 0) {
			return $chomp === "+" ? str_repeat("\n", $trailing) : "";
		}

		$text = $literal ? implode("\n", $lines) : $this->_fold($lines);

		if ($chomp === "-") {
			return $text;
		}

		if ($chomp === "+") {
			return $text . "\n" . str_repeat("\n", $trailing);
		}

		return $text . "\n";
	}

	/** Fold the lines of a > block scalar */
	function _fold($lines) {
		$text = "";
		$previous = false;
		foreach ($lines as $line) {
			if ($line === "") {
				$text .= "\n";
				$previous = false;
			} else {
				$text .= ($previous ? " " : "") . $line;
				$previous = true;
			}
		}

		return $text;
	}

	/** Remove a trailing comment from plain text */
	function _strip_comment($text) {
		$length = strlen($text);
		$in_single = false;
		$in_double = false;

		for ($i = 0; $i < $length; $i++) {
			$c = $text[$i];
			if ($in_single) {
				if ($c === "'") {
					if ($i + 1 < $length && $text[$i + 1] === "'") {
						$i++;
					} else {
						$in_single = false;
					}
				}

				continue;
			}

			if ($in_double) {
				if ($c === "\\") {
					$i++;
				} elseif ($c === '"') {
					$in_double = false;
				}

				continue;
			}

			if ($c === "'") {
				$in_single = true;
			} elseif ($c === '"') {
				$in_double = true;
			} elseif ($c === "#" && ($i === 0 || $text[$i - 1] === " " || $text[$i - 1] === "\t")) {
				return rtrim(substr($text, 0, $i));
			}
		}

		return $text;
	}

	/** Find the quote that ends the string starting at $start */
	function _quote_end($text, $start) {
		$quote = $text[$start];
		$length = strlen($text);

		for ($i = $start + 1; $i < $length; $i++) {
			$c = $text[$i];
			if ($quote === '"' && $c === "\\") {
				$i++;
				continue;
			}

			if ($c === $quote) {
				if ($quote === "'" && $i + 1 < $length && $text[$i + 1] === "'") {
					$i++;
					continue;
				}

				return $i;
			}
		}

		return false;
	}

	/** Resolve the escapes of a quoted string */
	function _unquote($text) {
		$quote = $text[0];
		$inner = substr($text, 1, strlen($text) - 2);

		if ($quote === "'") {
			return str_replace("''", "'", $inner);
		}

		$out = "";
		$length = strlen($inner);
		for ($i = 0; $i < $length; $i++) {
			$c = $inner[$i];
			if ($c !== "\\" || $i + 1 >= $length) {
				$out .= $c;
				continue;
			}

			$next = $inner[++$i];
			switch ($next) {
				case "0":
					$out .= "\0";
					break;
				case "a":
					$out .= "\x07";
					break;
				case "b":
					$out .= "\x08";
					break;
				case "t":
					$out .= "\t";
					break;
				case "n":
					$out .= "\n";
					break;
				case "v":
					$out .= "\x0B";
					break;
				case "f":
					$out .= "\x0C";
					break;
				case "r":
					$out .= "\r";
					break;
				case "e":
					$out .= "\x1B";
					break;
				case " ":
					$out .= " ";
					break;
				case '"':
					$out .= '"';
					break;
				case "/":
					$out .= "/";
					break;
				case "\\":
					$out .= "\\";
					break;
				case "x":
					$out .= $this->_byte(substr($inner, $i + 1, 2));
					$i += 2;
					break;
				case "u":
					$out .= $this->_utf8(substr($inner, $i + 1, 4));
					$i += 4;
					break;
				case "U":
					$out .= $this->_utf8(substr($inner, $i + 1, 8));
					$i += 8;
					break;
				default:
					$out .= $next;
			}
		}

		return $out;
	}

	/** Whether a string is made of hexadecimal digits */
	function _is_hex($hex) {
		return $hex !== "" && preg_match('/^[0-9a-fA-F]+$/', $hex) === 1;
	}

	/** Encode a two digit hex escape */
	function _byte($hex) {
		if (strlen($hex) !== 2 || !$this->_is_hex($hex)) {
			$this->_fail("invalid \\x escape");
		}

		return chr(hexdec($hex));
	}

	/** Encode a unicode code point as utf-8 */
	function _utf8($hex) {
		if (!$this->_is_hex($hex)) {
			$this->_fail("invalid \\u escape");
		}

		$code = hexdec($hex);
		if ($code < 0x80) {
			return chr($code);
		}

		if ($code < 0x800) {
			return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
		}

		if ($code < 0x10000) {
			return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
		}

		return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
	}

	/** A scalar used as a mapping key */
	function _key($value) {
		if (is_string($value) || is_int($value)) {
			return $value;
		}

		if (is_bool($value)) {
			return $value ? "true" : "false";
		}

		return "";
	}

	/** Whether the line is a sequence item */
	function _is_item($text) {
		return $text === "-" || substr($text, 0, 2) === "- ";
	}

	/** Line content without its indentation */
	function _text($index) {
		return rtrim(ltrim($this->lines[$index], " "));
	}

	/** Indentation of a line */
	function _indent($index) {
		$line = $this->lines[$index];
		$indent = strlen($line) - strlen(ltrim($line, " "));
		if ($indent < strlen($line) && $line[$indent] === "\t") {
			$this->_fail("tabs are not allowed for indentation", $index);
		}

		return $indent;
	}

	/** Advance past blank and comment lines */
	function _skip() {
		while ($this->index < $this->count) {
			$text = trim($this->lines[$this->index]);
			if ($text === "" || substr($text, 0, 1) === "#") {
				$this->index++;
				continue;
			}

			break;
		}
	}

	/** Throw a parse error that names the line */
	function _fail($message, $index = null) {
		if ($index === null) {
			$index = ($this->line === null) ? $this->index : $this->line;
		}

		$number = isset($this->numbers[$index]) ? $this->numbers[$index] : $this->count;
		throw new \RuntimeException("yaml: line " . $number . ": " . $message);
	}
}
