<?php

declare(strict_types=1);

namespace aegis\message;

use aegis\Detection;
use pocketmine\lang\Translatable;

final class Messages
{
	private function __construct() {}

	public static function flag(string $player, Detection $detection): Translatable
	{
		return new Translatable('§7[§aAegis§7] §f{%0} §7failed §e{%1} §7({%2}/{%3})', [
			$player,
			$detection->name,
			number_format($detection->violations, 1, '.', ''),
			number_format($detection->maxViolations, 1, '.', ''),
		]);
	}

	public static function punishment(string $player, Detection $detection, bool $enabled): Translatable
	{
		return new Translatable('§7[§aAegis§7] §f{%0} §7reached the §e{%1} §7threshold: {%2}', [
			$player,
			$detection->name,
			$enabled ? $detection->action->value : 'monitor mode',
		]);
	}

	public static function disconnect(Detection $detection): Translatable
	{
		return new Translatable('Aegis: {%0}', [$detection->name]);
	}

	public static function alerts(bool $enabled): Translatable
	{
		return new Translatable('§7[§aAegis§7] Alerts: {%0}', [$enabled ? '§aenabled' : '§cdisabled']);
	}

	public static function status(bool $connected, bool $punishments): Translatable
	{
		return new Translatable('§7[§aAegis§7] Connection: {%0}§7. Punishments: {%1}', [
			$connected ? '§aconnected' : '§cdisconnected',
			$punishments ? '§aenabled' : '§emonitor mode',
		]);
	}

	public static function usage(): Translatable
	{
		return new Translatable('Usage: /aegis [status|alerts [on|off]]');
	}

	public static function playerOnly(): Translatable
	{
		return new Translatable('Use this command in game to change your alerts');
	}
}
