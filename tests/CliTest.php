<?php

declare(strict_types=1);

namespace MiniTPL\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises cmd/minitpl as a process, the way a build or a person calls it.
 */
final class CliTest extends TestCase
{
	private string $root;
	private string $cli;

	protected function setUp(): void
	{
		$this->cli = dirname(__DIR__) . '/cmd/minitpl';
		$this->root = sys_get_temp_dir() . '/minitpl-cli-' . bin2hex(random_bytes(8));

		$this->write('templates/page.tpl', "<h1>{title}</h1>\n{include footer.tpl}\n");
		$this->write('templates/footer.tpl', "<footer>{year}</footer>\n");
		$this->write('templates/partials/note.tpl', "<p>{note}</p>\n");
		$this->write('data.yml', "title: Hello\nyear: 2026\n");
	}

	protected function tearDown(): void
	{
		self::rmdir($this->root);
	}

	public function testCompileWritesTemplatesC(): void
	{
		[$status, $stdout, $stderr] = $this->runCli(['compile', $this->root . '/templates']);

		$this->assertSame(0, $status, $stderr);
		$this->assertFileExists($this->root . '/templates_c/page.tpl');
		$this->assertFileExists($this->root . '/templates_c/footer.tpl');
		$this->assertFileExists($this->root . '/templates_c/partials/note.tpl');

		$compiled = (string) file_get_contents($this->root . '/templates_c/page.tpl');
		$this->assertStringContainsString("\$_v['title']", $compiled);
		$this->assertStringContainsString('<footer>', $compiled, 'the include is expanded at compile time');
		$this->assertStringContainsString('compiled 3 templates into ' . $this->root . '/templates_c', $stdout);
	}

	public function testCompileIntoExplicitOutputQuietly(): void
	{
		[$status, $stdout, $stderr] = $this->runCli([
			'compile', '--quiet', $this->root . '/templates', $this->root . '/build',
		]);

		$this->assertSame(0, $status, $stderr);
		$this->assertSame('', $stdout);
		$this->assertFileExists($this->root . '/build/page.tpl');
		$this->assertDirectoryDoesNotExist($this->root . '/templates_c');
	}

	public function testCompileReportsAnUncompilableTemplate(): void
	{
		$this->write('templates/empty.tpl', '');

		[$status, , $stderr] = $this->runCli(['compile', '-q', $this->root . '/templates']);

		$this->assertSame(1, $status);
		$this->assertStringContainsString("failed to compile '" . $this->root . "/templates/empty.tpl'", $stderr);
		$this->assertFileExists($this->root . '/templates_c/page.tpl', 'the other templates still compile');
	}

	public function testCompileNeedsADirectory(): void
	{
		[$status, , $stderr] = $this->runCli(['compile', $this->root . '/missing']);

		$this->assertSame(1, $status);
		$this->assertStringContainsString("templates directory '" . $this->root . "/missing' does not exist", $stderr);
	}

	public function testRenderWithDataFile(): void
	{
		[$status, $stdout, $stderr] = $this->runCli([
			'render', $this->root . '/templates/page.tpl', $this->root . '/data.yml',
		]);

		$this->assertSame(0, $status, $stderr);
		$this->assertSame("<h1>Hello</h1>\n<footer>2026</footer>\n\n", $stdout);
		$this->assertFileExists(
			$this->root . '/templates_c/page.tpl',
			'rendering compiles beside the template directory, without configuration'
		);
	}

	public function testRenderReadsDataFromStandardInput(): void
	{
		[$status, $stdout, $stderr] = $this->runCli(
			['render', $this->root . '/templates/page.tpl'],
			"title: Piped\nyear: 1\n"
		);

		$this->assertSame(0, $status, $stderr);
		$this->assertSame("<h1>Piped</h1>\n<footer>1</footer>\n\n", $stdout);
	}

	public function testRenderReadsDashAsStandardInput(): void
	{
		[$status, $stdout, $stderr] = $this->runCli(
			['render', $this->root . '/templates/page.tpl', '-'],
			"title: Dash\nyear: 2\n"
		);

		$this->assertSame(0, $status, $stderr);
		$this->assertSame("<h1>Dash</h1>\n<footer>2</footer>\n\n", $stdout);
	}

	public function testRenderEscapesValuesInMarkup(): void
	{
		[$status, $stdout, $stderr] = $this->runCli(
			['render', $this->root . '/templates/page.tpl'],
			"title: \"<b>x</b>\"\nyear: 3\n"
		);

		$this->assertSame(0, $status, $stderr);
		$this->assertStringContainsString('<h1>&lt;b&gt;x&lt;/b&gt;</h1>', $stdout);
	}

	public function testRenderNeedsAnExistingTemplate(): void
	{
		[$status, , $stderr] = $this->runCli(['render', $this->root . '/missing.tpl']);

		$this->assertSame(1, $status);
		$this->assertStringContainsString("template file '" . $this->root . "/missing.tpl' does not exist", $stderr);
	}

	public function testRenderRejectsInvalidYaml(): void
	{
		[$status, , $stderr] = $this->runCli(
			['render', $this->root . '/templates/page.tpl'],
			"title: [1, 2\n"
		);

		$this->assertSame(1, $status);
		$this->assertStringContainsString('yaml: line 1: unterminated flow collection', $stderr);
	}

	public function testRenderRejectsDataThatIsNotAMapping(): void
	{
		[$status, , $stderr] = $this->runCli(
			['render', $this->root . '/templates/page.tpl'],
			"- one\n- two\n"
		);

		$this->assertSame(1, $status);
		$this->assertStringContainsString('template data has to be a yaml mapping', $stderr);
	}

	public function testRenderRejectsAMissingDataFile(): void
	{
		[$status, , $stderr] = $this->runCli([
			'render', $this->root . '/templates/page.tpl', $this->root . '/missing.yml',
		]);

		$this->assertSame(1, $status);
		$this->assertStringContainsString('Unable to read yaml file', $stderr);
	}

	public function testUnknownOptionIsAnError(): void
	{
		[$status, , $stderr] = $this->runCli(['render', '--bogus', $this->root . '/templates/page.tpl']);

		$this->assertSame(1, $status);
		$this->assertStringContainsString("unknown option '--bogus'", $stderr);
	}

	public function testHelp(): void
	{
		[$status, $stdout] = $this->runCli(['help']);

		$this->assertSame(0, $status);
		$this->assertStringContainsString('Usage:', $stdout);
		$this->assertStringContainsString('minitpl compile', $stdout);
	}

	public function testCompileHelpDoesNotCompile(): void
	{
		[$status, $stdout] = $this->runCli(['compile', '--help', $this->root . '/templates']);

		$this->assertSame(0, $status);
		$this->assertStringContainsString('Usage:', $stdout);
		$this->assertDirectoryDoesNotExist($this->root . '/templates_c');
	}

	/**
	 * Run the command line with arguments and standard input.
	 *
	 * @param list<string> $args
	 * @return array{int, string, string} status, stdout, stderr
	 */
	private function runCli(array $args, string $stdin = ''): array
	{
		$command = array_merge([PHP_BINARY, $this->cli], $args);
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($command, $descriptors, $pipes);
		$this->assertIsResource($process);

		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);

		$stdout = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		return [proc_close($process), $stdout, $stderr];
	}

	private function write(string $relative, string $contents): void
	{
		$path = $this->root . '/' . $relative;
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}

		file_put_contents($path, $contents);
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
