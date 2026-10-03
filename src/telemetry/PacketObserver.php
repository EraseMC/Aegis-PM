<?php

declare(strict_types=1);

namespace aegis\telemetry;

use aegis\Clock;
use aegis\network\Frame;
use aegis\session\PlayerSession;
use aegis\session\SessionRegistry;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\CorrectPlayerMovePredictionPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MoveActorDeltaPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;

final readonly class PacketObserver
{
	public const int MAX_TARGETS = 128;

	private array $observers;

	public function __construct(private SessionRegistry $sessions)
	{
		$this->observers = [
			AddPlayerPacket::class => $this->movement(...),
			MovePlayerPacket::class => $this->movement(...),
			MoveActorAbsolutePacket::class => $this->movement(...),
			MoveActorDeltaPacket::class => $this->movement(...),
			RemoveActorPacket::class => self::removal(...),
			SetActorMotionPacket::class => self::motion(...),
			ChangeDimensionPacket::class => $this->teleport(...),
			CorrectPlayerMovePredictionPacket::class => $this->teleport(...),
			UpdateBlockPacket::class => self::block(...),
		];
	}

	public function accepts(DataPacket $packet): bool
	{
		return isset($this->observers[$packet::class]);
	}

	public function observe(PlayerSession $viewer, DataPacket $packet): ?string
	{
		$observe = $this->observers[$packet::class] ?? null;

		return $observe === null ? null : $observe($viewer, $packet);
	}

	public function seed(PlayerSession $viewer): void
	{
		$viewer->view->seed = array_slice(array_keys($this->sessions->all()), 0, self::MAX_TARGETS);
	}

	public function position(PlayerSession $viewer, PlayerSession $target, Vector3 $position): void
	{
		$id = $target->player->getId();

		if (!isset($viewer->view->visible[$id]) && count($viewer->view->visible) >= self::MAX_TARGETS) {
			return;
		}

		$viewer->view->visible[$id] = $position;
		$size = $target->player->getSize();
		$half = $size->getWidth() / 2;
		$viewer->view->pending[$id] = Frame::observation(
			$viewer->player->getId(),
			Clock::milliseconds(),
			$id,
			ObservationType::Position->value,
			[$position->x - $half, $position->y, $position->z - $half, $position->x + $half, $position->y + $size->getHeight(), $position->z + $half],
		);
	}

	public static function remove(PlayerSession $viewer, int $id): void
	{
		if (isset($viewer->view->visible[$id])) {
			$viewer->view->pending[$id] = self::emptyObservation($viewer, $id, ObservationType::Remove);
			unset($viewer->view->visible[$id]);
		}
	}

	private function movement(PlayerSession $viewer, DataPacket $packet): ?string
	{
		if (!$packet instanceof AddPlayerPacket && !$packet instanceof MovePlayerPacket
			&& !$packet instanceof MoveActorAbsolutePacket && !$packet instanceof MoveActorDeltaPacket) {
			return null;
		}

		$id = $packet->actorRuntimeId;
		$target = $this->sessions->find($id);

		if ($target === null) {
			return null;
		}

		if ($id === $viewer->player->getId()) {
			return $this->teleport($viewer);
		}

		$position = $packet instanceof MoveActorDeltaPacket ? self::delta($viewer, $target, $packet) : $packet->position;

		if (!$packet instanceof AddPlayerPacket) {
			$position = $position->subtractVector($target->player->getOffsetPosition(new Vector3(0, 0, 0)));
		}

		$this->position($viewer, $target, $position);

		return null;
	}

	private static function delta(PlayerSession $viewer, PlayerSession $target, MoveActorDeltaPacket $packet): Vector3
	{
		$previous = isset($viewer->view->visible[$target->player->getId()])
			? $target->player->getOffsetPosition($viewer->view->visible[$target->player->getId()])
			: $target->player->getOffsetPosition($target->player->getPosition());

		return new Vector3(
			($packet->flags & MoveActorDeltaPacket::FLAG_HAS_X) !== 0 ? $packet->xPos : $previous->x,
			($packet->flags & MoveActorDeltaPacket::FLAG_HAS_Y) !== 0 ? $packet->yPos : $previous->y,
			($packet->flags & MoveActorDeltaPacket::FLAG_HAS_Z) !== 0 ? $packet->zPos : $previous->z,
		);
	}

	private static function removal(PlayerSession $viewer, DataPacket $packet): null
	{
		if ($packet instanceof RemoveActorPacket) {
			self::remove($viewer, $packet->actorUniqueId);
		}

		return null;
	}

	private static function motion(PlayerSession $viewer, DataPacket $packet): ?string
	{
		if (!$packet instanceof SetActorMotionPacket || $packet->actorRuntimeId !== $viewer->player->getId()) {
			return null;
		}

		$motion = $packet->motion;

		return Frame::observation(
			$viewer->player->getId(), Clock::milliseconds(), $packet->actorRuntimeId,
			ObservationType::Motion->value, [$motion->x, $motion->y, $motion->z, 0.0, 0.0, 0.0],
		);
	}

	private function teleport(PlayerSession $viewer, ?DataPacket $packet = null): string
	{
		$viewer->view->clear();
		$this->seed($viewer);

		return self::emptyObservation($viewer, $viewer->player->getId(), ObservationType::Teleport);
	}

	private static function block(PlayerSession $viewer, DataPacket $packet): null
	{
		if (!$packet instanceof UpdateBlockPacket) {
			return null;
		}

		$position = $packet->blockPosition;

		if ($viewer->player->getPosition()->distanceSquared(new Vector3($position->getX(), $position->getY(), $position->getZ())) < 16) {
			$viewer->view->pending[ObservationType::Block->name] = self::emptyObservation($viewer, 0, ObservationType::Block);
		}

		return null;
	}

	private static function emptyObservation(PlayerSession $viewer, int $target, ObservationType $type): string
	{
		return Frame::observation($viewer->player->getId(), Clock::milliseconds(), $target, $type->value, [0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);
	}
}
