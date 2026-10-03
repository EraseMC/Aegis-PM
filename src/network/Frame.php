<?php

declare(strict_types=1);

namespace aegis\network;

use aegis\BridgeException;

final class Frame
{
	public const int VERSION = 2;

	public static function hello(int $shieldId, string $server): string
	{
		return self::wrap(FrameType::Hello, pack('vV', self::VERSION, $shieldId & 0xffffffff) . self::string($server));
	}

	public static function join(int $session, string $name, string $xuid, int $protocol, string $version, int $deviceOs, int $inputMode): string
	{
		return self::wrap(FrameType::Join, pack('P', $session) . self::string($name) . self::string($xuid)
			. pack('V', $protocol) . self::string($version) . pack('VV', $deviceOs & 0xffffffff, $inputMode));
	}

	public static function quit(int $session): string
	{
		return self::wrap(FrameType::Quit, pack('P', $session));
	}

	public static function packet(FrameType $type, int $session, int $time, string $payload): string
	{
		return self::wrap($type, pack('PPV', $session, $time, strlen($payload)) . $payload);
	}

	public static function tick(int $tick, int $time): string
	{
		return self::wrap(FrameType::Tick, pack('PP', $tick, $time));
	}

	public static function observation(int $session, int $time, int $target, int $kind, array $box): string
	{
		return self::wrap(FrameType::Observation, pack('PPPCg6', $session, $time, $target, $kind, ...$box));
	}

	public static function state(int $session, int $time, int $flags, float $speed, float $jump, float $offset, float $x, float $y, float $z): string
	{
		return self::wrap(FrameType::State, pack('PPVg6', $session, $time, $flags, $speed, $jump, $offset, $x, $y, $z));
	}

	public static function input(int $session, int $time, int $tick, float $x, float $y, float $z, float $pitch, float $yaw, float $headYaw, int $mode, bool $missed, int $control = 0): string
	{
		return self::wrap(FrameType::Input, pack('PPPg6VCC', $session, $time, $tick, $x, $y, $z, $pitch, $yaw, $headYaw, $mode, (int) $missed, $control));
	}

	public static function attack(int $session, int $time, int $target): string
	{
		return self::wrap(FrameType::Attack, pack('PPP', $session, $time, $target));
	}

	public static function swing(int $session, int $time): string
	{
		return self::wrap(FrameType::Swing, pack('PP', $session, $time));
	}

	public static function animation(int $session, int $time): string
	{
		return self::wrap(FrameType::Animation, pack('PP', $session, $time));
	}

	private static function string(string $value): string
	{
		$value = substr($value, 0, 0xffff);

		return pack('v', strlen($value)) . $value;
	}

	private static function wrap(FrameType $type, string $body): string
	{
		return pack('VC', strlen($body) + 1, $type->value) . $body;
	}

	public static function readU8(string $body, int &$offset): int
	{
		$value = self::unpack('C', 1, $body, $offset);

		return is_int($value) ? $value : 0;
	}

	public static function readU16(string $body, int &$offset): int
	{
		$value = self::unpack('v', 2, $body, $offset);

		return is_int($value) ? $value : 0;
	}

	public static function readU64(string $body, int &$offset): int
	{
		$value = self::unpack('P', 8, $body, $offset);

		return is_int($value) ? $value : 0;
	}

	public static function readF32(string $body, int &$offset): float
	{
		$value = self::unpack('g', 4, $body, $offset);

		return is_float($value) ? $value : 0.0;
	}

	public static function readString(string $body, int &$offset): string
	{
		$length = self::readU16($body, $offset);

		if ($offset + $length > strlen($body)) {
			throw new BridgeException(sprintf('String of %d bytes runs past the frame at %d', $length, $offset));
		}

		$value = substr($body, $offset, $length);
		$offset += $length;

		return $value;
	}

	private static function unpack(string $format, int $size, string $body, int &$offset): mixed
	{
		if ($offset + $size > strlen($body)) {
			throw new BridgeException(sprintf('Frame ends at %d, %d more bytes expected', strlen($body), $size));
		}

		$value = unpack($format, $body, $offset);
		$offset += $size;

		return $value === false ? null : $value[1];
	}
}
