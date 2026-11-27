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

## CLI

A `minitpl` binary is provided. It compiles templates and renders them with data, and `minitpl --help` prints the same usage.

### compile

`minitpl compile [options] [templates_dir] [output_dir]`

Transpiles every `.tpl` file under `templates_dir` (default `templates`) into `output_dir`, which defaults to the `templates_c` directory beside `templates_dir`. Subdirectories keep their names, and `{include}` is resolved against `templates_dir`.

### render

`minitpl render [options] <template.tpl> [data.yml]`

Renders `template.tpl` to standard output. Template variables come from a yaml mapping read from `data.yml`, or from standard input when `data.yml` is omitted and standard input is not a terminal; pass `-` to read standard input explicitly.

### help

`minitpl help`

Prints the usage.

### Options

| Option | What it does |
|--------|--------------|
| `-o, --output DIR` | Write compiled templates to `DIR` (compile). |
| `-q, --quiet` | Print nothing but errors. |
| `-h, --help` | Show the help. |

# Testing

The project tries to maintain 100% code coverage. You can verify this by running `phpunit --coverage-text`,
or uncommenting the logging section within phpunit.xml.