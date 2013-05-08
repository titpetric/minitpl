<?php

class TemplateTest extends PHPUnit_Framework_TestCase
{
	/**
	 * @dataProvider compileProvider
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

	public function compileProvider()
	{
		$tests = array();
		$templates = glob("test/templates/*.tpl");
		sort($templates);
		foreach ($templates as $template) {
			$tests[] = array(basename($template));
		}
		return $tests;
	}

	/**
	 * @dataProvider varsProvider
	 */
	public function testVars($expression, $expected)
	{
		$tpl = new Monotek\MiniTPL\Compiler;

		$result = $tpl->_split_exp($expression);
		$this->assertEquals($expected, $result);
	}

	public function varsProvider()
	{
		$vars = array();
		$vars[] = array('news_section_news_list.tpl', 'news_section_news_list.tpl');
		$vars[] = array('$var', "\$_v['var']");
		$vars[] = array('$var.netko', "\$_v['var']['netko']");
		$vars[] = array('$var . "netko"', "\$_v['var'] . \"netko\"");
		$vars[] = array('$var1 . $var2', "\$_v['var1'] . \$_v['var2']");
		$vars[] = array('$var1.$var2', "\$_v['var1'][\$_v['var2']]");
		$vars[] = array('$items.0', "\$_v['items']['0']");
		return $vars;
	}
}
