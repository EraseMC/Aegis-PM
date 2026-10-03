<?php

declare(strict_types=1);

namespace aegis\session;

use pocketmine\player\Player;

final class PlayerSession
{
	public ?bool $alerts = null;

	public array $alertedAt = [];

	public function __construct(
		public readonly Player $player,
		public readonly PlayerView $view = new PlayerView(),
	) {}
}
