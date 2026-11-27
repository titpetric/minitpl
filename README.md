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

The `minitpl` binary compiles templates and renders them with data.

```text
minitpl compile [options] [templates_dir] [output_dir]
minitpl render [options] <template.tpl> [data.yml]
```

The options are:

- `-q, --quiet` prints nothing but errors, for `compile`.
- `-h, --help` prints the usage.

### Compilation

Run `minitpl compile` to compile templates.

| Format | Example |
|--------|---------|
| `minitpl compile [options] [templates_dir] [output_dir]` | `minitpl compile templates templates_c` |

`templates_dir` is the directory holding the source templates and defaults to `templates`; it has to exist, and a missing directory fails the command. The directory is read recursively: every `.tpl` file below it is compiled, and subdirectories keep their names. `{include}` resolves against the source directory. `output_dir` is the directory the compiled templates are written to and defaults to a sibling of the source directory named after it with `_c` appended, so `templates` compiles into `templates_c`.

### Rendering

Run `minitpl render` to render templates.

| Format | Example |
|--------|---------|
| `minitpl render [options] <template.tpl> [data.yml]` | `minitpl render templates/page.tpl data.yml` |

`template.tpl` is the template file and is required. `data.yml` is a yaml mapping read into the template variables, and is optional; when it is omitted and standard input is not a terminal, the mapping is read from standard input. Pass `-` to read standard input explicitly. The rendered template is written to standard output.

# Testing

The project tries to maintain 100% code coverage. You can verify this by running `phpunit --coverage-text`,
or uncommenting the logging section within phpunit.xml.