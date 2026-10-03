<?php

declare(strict_types=1);

namespace aegis;

use aegis\alert\AlertService;
use aegis\command\AegisCommand;
use aegis\config\BridgeConfig;
use aegis\network\Frame;
use aegis\punishment\PunishmentService;
use aegis\session\SessionRegistry;
use aegis\telemetry\PlayerMirror;
use aegis\telemetry\WorldTracker;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Aegis extends PluginBase
{
	private ?Bridge $bridge = null;

	protected function onEnable(): void
	{
		$this->saveDefaultConfig();

		try {
			$config = BridgeConfig::load($this->getConfig());
		} catch (BridgeException $exception) {
			$this->getLogger()->error($exception->getMessage());
			$this->getServer()->getPluginManager()->disablePlugin($this);

			return;
		}

		$server = $this->getServer();
		$logger = $this->getLogger();
		$sessions = new SessionRegistry();
		$mirror = new PlayerMirror($server, $sessions, $logger);
		$tracker = new WorldTracker($server, $sessions);
		$alerts = new AlertService($server, $sessions, $config, $logger);
		$punishments = new PunishmentService($server, $config);
		$shield = TypeConverter::getInstance()->getItemTypeDictionary()->fromStringId(ItemTypeNames::SHIELD);
		$bridge = new Bridge(
			config: $config,
			server: $server,
			mirror: $mirror,
			tracker: $tracker,
			detections: new DetectionService($sessions, $alerts, $punishments),
			logger: $logger,
			hello: Frame::hello($shield, $server->getMotd()),
		);
		$this->bridge = $bridge;
		$this->registerEvents($mirror, $tracker);
		$server->getCommandMap()->register('aegispm', new AegisCommand($this, $bridge, $alerts));
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask($bridge->tick(...)), 1);
	}

	protected function onDisable(): void
	{
		$this->bridge?->close();
		$this->bridge = null;
	}

	private function registerEvents(PlayerMirror $mirror, WorldTracker $tracker): void
	{
		$events = $this->getServer()->getPluginManager();
		$events->registerEvent(PlayerJoinEvent::class, $mirror->onJoin(...), EventPriority::MONITOR, $this);
		$events->registerEvent(PlayerQuitEvent::class, $mirror->onQuit(...), EventPriority::MONITOR, $this);
		$events->registerEvent(DataPacketDecodeEvent::class, $mirror->onDecode(...), EventPriority::NORMAL, $this, true);
		$events->registerEvent(DataPacketReceiveEvent::class, $mirror->onReceive(...), EventPriority::MONITOR, $this, true);
		$events->registerEvent(PlayerJoinEvent::class, $tracker->onJoin(...), EventPriority::MONITOR, $this);
		$events->registerEvent(PlayerQuitEvent::class, $tracker->onQuit(...), EventPriority::MONITOR, $this);
		$events->registerEvent(DataPacketSendEvent::class, $tracker->onSend(...), EventPriority::MONITOR, $this);
	}
}
