<?php

declare(strict_types=1);

namespace aegis;

enum Action: string
{
	case None = 'none';
	case Kick = 'kick';
	case Ban = 'ban';

	public static function fromWire(int $value): self
	{
		return match ($value) {
			0 => self::None,
			1 => self::Kick,
			2 => self::Ban,
			default => throw new BridgeException(sprintf('Unknown punishment action %d', $value)),
		};
	}
}
