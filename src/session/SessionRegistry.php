<?php

declare(strict_types=1);

namespace aegis\session;

use pocketmine\player\Player;

final class SessionRegistry
{
	private array $sessions = [];

	public function register(Player $player): PlayerSession
	{
		return $this->sessions[$player->getId()] ??= new PlayerSession($player);
	}

	public function find(int $id): ?PlayerSession
	{
		return $this->sessions[$id] ?? null;
	}

	public function remove(int $id): void
	{
		unset($this->sessions[$id]);
	}

	public function all(): array
	{
		return $this->sessions;
	}
}
