<?php

declare(strict_types=1);

namespace aegis;

use aegis\config\BridgeConfig;
use aegis\network\Connection;
use aegis\network\ResolveHostTask;
use aegis\telemetry\PlayerMirror;
use aegis\telemetry\WorldTracker;
use Logger;
use pocketmine\Server;

final class Bridge
{
	private const int RETRY_INTERVAL = 100;

	private ?Connection $connection = null;

	private int $retryAt = 0;

	private bool $resolving = false;

	private bool $active = true;

	private bool $resolutionWarned = false;

	public function __construct(
		public readonly BridgeConfig $config,
		private readonly Server $server,
		private readonly PlayerMirror $mirror,
		private readonly WorldTracker $tracker,
		private readonly DetectionService $detections,
		private readonly Logger $logger,
		private readonly string $hello,
	) {}

	public function connected(): bool
	{
		return $this->connection?->connected() ?? false;
	}

	public function tick(): void
	{
		$tick = $this->server->getTick();

		if ($this->connection?->retryDue($tick) && filter_var($this->config->host, FILTER_VALIDATE_IP) === false) {
			$this->connection->close();
			$this->connection = null;
		}

		if ($this->connection === null) {
			if (!$this->resolving && $tick >= $this->retryAt) {
				$this->resolve();
			}

			return;
		}

		$this->tracker->tick($tick);

		foreach ($this->connection->tick($tick, Clock::milliseconds()) as $verdict) {
			$this->detections->handle($verdict);
		}
	}

	public function close(): void
	{
		$this->active = false;
		$this->connection?->close();
		$this->connection = null;
	}

	private function resolve(): void
	{
		$this->retryAt = $this->server->getTick() + self::RETRY_INTERVAL;

		if (filter_var($this->config->host, FILTER_VALIDATE_IP) !== false) {
			$this->connect($this->config->host);

			return;
		}

		$this->resolving = true;
		$this->server->getAsyncPool()->submitTask(new ResolveHostTask($this->config->host, $this->resolved(...)));
	}

	private function resolved(?string $address): void
	{
		$this->resolving = false;

		if (!$this->active) {
			return;
		}

		if ($address !== null) {
			$this->connect($address);

			return;
		}

		if (!$this->resolutionWarned) {
			$this->resolutionWarned = true;
			$this->logger->warning('Cannot resolve the Aegis host; retrying in the background');
		}
	}

	private function connect(string $address): void
	{
		$this->resolutionWarned = false;
		$this->connection = new Connection(
			host: $address,
			port: $this->config->port,
			hello: $this->hello,
			onConnect: $this->synchronise(...),
			logger: $this->logger,
		);
		$this->mirror->attach($this->connection);
		$this->tracker->attach($this->connection);
	}

	private function synchronise(): void
	{
		$this->mirror->announceAll();
		$this->tracker->reset();
	}
}
