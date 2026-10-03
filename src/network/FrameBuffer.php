<?php

declare(strict_types=1);

namespace aegis\network;

use aegis\BridgeException;

final class FrameBuffer
{
	private const int MAX_FRAME = 1 << 20;

	private const int MAX_BATCH = 256;

	private string $buffer = '';

	private bool $welcomed = false;

	public function welcomed(): bool
	{
		return $this->welcomed;
	}

	public function append(string $bytes): void
	{
		if (strlen($this->buffer) + strlen($bytes) > self::MAX_FRAME + 4) {
			throw new BridgeException('Aegis receive buffer is full');
		}

		$this->buffer .= $bytes;
	}

	public function drain(): array
	{
		$verdicts = [];
		$processed = 0;

		while (strlen($this->buffer) >= 5 && $processed++ < self::MAX_BATCH) {
			$size = unpack('V', $this->buffer)[1] ?? 0;

			if ($size < 1 || $size > self::MAX_FRAME) {
				throw new BridgeException(sprintf('Frame of %d bytes is out of bounds', $size));
			}

			if (strlen($this->buffer) < $size + 4) {
				break;
			}

			$type = FrameType::tryFrom(ord($this->buffer[4]))
				?? throw new BridgeException(sprintf('Unknown frame type 0x%02x', ord($this->buffer[4])));
			$body = substr($this->buffer, 5, $size - 1);
			$this->buffer = (string) substr($this->buffer, $size + 4);

			if ($type === FrameType::Welcome) {
				$offset = 0;

				if ($this->welcomed || Frame::readU16($body, $offset) !== Frame::VERSION || $offset !== strlen($body)) {
					throw new BridgeException('Unsupported or repeated Aegis handshake');
				}

				$this->welcomed = true;
				continue;
			}

			if (!$this->welcomed) {
				throw new BridgeException('Aegis sent a verdict before the handshake');
			}

			$verdicts[] = Verdict::decode($type, $body);
		}

		return $verdicts;
	}

	public function clear(): void
	{
		$this->buffer = '';
		$this->welcomed = false;
	}
}
