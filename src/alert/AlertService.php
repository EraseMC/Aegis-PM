<?php

declare(strict_types=1);

namespace aegis\alert;

use aegis\Clock;
use aegis\config\BridgeConfig;
use aegis\Detection;
use aegis\message\Messages;
use aegis\session\PlayerSession;
use aegis\session\SessionRegistry;
use Logger;
use pocketmine\player\Player;
use pocketmine\Server;

final readonly class AlertService
{
	public function __construct(
		private Server $server,
		private SessionRegistry $sessions,
		private BridgeConfig $config,
		private Logger $logger,
	) {}

	public function enabled(Player $player): bool
	{
		return $this->sessions->find($player->getId())->alerts ?? $this->config->alertsDefault;
	}

	public function set(Player $player, bool $enabled): void
	{
		$session = $this->sessions->find($player->getId());

		if ($session !== null) {
			$session->alerts = $enabled;
		}
	}

	public function notify(PlayerSession $session, Detection $detection, bool $punishment): void
	{
		$at = Clock::milliseconds();
		$previous = $session->alertedAt[$detection->name] ?? 0;

		if (!$punishment && $at - $previous < $this->config->alertCooldown) {
			return;
		}

		$session->alertedAt[$detection->name] = $at;
		$message = $punishment
			? Messages::punishment($session->player->getName(), $detection, $this->config->punish)
			: Messages::flag($session->player->getName(), $detection);

		if ($this->config->logAlerts) {
			$this->logger->info($this->server->getLanguage()->translate($message));
		}

		foreach ($this->server->getOnlinePlayers() as $viewer) {
			if ($viewer->hasPermission('aegis.alerts') && $this->enabled($viewer)) {
				$viewer->sendMessage($message);
			}
		}
	}
}
