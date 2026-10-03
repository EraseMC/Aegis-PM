<?php

declare(strict_types=1);

namespace aegis\telemetry;

use aegis\network\Frame;
use aegis\network\FrameType;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;

final readonly class PacketEncoder
{
	private array $encoders;

	public function __construct(private PacketCodec $codec = new PacketCodec())
	{
		$this->encoders = [
			PlayerAuthInputPacket::class => self::input(...),
			InventoryTransactionPacket::class => self::attack(...),
			AnimatePacket::class => self::animation(...),
			LevelSoundEventPacket::class => self::miss(...),
			NetworkStackLatencyPacket::class => $this->latency(...),
		];
	}

	public function accepts(DataPacket $packet): bool
	{
		return isset($this->encoders[$packet::class]);
	}

	public function encode(int $session, int $time, DataPacket $packet): ?string
	{
		$encode = $this->encoders[$packet::class] ?? null;

		return $encode === null ? null : $encode($session, $time, $packet);
	}

	private static function input(int $session, int $time, DataPacket $packet): ?string
	{
		if (!$packet instanceof PlayerAuthInputPacket) {
			return null;
		}

		$position = $packet->getPosition();
		$flags = $packet->getInputFlags();
		$control = InputControl::Known->value;

		foreach (InputControl::cases() as $bit) {
			$flag = $bit->inputFlag();

			if ($flag !== null && $flags->getLength() > $flag && $flags->get($flag)) {
				$control |= $bit->value;
			}
		}

		return Frame::input(
			session: $session,
			time: $time,
			tick: $packet->getTick(),
			x: $position->x,
			y: $position->y,
			z: $position->z,
			pitch: $packet->getPitch(),
			yaw: $packet->getYaw(),
			headYaw: $packet->getHeadYaw(),
			mode: $packet->getInputMode(),
			missed: $flags->getLength() > PlayerAuthInputFlags::MISSED_SWING && $flags->get(PlayerAuthInputFlags::MISSED_SWING),
			control: $control,
		);
	}

	private static function attack(int $session, int $time, DataPacket $packet): ?string
	{
		if (!$packet instanceof InventoryTransactionPacket) {
			return null;
		}

		$transaction = $packet->trData;

		if (!$transaction instanceof UseItemOnEntityTransactionData || $transaction->getActionType() !== UseItemOnEntityTransactionData::ACTION_ATTACK) {
			return null;
		}

		return Frame::attack($session, $time, $transaction->getActorRuntimeId());
	}

	private static function animation(int $session, int $time, DataPacket $packet): ?string
	{
		if (!$packet instanceof AnimatePacket) {
			return null;
		}

		return $packet->action === AnimatePacket::ACTION_SWING_ARM ? Frame::animation($session, $time) : null;
	}

	private static function miss(int $session, int $time, DataPacket $packet): ?string
	{
		if (!$packet instanceof LevelSoundEventPacket) {
			return null;
		}

		return $packet->sound === LevelSoundEvent::ATTACK_NODAMAGE ? Frame::swing($session, $time) : null;
	}

	private function latency(int $session, int $time, DataPacket $packet): ?string
	{
		if (!$packet instanceof NetworkStackLatencyPacket) {
			return null;
		}

		return Frame::packet(FrameType::Serverbound, $session, $time, $this->codec->encode($packet));
	}
}
