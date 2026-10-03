<?php

declare(strict_types=1);

namespace aegis\event;

use aegis\Detection;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\player\Player;

final class DetectionEvent extends Event implements Cancellable
{
	use CancellableTrait;

	public function __construct(
		private readonly Player $player,
		private readonly Detection $detection,
		private readonly bool $punishment,
	) {}

	public function getPlayer(): Player
	{
		return $this->player;
	}

	public function getDetection(): Detection
	{
		return $this->detection;
	}

	public function isPunishment(): bool
	{
		return $this->punishment;
	}
}
