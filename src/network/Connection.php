<?php

declare(strict_types=1);

namespace aegis\network;

use aegis\BridgeException;
use Closure;
use Logger;
use Socket;

final class Connection
{
	private const int MAX_OUTGOING = 512 << 10;

	private const int RETRY_TICKS = 100;

	private const int READ_CHUNK = 65536;

	private const int IO_BUDGET = 262144;

	private const int CONNECT_TICKS = 100;

	private ?Socket $socket = null;

	private bool $connected = false;

	private string $outgoing = '';

	private readonly FrameBuffer $frames;

	private int $retryAt = 0;

	private int $connectUntil = 0;

	private bool $warned = false;

	private int $lastTick = 0;

	private int $queuedAt = 0;

	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $hello,
		private readonly Closure $onConnect,
		private readonly Logger $logger,
	)
	{
		$this->frames = new FrameBuffer();
	}

	public function connected(): bool
	{
		return $this->connected && $this->frames->welcomed();
	}

	public function retryDue(int $tick): bool
	{
		return $this->socket === null && $this->retryAt > 0 && $tick >= $this->retryAt;
	}

	public function send(string $frame): void
	{
		if (!$this->connected) {
			return;
		}

		if (strlen($this->outgoing) + strlen($frame) > self::MAX_OUTGOING) {
			$this->drop('Aegis is not keeping up, the send buffer is full');

			return;
		}

		$this->outgoing .= $frame;

		if ($this->queuedAt === 0) {
			$this->queuedAt = $this->lastTick;
		}
	}

	public function suspend(string $source = 'telemetry'): void
	{
		$this->drop(sprintf('Aegis %s budget exceeded, rebuilding its state', $source));
	}

	public function tick(int $tick, int $time): array
	{
		$this->lastTick = $tick;

		if ($this->outgoing !== '' && $this->queuedAt > 0 && $tick - $this->queuedAt > 10) {
			$this->drop('Aegis telemetry is stale, rebuilding its state');
		}

		if ($this->socket === null) {
			if ($tick >= $this->retryAt) {
				$this->open($tick);
			}

			return [];
		}

		if (!$this->connected) {
			if ($tick >= $this->connectUntil) {
				$this->drop('Aegis connection timed out');

				return [];
			}

			if (!$this->finishConnect()) {
				return [];
			}
		}

		if (!$this->frames->welcomed() && $tick >= $this->connectUntil) {
			$this->drop('Aegis handshake timed out');

			return [];
		}

		$this->send(Frame::tick($tick, $time));
		$this->flush();

		return $this->socket === null ? [] : $this->receive();
	}

	public function close(): void
	{
		$this->flush();

		if ($this->socket !== null) {
			socket_close($this->socket);
		}

		$this->reset();
	}

	private function open(int $tick): void
	{
		$this->retryAt = $tick + self::RETRY_TICKS;
		$family = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? AF_INET6 : AF_INET;
		$socket = @socket_create($family, SOCK_STREAM, SOL_TCP);

		if ($socket === false) {
			return;
		}

		$address = $this->host;
		socket_set_nonblock($socket);
		socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);

		if (!@socket_connect($socket, $address, $this->port)) {
			$error = socket_last_error($socket);

			if ($error !== SOCKET_EINPROGRESS && $error !== SOCKET_EALREADY && $error !== SOCKET_EWOULDBLOCK) {
				$this->warnOnce(sprintf('Aegis is unreachable at %s:%d: %s', $this->host, $this->port, trim(socket_strerror($error))));
				socket_close($socket);

				return;
			}
		}

		$this->socket = $socket;
		$this->connectUntil = $tick + self::CONNECT_TICKS;
	}

	private function finishConnect(): bool
	{
		if ($this->socket === null) {
			return false;
		}

		$read = null;
		$write = [$this->socket];
		$except = null;

		if (@socket_select($read, $write, $except, 0) !== 1) {
			return false;
		}

		$socket = $this->socket;
		$error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);

		if ($error !== 0) {
			$this->warnOnce(sprintf('Aegis refused the connection at %s:%d: %s', $this->host, $this->port, trim(socket_strerror((int) $error))));
			socket_close($socket);
			$this->reset();

			return false;
		}

		$this->connected = true;
		$this->warned = false;
		$this->outgoing = $this->hello;
		($this->onConnect)();

		return true;
	}

	private function flush(): void
	{
		$budget = self::IO_BUDGET;

		while ($this->socket !== null && $this->outgoing !== '' && $budget > 0) {
			$written = $this->write($budget);

			if ($written === 0) {
				return;
			}

			$this->outgoing = (string) substr($this->outgoing, $written);

			if ($this->outgoing === '') {
				$this->queuedAt = 0;
			}

			$budget -= $written;
		}
	}

	private function write(int $budget): int
	{
		if ($this->socket === null) {
			return 0;
		}

		$written = @socket_write($this->socket, $this->outgoing, min(strlen($this->outgoing), $budget));

		if ($written !== false) {
			return $written;
		}

		$error = socket_last_error($this->socket);

		if (!in_array($error, [SOCKET_EWOULDBLOCK, SOCKET_EAGAIN], true)) {
			$this->drop(sprintf('Lost the connection to Aegis: %s', trim(socket_strerror($error))));
		}

		return 0;
	}

	private function receive(): array
	{
		$budget = self::IO_BUDGET;
		$welcomed = $this->frames->welcomed();

		try {
			while ($this->socket !== null && $budget > 0) {
				$chunk = $this->read($budget);

				if ($chunk === null) {
					break;
				}

				$this->frames->append($chunk);
				$budget -= strlen($chunk);
			}

			if ($this->socket === null) {
				return [];
			}

			$verdicts = $this->frames->drain();

			if (!$welcomed && $this->frames->welcomed()) {
				$this->logger->info(sprintf('Connected to Aegis at %s:%d', $this->host, $this->port));
			}

			return $verdicts;
		} catch (BridgeException $exception) {
			$this->drop(sprintf('Aegis sent a broken frame: %s', $exception->getMessage()));

			return [];
		}
	}

	private function read(int $budget): ?string
	{
		if ($this->socket === null) {
			return null;
		}

		$chunk = '';
		$read = @socket_recv($this->socket, $chunk, min(self::READ_CHUNK, $budget), 0);

		if ($read === 0) {
			$this->drop('Aegis closed the connection');

			return null;
		}

		if ($read !== false) {
			return $chunk;
		}

		$error = socket_last_error($this->socket);

		if (!in_array($error, [SOCKET_EWOULDBLOCK, SOCKET_EAGAIN], true)) {
			$this->drop(sprintf('Lost the connection to Aegis: %s', trim(socket_strerror($error))));
		}

		return null;
	}

	private function drop(string $reason): void
	{
		$this->logger->warning($reason);

		if ($this->socket !== null) {
			socket_close($this->socket);
		}

		$this->reset();
		$this->retryAt = $this->lastTick + self::RETRY_TICKS;
	}

	private function reset(): void
	{
		$this->socket = null;
		$this->connected = false;
		$this->outgoing = '';
		$this->frames->clear();
		$this->queuedAt = 0;
	}

	private function warnOnce(string $message): void
	{
		if (!$this->warned) {
			$this->warned = true;
			$this->logger->warning($message);
		}
	}
}
