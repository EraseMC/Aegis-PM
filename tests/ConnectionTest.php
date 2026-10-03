<?php

declare(strict_types=1);

namespace aegis\tests;

use aegis\network\Connection;
use aegis\network\Frame;
use RuntimeException;
use SimpleLogger;
use Socket;

final class ConnectionTest
{
	private int $tick = 1;

	private int $announced = 0;

	private readonly Socket $listener;

	private ?Socket $peer = null;

	private readonly Connection $connection;

	private function __construct()
	{
		$this->listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) ?: throw new RuntimeException('Cannot create a test listener');
		expect(socket_bind($this->listener, '127.0.0.1', 0) && socket_listen($this->listener), 'Cannot bind a test listener');
		socket_set_nonblock($this->listener);
		socket_getsockname($this->listener, $address, $port);
		$this->connection = new Connection(
			'127.0.0.1', $port, Frame::hello(387, 'connection-test'),
			function (): void {
				++$this->announced;
				$this->connection->send(Frame::join(42, 'test', '', 1001, '1.26.30', 7, 1));
			},
			new class extends SimpleLogger {
				public function log(mixed $level, mixed $message): void {}
			},
		);
	}

	public static function run(): void
	{
		$test = new self();

		try {
			$test->verify();
		} finally {
			$test->connection->close();
			if ($test->peer !== null) {
				socket_close($test->peer);
			}
			socket_close($test->listener);
		}

		fwrite(STDOUT, "Socket handshake, fragmented verdicts, reconnect, backpressure and timeout checks passed\n");
	}

	private function verify(): void
	{
		$this->accept();
		expect(!$this->connection->connected(), 'TCP alone was treated as a completed handshake');
		$this->write(substr(welcome(), 0, 2));
		$this->advance();
		expect(!$this->connection->connected(), 'A fragmented handshake was accepted too early');
		$this->write(substr(welcome(), 2));
		$this->awaitHandshake();
		$this->write(substr(flag(), 0, 8));
		expect($this->advance() === [], 'A partial verdict was dispatched');
		$this->write(substr(flag(), 8));
		$verdicts = [];

		for ($i = 0; $i < 50 && $verdicts === []; ++$i) {
			$verdicts = $this->advance();
		}

		expect(count($verdicts) === 1 && $verdicts[0]->session === 42, 'The socket verdict was lost');
		$this->write(substr(flag(), 0, 8));
		$this->advance();
		socket_shutdown($this->peer);
		socket_close($this->peer);
		$this->peer = null;

		for ($i = 0; $i < 50 && $this->connection->connected(); ++$i) {
			$this->advance();
		}

		expect(!$this->connection->connected(), 'A closed socket stayed connected');
		$this->tick += 100;
		$this->accept();
		$this->write(welcome());
		$this->awaitHandshake();
		expect($this->announced === 2, 'Reconnect did not resynchronise players');
		expect($this->advance() === [], 'A stale verdict survived reconnect');
		$this->connection->send(str_repeat('x', (512 << 10) + 1));
		expect(!$this->connection->connected(), 'An overflowing queue stayed connected');
		socket_close($this->peer);
		$this->peer = null;
		$this->tick += 100;
		$this->accept();
		$this->tick += 100;
		$this->advance();
		expect(!$this->connection->connected(), 'A missing handshake did not time out');
	}

	private function accept(): void
	{
		for ($i = 0; $i < 50; ++$i) {
			$this->advance();
			$peer = @socket_accept($this->listener);

			if ($peer !== false) {
				socket_set_nonblock($peer);
				$this->peer = $peer;
				$this->advance();

				return;
			}
		}

		throw new RuntimeException('The bridge did not open a socket');
	}

	private function awaitHandshake(): void
	{
		for ($i = 0; $i < 50 && !$this->connection->connected(); ++$i) {
			$this->advance();
		}

		expect($this->connection->connected(), 'The socket handshake failed');
	}

	private function advance(): array
	{
		usleep(1000);

		return $this->connection->tick($this->tick++, $this->tick * 50);
	}

	private function write(string $bytes): void
	{
		expect($this->peer !== null && socket_write($this->peer, $bytes) === strlen($bytes), 'Cannot send test evidence');
	}
}
