<?php

declare(strict_types=1);

namespace MiniTPL\Tests;

use MiniTPL\Compiler;
use MiniTPL\Template;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Template::class)]
#[CoversClass(Compiler::class)]
final class TemplateTest extends TestCase
{
	private const TEMPLATES = 'test/templates/';
	private const TEMPLATES_BROKEN = 'test/templates2/';
	private const COMPILE = 'test/compile/';
	private const EXPECTED = 'test/compiled/';

	/** Compiled output produced by {@see Template::load()} lands next to the templates. */
	private const RUNTIME_COMPILE = self::TEMPLATES . 'test/';

	protected function setUp(): void
	{
		self::rmdir(self::RUNTIME_COMPILE);
	}

	protected function tearDown(): void
	{
		self::rmdir(self::RUNTIME_COMPILE);
		unset($GLOBALS['tpl']);
	}

	#[DataProvider('compileProvider')]
	public function testCompile(string $template): void
	{
		// 12_global_objects.tpl asserts that the compiler detects globals,
		// so $tpl has to be a global object while compiling.
		$GLOBALS['tpl'] = $tpl = $this->template();

		$destination = self::COMPILE . $template;
		$result = $tpl->compile(self::TEMPLATES . $template, $destination);

		$this->assertSame(1, $result, 'compile() should report success');
		$this->assertFileEquals(self::EXPECTED . $template, $destination);
	}

	/** @return iterable<string, array{string}> */
	public static function compileProvider(): iterable
	{
		$templates = glob(__DIR__ . '/templates/*.tpl') ?: [];
		sort($templates);

		foreach ($templates as $template) {
			$name = basename($template);
			yield $name => [$name];
		}
	}

	public function testRenderMatchesGet(): void
	{
		$tpl = $this->template();
		$this->assertTrue($tpl->load('08_utf8_bom.tpl'));

		$this->assign($tpl);

		$returned = $tpl->get();

		ob_start();
		$tpl->render();
		$echoed = ob_get_clean();

		$this->assertNotSame('', $returned);
		$this->assertSame($returned, $echoed);
	}

	public function testStaleCompiledTemplateIsRecompiled(): void
	{
		$tpl = $this->template();
		$this->assertTrue($tpl->load('08_utf8_bom.tpl'));

		$compiled = self::RUNTIME_COMPILE . 'compile/08_utf8_bom.tpl';
		$this->assertFileExists($compiled);

		// Backdate the compiled file so the next load() has to recompile it.
		touch($compiled, filemtime(self::TEMPLATES . '08_utf8_bom.tpl') - 86400);
		$stale = filemtime($compiled);

		$this->assertTrue($tpl->load('08_utf8_bom.tpl'));
		clearstatcache(true, $compiled);
		$this->assertGreaterThan($stale, filemtime($compiled));

		$this->assign($tpl);
		$this->assertNotSame('', $tpl->get());
	}

	public function testFindPathReturnsFalseForUnknownTemplate(): void
	{
		$this->assertFalse($this->template()->_find_path('404.tpl'));
	}

	#[DataProvider('compileLocationProvider')]
	public function testCompilePath(string $location, bool $absolute, string $expected): void
	{
		$tpl = $this->template();
		$tpl->set_compile_location($location, $absolute);

		$this->assertSame($expected, $tpl->_compile_path(self::TEMPLATES));
	}

	/** @return iterable<string, array{string, bool, string}> */
	public static function compileLocationProvider(): iterable
	{
		yield 'relative' => ['test/compile/', false, 'test/templates/test/compile/'];
		yield 'absolute' => ['/test/compile/', true, '/test/compile/test/templates/'];
	}

	public function testGetVar(): void
	{
		$tpl = new Template();
		$tpl->assign('foo', 'bar');

		$this->assertSame('bar', $tpl->getVar('foo'));
		$this->assertFalse($tpl->getVar('missing'));
	}

	public function testLoadThrowsWhenTemplateCannotBeCompiled(): void
	{
		$tpl = new Template();
		$tpl->set_compile_location(self::COMPILE, false);
		$tpl->set_paths(self::TEMPLATES_BROKEN);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage("Template file 'fail_to_compile.tpl' doesn't exist!");

		$tpl->load('fail_to_compile.tpl');
	}

	public function testRenderThrowsWhenNothingWasLoaded(): void
	{
		$tpl = new Template();

		$this->assertFalse($tpl->load('missing.tpl'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage("Filename can't be empty, tried to render 'missing.tpl'");

		$tpl->render();
	}

	#[DataProvider('varsProvider')]
	public function testSplitExpression(string $expression, string $expected, string $description): void
	{
		// $tpl is a global object, $tplx is not: the compiler treats them differently.
		$GLOBALS['tpl'] = new Compiler();

		$this->assertSame($expected, $GLOBALS['tpl']->_split_exp($expression), $description);
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function varsProvider(): iterable
	{
		yield 'normal string' => ['news_section_news_list.tpl', 'news_section_news_list.tpl', 'normal string'];
		yield 'variable' => ['$var', "\$_v['var']", 'variable'];
		yield 'array index' => ['$var.netko', "\$_v['var']['netko']", 'array index'];
		yield 'string concat' => ['$var . "netko"', "\$_v['var'] . \"netko\"", 'string concat'];
		yield 'variable concat' => ['$var1 . $var2', "\$_v['var1'] . \$_v['var2']", 'variable concat'];
		yield 'array var index' => ['$var1.$var2', "\$_v['var1'][\$_v['var2']]", 'array var index'];
		yield 'array int index' => ['$items.0', "\$_v['items']['0']", 'array int index'];
		yield 'global function' => ['$tpl->get()', '$tpl->get()', 'global function'];
		yield 'object function' => ['$tplx->get()', "\$_v['tplx']->get()", 'object function'];
	}

	/** A template configured against the test fixtures. */
	private function template(): Template
	{
		$tpl = new Template();
		$tpl->set_paths(self::TEMPLATES);
		$tpl->set_compile_location(self::COMPILE, false);
		$tpl->add_default('key', 'val');

		return $tpl;
	}

	/** The variables the fixture templates expect. */
	private function assign(Template $tpl): void
	{
		$tpl->assign('items', [['id' => 1], ['id' => 2], ['id' => 3]]);
		$tpl->assign(['foo' => 'bar', 'd' => ['burger']], 'foo');
		$tpl->assign('.foo_foo', 'baz');
		$tpl->assign('.foo_d', ['steak', 'beef', 'pork', 'chicken']);
	}

	private static function rmdir(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$child = $path . '/' . $entry;
			is_dir($child) ? self::rmdir($child) : unlink($child);
		}

		rmdir($path);
	}
}
