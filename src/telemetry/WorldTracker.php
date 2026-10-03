<?php

declare(strict_types=1);

namespace aegis\telemetry;

use aegis\Clock;
use aegis\network\Connection;
use aegis\network\Frame;
use aegis\network\FrameType;
use aegis\session\PlayerSession;
use aegis\session\SessionRegistry;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\Server;

final class WorldTracker
{
	private const int SAMPLE_INTERVAL = 5;

	private const int MAX_SAMPLES = 8;

	private const int SAMPLE_BUDGET = 1_000_000;

	private ?Connection $connection = null;

	private int $marker = 1;

	private int $cursor = 0;

	private readonly PacketObserver $observer;

	private readonly PacketCodec $codec;

	private readonly WorldSampler $sampler;

	private readonly TickBudget $budget;

	public function __construct(private readonly Server $server, private readonly SessionRegistry $sessions)
	{
		$this->observer = new PacketObserver($sessions);
		$this->codec = new PacketCodec();
		$this->sampler = new WorldSampler($server);
		$this->budget = new TickBudget(nanoseconds: 1_000_000, maximumOperations: 2048);
	}

	public function attach(Connection $connection): void
	{
		$this->connection = $connection;
	}

	public function reset(): void
	{
		foreach ($this->sessions->all() as $session) {
			$session->view->clear();
			$this->observer->seed($session);
		}
	}

	public function onJoin(PlayerJoinEvent $event): void
	{
		$session = $this->sessions->find($event->getPlayer()->getId());

		if ($session !== null) {
			$this->observer->seed($session);
		}
	}

	public function onQuit(PlayerQuitEvent $event): void
	{
		foreach ($this->sessions->all() as $session) {
			PacketObserver::remove($session, $event->getPlayer()->getId());
		}
	}

	public function onSend(DataPacketSendEvent $event): void
	{
		$connection = $this->connection;

		if ($connection === null || !$connection->connected()) {
			return;
		}

		foreach ($event->getPackets() as $packet) {
			if (!$packet instanceof DataPacket || !$this->observer->accepts($packet)) {
				continue;
			}

			foreach ($event->getTargets() as $network) {
				if (!$this->observe($network, $packet, $connection)) {
					return;
				}
			}
		}
	}

	public function tick(int $tick): void
	{
		$connection = $this->connection;
		$sessions = array_values($this->sessions->all());
		$count = count($sessions);

		if ($connection === null || !$connection->connected() || $count === 0) {
			return;
		}

		$sampled = 0;
		$deadline = hrtime(true) + self::SAMPLE_BUDGET;

		for ($i = 0; $i < $count && $sampled < self::MAX_SAMPLES && hrtime(true) < $deadline; ++$i) {
			$session = $sessions[$this->cursor++ % $count];

			if ($session->view->sampleAt > $tick || !$session->player->isConnected()) {
				continue;
			}

			$this->seed($session, $deadline);
			$session->view->sampleAt = $tick + self::SAMPLE_INTERVAL;
			$this->sample($session, $connection);
			++$sampled;
		}
	}

	private function observe(NetworkSession $network, DataPacket $packet, Connection $connection): bool
	{
		$player = $network->getPlayer();
		$session = $player === null ? null : $this->sessions->find($player->getId());

		if ($session === null) {
			return true;
		}

		if (!$this->budget->claim($this->server->getTick())) {
			$connection->suspend('outgoing');

			return false;
		}

		$started = hrtime(true);
		$frame = $this->observer->observe($session, $packet);

		if ($frame !== null) {
			$connection->send($frame);
		}

		$this->budget->account($started);

		return true;
	}

	private function seed(PlayerSession $viewer, int $deadline): void
	{
		for ($i = 0; $i < 8 && $viewer->view->seed !== [] && hrtime(true) < $deadline; ++$i) {
			$target = $this->sessions->find(array_pop($viewer->view->seed));

			if ($target !== null && isset($target->player->getViewers()[spl_object_id($viewer->player)])
				&& !isset($viewer->view->visible[$target->player->getId()])) {
				$this->observer->position($viewer, $target, $target->player->getPosition());
			}
		}
	}

	private function sample(PlayerSession $session, Connection $connection): void
	{
		$player = $session->player;
		$connection->send($this->sampler->sample($player));

		foreach ($session->view->pending as $frame) {
			$connection->send($frame);
		}

		$session->view->pending = [];
		$marker = NetworkStackLatencyPacket::request((0x10000000 + $this->marker++) * 1000);
		$connection->send(Frame::packet(FrameType::Clientbound, $player->getId(), Clock::milliseconds(), $this->codec->encode($marker)));
		$player->getNetworkSession()->sendDataPacket($marker);
	}
}
