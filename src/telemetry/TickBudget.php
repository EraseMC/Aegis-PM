<?php

declare(strict_types=1);

namespace aegis\telemetry;

final class TickBudget
{
	private int $tick = -1;

	private int $spent = 0;

	private int $operations = 0;

	public function __construct(private readonly int $nanoseconds, private readonly int $maximumOperations) {}

	public function claim(int $tick): bool
	{
		if ($this->tick !== $tick) {
			$this->tick = $tick;
			$this->spent = $this->operations = 0;
		}

		return $this->spent < $this->nanoseconds && ++$this->operations <= $this->maximumOperations;
	}

	public function account(int $started): void
	{
		$this->spent += hrtime(true) - $started;
	}
}
