<?php

declare(strict_types=1);

namespace aegis\punishment;

use aegis\Action;
use aegis\config\BridgeConfig;
use aegis\Detection;
use aegis\message\Messages;
use DateTime;
use pocketmine\player\Player;
use pocketmine\Server;

final readonly class PunishmentService
{
	public function __construct(private Server $server, private BridgeConfig $config) {}

	public function apply(Player $player, Detection $detection): void
	{
		if (!$this->config->punish || $detection->action === Action::None) {
			return;
		}

		if ($detection->action === Action::Ban) {
			$expires = $this->config->banSeconds === 0
				? null
				: (new DateTime())->setTimestamp(time() + $this->config->banSeconds);
			$this->server->getNameBans()->addBan($player->getName(), sprintf('Aegis %s', $detection->name), $expires, 'Aegis');
		}

		$player->kick(Messages::disconnect($detection));
	}
}
