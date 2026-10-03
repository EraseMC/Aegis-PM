<?php

declare(strict_types=1);

namespace aegis\telemetry;

use aegis\Clock;
use aegis\network\Frame;
use pocketmine\block\Block;
use pocketmine\block\Cobweb;
use pocketmine\block\Ladder;
use pocketmine\block\Liquid;
use pocketmine\block\Slime;
use pocketmine\block\Vine;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\World;

final readonly class WorldSampler
{
	private const float EDGE_MARGIN = 0.12;

	public function __construct(private Server $server) {}

	public function sample(Player $player): string
	{
		$position = $player->getPosition();
		$box = $player->getBoundingBox();
		$flags = $this->playerFlags($player);
		$cells = [];

		foreach ([$box->minX - self::EDGE_MARGIN, $box->maxX + self::EDGE_MARGIN] as $x) {
			foreach ([$box->minZ - self::EDGE_MARGIN, $box->maxZ + self::EDGE_MARGIN] as $z) {
				$flags |= self::column($player->getWorld(), $box, $x, $z, $cells);
			}
		}

		return Frame::state(
			session: $player->getId(),
			time: Clock::milliseconds(),
			flags: $flags,
			speed: $player->getMovementSpeed(),
			jump: $player->getJumpVelocity(),
			offset: $player->getOffsetPosition($position)->y - $position->y,
			x: $position->x,
			y: $position->y,
			z: $position->z,
		);
	}

	private function playerFlags(Player $player): int
	{
		return StateFlag::Physics->value
			| ($player->getAllowFlight() || $player->isFlying() || $player->isSpectator() ? StateFlag::Flying->value : 0)
			| ($player->isGliding() || $player->isSwimming() || $player->isUnderwater()
				|| $player->getEffects()->has(VanillaEffects::LEVITATION()) ? StateFlag::Special->value : 0)
			| ($this->server->getTicksPerSecond() < 18 ? StateFlag::Lagging->value : 0)
			| (!$player->isAlive() ? StateFlag::Dead->value : 0)
			| ($player->hasNoClientPredictions() ? StateFlag::Frozen->value : 0);
	}

	private static function column(World $world, AxisAlignedBB $box, float $x, float $z, array &$visited): int
	{
		$flags = 0;
		$bx = (int) floor($x);
		$bz = (int) floor($z);

		foreach ([$box->minY - 0.1, $box->minY + 0.1, $box->maxY + 0.1] as $y) {
			$by = (int) floor($y);
			$key = "$bx:$by:$bz";

			if (isset($visited[$key])) {
				continue;
			}

			$visited[$key] = true;
			$flags |= $world->isChunkLoaded($bx >> 4, $bz >> 4)
				? self::blockFlags($world->getBlockAt($bx, $by, $bz), $box)
				: StateFlag::Special->value;
		}

		return $flags;
	}

	private static function blockFlags(Block $block, AxisAlignedBB $box): int
	{
		$flags = $block instanceof Liquid || $block instanceof Ladder || $block instanceof Vine
			|| $block instanceof Cobweb || $block instanceof Slime || $block->getFrictionFactor() > 0.65
			? StateFlag::Special->value
			: 0;

		foreach ($block->getCollisionBoxes() as $collision) {
			$flags |= self::collisionFlags($collision, $box);
		}

		return $flags;
	}

	private static function collisionFlags(AxisAlignedBB $collision, AxisAlignedBB $box): int
	{
		$flags = 0;

		if ($collision->maxY > $box->minY + 0.2 && $collision->minY < $box->maxY - 0.1
			&& $collision->maxX > $box->minX - self::EDGE_MARGIN && $collision->minX < $box->maxX + self::EDGE_MARGIN
			&& $collision->maxZ > $box->minZ - self::EDGE_MARGIN && $collision->minZ < $box->maxZ + self::EDGE_MARGIN) {
			$flags |= StateFlag::Obstructed->value;
		}

		if ($collision->maxX - $collision->minX < 0.999 || $collision->maxY - $collision->minY < 0.999
			|| $collision->maxZ - $collision->minZ < 0.999) {
			$flags |= StateFlag::Special->value;
		}

		if ($collision->maxX <= $box->minX || $collision->minX >= $box->maxX
			|| $collision->maxZ <= $box->minZ || $collision->minZ >= $box->maxZ) {
			return $flags;
		}

		return $flags
			| (abs($collision->maxY - $box->minY) < 0.15 ? StateFlag::Ground->value : 0)
			| (abs($collision->minY - $box->maxY) < 0.2 ? StateFlag::Ceiling->value : 0);
	}
}
