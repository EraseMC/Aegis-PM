<?php

declare(strict_types=1);

namespace aegis;

final class Clock
{
	private function __construct() {}

	public static function milliseconds(): int
	{
		return intdiv(hrtime(true), 1_000_000);
	}
}
