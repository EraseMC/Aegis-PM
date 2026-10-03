<?php

declare(strict_types=1);

namespace aegis\config;

use aegis\BridgeException;
use pocketmine\utils\Config;

final readonly class BridgeConfig
{
	public function __construct(
		public string $host,
		public int $port,
		public bool $punish,
		public int $banSeconds,
		public bool $alertsDefault,
		public int $alertCooldown,
		public bool $logAlerts,
	) {}

	public static function load(Config $config): self
	{
		$host = $config->getNested('connection.host');

		if (!is_string($host) || $host === '' || strlen($host) > 253 || preg_match('/^[a-zA-Z0-9.:_-]+$/D', $host) !== 1) {
			throw new BridgeException('connection.host must be an IP address or hostname');
		}

		return new self(
			$host,
			self::integer($config, 'connection.port', 1, 65535),
			self::boolean($config, 'punishments.enabled'),
			self::integer($config, 'punishments.ban-duration-seconds', 0, 315360000),
			self::boolean($config, 'alerts.enabled-by-default'),
			self::integer($config, 'alerts.cooldown-milliseconds', 100, 60000),
			self::boolean($config, 'alerts.log'),
		);
	}

	private static function boolean(Config $config, string $key): bool
	{
		$value = $config->getNested($key);

		if (!is_bool($value)) {
			throw new BridgeException(sprintf('%s must be a boolean', $key));
		}

		return $value;
	}

	private static function integer(Config $config, string $key, int $minimum, int $maximum): int
	{
		$value = $config->getNested($key);

		if (!is_int($value) || $value < $minimum || $value > $maximum) {
			throw new BridgeException(sprintf('%s must be an integer between %d and %d', $key, $minimum, $maximum));
		}

		return $value;
	}
}
