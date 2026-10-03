<?php

declare(strict_types=1);

namespace aegis\network;

use aegis\Action;
use aegis\BridgeException;
use aegis\Detection;

final readonly class Verdict
{
	public function __construct(
		public int $session,
		public bool $punish,
		public Detection $detection
	) {}

	public static function decode(FrameType $type, string $body): self
	{
		$offset = 0;
		$session = Frame::readU64($body, $offset);
		$check = Frame::readString($body, $offset);
		$violations = Frame::readF32($body, $offset);

		if ($session <= 0 || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $check) !== 1 || !is_finite($violations) || $violations < 0) {
			throw new BridgeException('Aegis sent an invalid detection');
		}

		$verdict = match ($type) {
			FrameType::Flag => self::flag($session, $check, $violations, $body, $offset),
			FrameType::Punish => self::punishment($session, $check, $violations, $body, $offset),
			default => throw new BridgeException(sprintf('Frame 0x%02x is not a verdict', $type->value)),
		};

		if ($offset !== strlen($body)) {
			throw new BridgeException(sprintf('%d trailing bytes in a %s frame', strlen($body) - $offset, $type->name));
		}

		return $verdict;
	}

	private static function flag(int $session, string $check, float $violations, string $body, int &$offset): self
	{
		$max = Frame::readF32($body, $offset);

		if (!is_finite($max) || $max <= 0) {
			throw new BridgeException('Aegis sent an invalid violation threshold');
		}

		return new self($session, false, new Detection($check, $violations, $max, Frame::readString($body, $offset), Action::None));
	}

	private static function punishment(int $session, string $check, float $violations, string $body, int &$offset): self
	{
		$action = Action::fromWire(Frame::readU8($body, $offset));

		return new self($session, true, new Detection($check, $violations, $violations, Frame::readString($body, $offset), $action));
	}
}
