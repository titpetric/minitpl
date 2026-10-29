<?php

declare(strict_types=1);

namespace MiniTPL\Tests;

use MiniTPL\Yaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Yaml::class)]
final class YamlTest extends TestCase
{
	#[DataProvider('parseProvider')]
	public function testParse(string $yaml, mixed $expected): void
	{
		$this->assertSame($expected, Yaml::parse($yaml));
	}

	/** @return iterable<string, array{string, mixed}> */
	public static function parseProvider(): iterable
	{
		yield 'empty document' => ['', null];
		yield 'comments only' => ["# nothing here\n", null];
		yield 'document markers' => ["---\ntitle: t\n...\nignored: x\n", ['title' => 't']];

		yield 'scalars' => [
			"int: 1\nfloat: 1.5\nexponent: 2e3\ntrue: true\nfalse: false\nnull: null\ntilde: ~\ntext: hello\n",
			[
				'int' => 1,
				'float' => 1.5,
				'exponent' => 2000.0,
				'true' => true,
				'false' => false,
				'null' => null,
				'tilde' => null,
				'text' => 'hello',
			],
		];

		yield 'nested mapping' => [
			"user:\n  name: Tit\n  admin: true\n",
			['user' => ['name' => 'Tit', 'admin' => true]],
		];

		yield 'block sequence' => [
			"items:\n  - one\n  - two\n  - 3\n",
			['items' => ['one', 'two', 3]],
		];

		yield 'sequence at the indentation of its key' => [
			"items:\n- one\n- two\n",
			['items' => ['one', 'two']],
		];

		yield 'sequence of mappings' => [
			"items:\n  - name: a\n    tags:\n      - x\n  - name: b\n",
			['items' => [['name' => 'a', 'tags' => ['x']], ['name' => 'b']]],
		];

		yield 'missing values are null' => [
			"a:\nb: 2\n",
			['a' => null, 'b' => 2],
		];

		yield 'comments and hashes' => [
			"# top\na: 1 # one\nb: \"# still text\"\nc: it#s tag\n",
			['a' => 1, 'b' => '# still text', 'c' => 'it#s tag'],
		];

		yield 'quoted scalars' => [
			"a: \"x: y # z\"\nb: 'it''s'\nc: \"tab\\there\"\n",
			['a' => 'x: y # z', 'b' => "it's", 'c' => "tab\there"],
		];

		yield 'quoted keys' => [
			"\"a: b\": 1\n",
			['a: b' => 1],
		];

		yield 'inline list' => [
			"a: [1, two, [3, 4]]\n",
			['a' => [1, 'two', [3, 4]]],
		];

		yield 'inline map' => [
			"a: {x: 1, y: \"q\"}\n",
			['a' => ['x' => 1, 'y' => 'q']],
		];

		yield 'literal block scalar' => [
			"body: |\n  one\n  two\n",
			['body' => "one\ntwo\n"],
		];

		yield 'folded block scalar' => [
			"body: >\n  one\n  two\n\n  four\n",
			['body' => "one two\nfour\n"],
		];

		yield 'block scalar chomping' => [
			"strip: |-\n  x\nkeep: |+\n  y\n\nnext: 1\n",
			['strip' => 'x', 'keep' => "y\n\n", 'next' => 1],
		];

		yield 'top level sequence' => [
			"- a\n- b\n",
			['a', 'b'],
		];

		yield 'windows line endings' => [
			"a: 1\r\nb: 2\r\n",
			['a' => 1, 'b' => 2],
		];
	}

	#[DataProvider('errorProvider')]
	public function testParseRejectsBadYaml(string $yaml, string $message): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage($message);

		Yaml::parse($yaml);
	}

	/** @return iterable<string, array{string, string}> */
	public static function errorProvider(): iterable
	{
		yield 'tab indentation' => ["a:\n\tb: 1\n", 'line 2: tabs are not allowed for indentation'];
		yield 'unterminated quote' => ["a: \"x\n", 'line 1: unterminated quoted string'];
		yield 'unexpected indentation' => ["a: 1\n  b: 2\n", 'line 2: unexpected indentation'];
		yield 'not a pair' => ["a: 1\nb\n", 'line 2: expected a'];
		yield 'unterminated flow list' => ["a: [1, 2\n", 'line 1: unterminated flow collection'];
		yield 'trailing characters after flow list' => ["a: [1] x\n", 'line 1: unexpected content after a flow collection'];
	}

	public function testParseFileReadsYaml(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'minitpl-yaml-');
		$this->assertIsString($file);

		try {
			file_put_contents($file, "title: from a file\n");
			$this->assertSame(['title' => 'from a file'], Yaml::parse_file($file));
		} finally {
			unlink($file);
		}
	}

	public function testParseFileThrowsForMissingFile(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Unable to read yaml file");

		Yaml::parse_file(sys_get_temp_dir() . '/minitpl-missing-' . bin2hex(random_bytes(6)) . '.yml');
	}
}
