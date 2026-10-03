<?php

declare(strict_types=1);

namespace aegis\scripts;

use FilesystemIterator;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

$root = dirname(__DIR__);
$destination = $root . '/dist';

if (ini_get('phar.readonly') !== '0') {
	fwrite(STDERR, "Run with php -d phar.readonly=0 scripts/build.php\n");
	exit(1);
}

if (!is_dir($destination) && !mkdir($destination, 0775, true)) {
	throw new RuntimeException(sprintf('Cannot create %s', $destination));
}

$path = $destination . '/Aegis-PM.phar';

if (is_file($path) && !unlink($path)) {
	throw new RuntimeException(sprintf('Cannot replace %s', $path));
}

$files = ['LICENSE', 'plugin.yml'];

foreach (['src', 'resources'] as $directory) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		if ($file instanceof SplFileInfo && $file->isFile()) {
			$files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
		}
	}
}

sort($files, SORT_STRING);
$phar = new Phar($path);
$phar->startBuffering();

foreach ($files as $file) {
	$phar->addFile($root . '/' . $file, $file);
}

$phar->setStub('<?php __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
fwrite(STDOUT, sprintf("Built %s (%d files)\n", $path, count($files)));
