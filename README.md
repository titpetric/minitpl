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
own. It is a phpscript script with a `#!/usr/bin/env phpscript` shebang and
runs from a checkout of the repository, after `composer install`: it loads
`vendor/autoload.php` from the root of the clone.

```sh
# run it through phpscript, or directly when the script is executable
phpscript cmd/minitpl compile templates
./cmd/minitpl compile templates

# transpile every template under templates/ into templates_c/
./cmd/minitpl compile templates

# render a template with data from a yaml file
./cmd/minitpl render templates/page.tpl data.yml

# the same, with the data piped in on standard input
cat data.yml | ./cmd/minitpl render templates/page.tpl
```

phpscript roots its source filesystem at the directory it is started in, so
run the command from the checkout and name the templates and data relative
to it.

`compile` keeps the directory structure of the source, resolves `{include}`
against it, and writes each template beside it: `templates/` compiles into
`templates_c/`. `-o DIR` writes elsewhere and `-q` prints nothing but
errors.

`render` reads a yaml mapping into template variables and prints the result
to standard output. The yaml comes from the binding the runtime registers,
`yaml_decode()`, listed by `phpscript list --bindings`; a runtime without
that binding decodes json instead. The command line carries no reader of
its own.

# Testing

The project tries to maintain 100% code coverage. You can verify this by running `phpunit --coverage-text`,
or uncommenting the logging section within phpunit.xml.