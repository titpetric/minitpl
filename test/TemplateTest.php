<?php

class TemplateTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @dataProvider provider
	 */
	public function testCompile($template)
	{
		$destination = "test/compile/".$template;
		$compiled = "test/compiled/".$template;

		$tpl = new Monotek\MiniTPL\Template;

		$tpl->set_paths("test/templates/");
		$tpl->set_compile_location("test/compile/", false);

		$source = "test/templates/".$template;
		$return = $tpl->compile($source, $destination);

		$this->assertTrue((bool)$return);
		$this->assertFileEquals($destination, $compiled);
	}

	public function provider()
	{
		$tests = array();
		$templates = glob("test/templates/*.tpl");
		sort($templates);
		foreach ($templates as $template) {
			$tests[] = array(basename($template));
		}
		return $tests;
	}
}
