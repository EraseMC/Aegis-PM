<?php

declare(strict_types=1);

namespace aegis;

final readonly class Detection
{
	public function __construct(
		public string $name,
		public float $violations,
		public float $maxViolations,
		public string $data,
		public Action $action
	) {}
}
