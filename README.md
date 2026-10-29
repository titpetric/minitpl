# MiniTPL

The goal of the MiniTPL template engine is to provide a miniature
framework which allows you to rapidly create and consume
Smarty-like templates without adding the overhead of Smarty to
your choice of a PHP framework.

In benchmarks the speed of Mini TPL is very close to PHP itself.
All that is usually needed for Mini TPL is a 3KB PHP code overhead.
So it beats Smarty, and usual PHP vsprintf and str_replace functionality.

With a total size of about 13KB and the functionality contained, this is
one of the smallest full featured template engines for PHP to date.

MiniTPL is available on [packagist as titpetric/minitpl](https://packagist.org/packages/titpetric/minitpl).

To start using MiniTPL in your project with [composer](http://getcomposer.org/):

```sh
composer require titpetric/minitpl:^1.3
```

You can start using MiniTPL right away.

```php
<?php

include("vendor/autoload.php");

$tpl = new MiniTPL\Template;

$tpl->load("test.tpl");
$tpl->render();
```

The classes live in the `MiniTPL` namespace: `MiniTPL\Template`,
`MiniTPL\Compiler` and `MiniTPL\Hook`. `Compiler` and `Hook` are usable
stand-alone; `Template` is the entrypoint you normally want.

Compiled templates are written to a `templates_c/` directory next to the
`templates/` directory, so the default `Template` needs a writable project
directory and no configuration. `set_compile_location()` moves them.

## Command line

`cmd/minitpl` compiles and renders templates without a php script of your
own. When the package is installed with composer, the command is
`vendor/bin/minitpl`; in a checkout it is `cmd/minitpl`.

```sh
# transpile every template under templates/ into templates_c/
vendor/bin/minitpl compile templates

# render a template with data from a yaml file
vendor/bin/minitpl render templates/page.tpl data.yml

# the same, with the data piped in on standard input
cat data.yml | vendor/bin/minitpl render templates/page.tpl
```

`compile` keeps the directory structure of the source, resolves `{include}`
against it, and writes each template beside it: `templates/` compiles into
`templates_c/`. `-o DIR` writes elsewhere and `-q` prints nothing but
errors.

`render` reads a yaml mapping into template variables and prints the result
to standard output. The reader covers the subset of yaml that template data
uses: mappings, sequences, quoted and plain scalars, inline `[lists]` and
`{maps}`, and `|` / `>` block scalars. Anchors, aliases and tags are not
supported.

## Escaping

Printed variables are escaped by default. The compiler scans the template
to see where a variable lands, so `{title}` is escaped in a text node, in
an attribute value and in a comment, and left alone inside a `<script>` or
`<style>` body where html escaping would corrupt the code:

```smarty
<a href="{news.link}" title="{news.title}">{news.title}</a>
```

A value that is already markup opts out per tag with `{content|raw}`, or
its `{content|unescape}` alias. A whole template opts out with the
`{*noescape*}` directive, and a whole `Template` instance with
`$tpl->set_escape(false)`.

This changes compiled output, so a cache written by an earlier version has
to be cleared once on upgrade. See
[docs/minitpl-usage.md](docs/minitpl-usage.md) for the full rules.

## phpscript

The engine is kept compatible with
[phpscript](https://github.com/titpetric/phpscript), a PHP interpreter written
in Go. phpscript resolves `composer.json` and the `vendor/` directory on its
own, so the same `vendor/autoload.php` include works there:

```sh
phpscript example.php
```

# Testing

The project tries to maintain 100% code coverage. You can verify this by running `phpunit --coverage-text`,
or uncommenting the logging section within phpunit.xml.