<?php

declare(strict_types=1);

namespace aegis\tests;

if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
	require dirname(__DIR__) . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'aegis\\')) {
		require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
	}
});
