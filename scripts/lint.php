<?php

declare(strict_types=1);

namespace aegis\scripts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

$root = dirname(__DIR__);

foreach (['src', 'scripts', 'tests'] as $directory) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
			continue;
		}

		passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $exit);

		if ($exit !== 0) {
			exit($exit);
		}
	}
}
