<?php

declare(strict_types=1);

namespace aegis\session;

final class PlayerView
{
	public array $visible = [];

	public array $pending = [];

	public array $seed = [];

	public int $sampleAt = 0;

	public function clear(): void
	{
		$this->visible = $this->pending = $this->seed = [];
		$this->sampleAt = 0;
	}
}
