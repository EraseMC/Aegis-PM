<?php

declare(strict_types=1);

namespace aegis\telemetry;

use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;

enum InputControl: int
{
	case Jump = 1;
	case JumpStart = 2;
	case Sprint = 4;
	case HorizontalCollision = 8;
	case VerticalCollision = 16;
	case JumpPressed = 32;
	case Sneak = 64;
	case Known = 128;

	public function inputFlag(): ?int
	{
		return match ($this) {
			self::Jump => PlayerAuthInputFlags::JUMPING,
			self::JumpStart => PlayerAuthInputFlags::START_JUMPING,
			self::Sprint => PlayerAuthInputFlags::SPRINTING,
			self::HorizontalCollision => PlayerAuthInputFlags::HORIZONTAL_COLLISION,
			self::VerticalCollision => PlayerAuthInputFlags::VERTICAL_COLLISION,
			self::JumpPressed => PlayerAuthInputFlags::JUMP_PRESSED_RAW,
			self::Sneak => PlayerAuthInputFlags::SNEAKING,
			self::Known => null,
		};
	}
}
