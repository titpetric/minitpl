<?php

namespace MiniTPL;

abstract class Hook {
	const POSITION_PRE = "pre";
	const POSITION_POST = "post";

	abstract function execute($filename, $contents);
}
