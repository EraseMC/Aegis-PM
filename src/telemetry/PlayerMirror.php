<?php

declare(strict_types=1);

namespace aegis\telemetry;

use aegis\Clock;
use aegis\network\Connection;
use aegis\network\Frame;
use aegis\session\SessionRegistry;
use Logger;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\player\Player;
use pocketmine\Server;
use Throwable;

final class PlayerMirror
{
	private ?Connection $connection = null;

	private array $failed = [];

	private readonly PacketEncoder $encoder;

	private readonly TickBudget $budget;

	public function __construct(
		private readonly Server $server,
		private readonly SessionRegistry $sessions,
		private readonly Logger $logger,
	)
	{
		$this->encoder = new PacketEncoder();
		$this->budget = new TickBudget(nanoseconds: 1_500_000, maximumOperations: 2048);
	}

	public function attach(Connection $connection): void
	{
		$this->connection = $connection;
	}

	public function announceAll(): void
	{
		foreach ($this->server->getOnlinePlayers() as $player) {
			if ($player->isConnected()) {
				$this->sessions->register($player);
				$this->announce($player);
			}
		}
	}

	public function onJoin(PlayerJoinEvent $event): void
	{
		$player = $event->getPlayer();

		if (!$player->isConnected()) {
			return;
		}

		$this->sessions->register($player);
		$this->announce($player);
	}

	public function onQuit(PlayerQuitEvent $event): void
	{
		$id = $event->getPlayer()->getId();
		$this->sessions->remove($id);
		$this->connection?->send(Frame::quit($id));
	}

	public function onDecode(DataPacketDecodeEvent $event): void
	{
		if ($this->connection?->connected()
			&& in_array($event->getPacketId(), [LevelSoundEventPacket::NETWORK_ID, NetworkStackLatencyPacket::NETWORK_ID], true)) {
			$event->uncancel();
		}
	}

	public function onReceive(DataPacketReceiveEvent $event): void
	{
		$connection = $this->connection;
		$packet = $event->getPacket();
		$player = $event->getOrigin()->getPlayer();

		if ($connection === null || !$connection->connected() || !$packet instanceof DataPacket || !$this->encoder->accepts($packet)
			|| $player === null || $this->sessions->find($player->getId()) === null) {
			return;
		}

		if (!$this->budget->claim($this->server->getTick())) {
			$connection->suspend('input');

			return;
		}

		$started = hrtime(true);

		try {
			$frame = $this->encoder->encode($player->getId(), Clock::milliseconds(), $packet);

			if ($frame !== null) {
				$connection->send($frame);
			}
		} catch (Throwable $exception) {
			$key = $packet::class . '@' . PacketCodec::protocol($event->getOrigin());

			if (!isset($this->failed[$key])) {
				$this->failed[$key] = true;
				$this->logger->warning(sprintf('Cannot mirror %s: %s', $key, $exception->getMessage()));
			}
		} finally {
			$this->budget->account($started);
		}
	}

	private function announce(Player $player): void
	{
		$extra = $player->getPlayerInfo()->getExtraData();
		$this->connection?->send(Frame::join(
			session: $player->getId(),
			name: $player->getName(),
			xuid: $player->getXuid(),
			protocol: PacketCodec::protocol($player->getNetworkSession()),
			version: is_string($extra['GameVersion'] ?? null) ? $extra['GameVersion'] : '',
			deviceOs: is_int($extra['DeviceOS'] ?? null) ? $extra['DeviceOS'] : 0,
			inputMode: is_int($extra['CurrentInputMode'] ?? null) ? $extra['CurrentInputMode'] : 0,
		));
	}
}
