<?php

declare(strict_types=1);

namespace aegis\command;

use aegis\alert\AlertService;
use aegis\Bridge;
use aegis\message\Messages;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\Translatable;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;

final class AegisCommand extends Command implements PluginOwned
{
	public function __construct(
		private readonly Plugin $plugin,
		private readonly Bridge $bridge,
		private readonly AlertService $alerts,
	)
	{
		parent::__construct('aegis', 'Manage the Aegis bridge', '/aegis [status|alerts [on|off]]');
		$this->setPermission('aegis.command');
	}

	public function getOwningPlugin(): Plugin
	{
		return $this->plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): bool
	{
		if (!$this->testPermission($sender)) {
			return true;
		}

		$action = strtolower($args[0] ?? 'status');

		if ($action === 'status' && count($args) <= 1) {
			$sender->sendMessage(Messages::status($this->bridge->connected(), $this->bridge->config->punish));

			return true;
		}

		if ($action !== 'alerts' || count($args) > 2 || (isset($args[1]) && !in_array(strtolower($args[1]), ['on', 'off'], true))) {
			$sender->sendMessage(Messages::usage());

			return true;
		}

		if (!$sender instanceof Player) {
			$sender->sendMessage(Messages::playerOnly());

			return true;
		}

		if (!$sender->hasPermission('aegis.alerts')) {
			$sender->sendMessage(new Translatable('pocketmine.command.error.permission', ['aegis.alerts']));

			return true;
		}

		$enabled = isset($args[1]) ? strtolower($args[1]) === 'on' : !$this->alerts->enabled($sender);
		$this->alerts->set($sender, $enabled);
		$sender->sendMessage(Messages::alerts($enabled));

		return true;
	}
}
