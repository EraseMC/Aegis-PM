<?php

declare(strict_types=1);

namespace aegis\tests;

use aegis\Action;
use aegis\BridgeException;
use aegis\config\BridgeConfig;
use aegis\network\Frame;
use aegis\network\FrameBuffer;
use aegis\network\FrameType;
use Closure;
use pocketmine\utils\Config;
use RuntimeException;

require __DIR__ . '/bootstrap.php';

function expect(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function rejected(Closure $operation): void
{
	try {
		$operation();
	} catch (BridgeException) {
		return;
	}

	throw new RuntimeException('Invalid frame was accepted');
}

function frame(FrameType $type, string $body): string
{
	return pack('VC', strlen($body) + 1, $type->value) . $body;
}

function welcome(): string
{
	return frame(FrameType::Welcome, pack('v', Frame::VERSION));
}

function flag(float $violations = 3.5, float $maximum = 10): string
{
	$check = 'Reach_A';
	$data = 'distance=3.2';

	return frame(FrameType::Flag, pack('Pv', 42, strlen($check)) . $check . pack('g2v', $violations, $maximum, strlen($data)) . $data);
}

$stream = welcome() . flag();

for ($split = 0; $split <= strlen($stream); ++$split) {
	$buffer = new FrameBuffer();
	$buffer->append(substr($stream, 0, $split));
	$first = $buffer->drain();
	$buffer->append(substr($stream, $split));
	$verdicts = [...$first, ...$buffer->drain()];
	expect($buffer->welcomed() && count($verdicts) === 1, sprintf('Fragmentation failed at byte %d', $split));
	$verdict = $verdicts[0];
	expect($verdict->session === 42 && !$verdict->punish && $verdict->detection->name === 'Reach_A', 'Flag identity changed');
	expect($verdict->detection->violations === 3.5 && $verdict->detection->data === 'distance=3.2', 'Flag evidence changed');
}

$buffer = new FrameBuffer();
$buffer->append(welcome() . str_repeat(flag(), 300));
expect(count($buffer->drain()) === 255, 'Frame batch was not bounded');
expect(count($buffer->drain()) === 45, 'Buffered verdicts were lost');
$buffer->clear();
expect(!$buffer->welcomed() && $buffer->drain() === [], 'Handshake state survived reset');

foreach ([
	flag(),
	welcome() . welcome(),
	frame(FrameType::Welcome, pack('v', 1)),
	pack('V', 0) . "\x81",
	pack('V', (1 << 20) + 1) . "\x81",
	welcome() . flag(NAN),
	welcome() . flag(INF),
	welcome() . flag(-1),
	welcome() . flag(1, 0),
	welcome() . frame(FrameType::Flag, "\x00"),
	welcome() . frame(FrameType::Tick, ''),
] as $invalid) {
	rejected(static function () use ($invalid): void {
		$buffer = new FrameBuffer();
		$buffer->append($invalid);
		$buffer->drain();
	});
}

foreach ([Action::None, Action::Kick, Action::Ban] as $wire => $action) {
	$check = 'Fly_A';
	$payload = pack('Pv', 42, strlen($check)) . $check . pack('gCv', 10, $wire, 0);
	$buffer = new FrameBuffer();
	$buffer->append(welcome() . frame(FrameType::Punish, $payload));
	$verdict = $buffer->drain()[0];
	expect($verdict->punish && $verdict->detection->action === $action, 'Punishment action changed');
}

rejected(static fn () => Action::fromWire(3));

$input = Frame::input(42, 1000, 20, 1, 2, 3, 0, 90, 90, 1, false, 129);
expect(strlen($input) === 59 && ord($input[4]) === FrameType::Input->value && ord($input[58]) === 129, 'Input wire layout changed');
expect(ord(Frame::animation(42, 1000)[4]) === FrameType::Animation->value, 'Animation was encoded as a miss sound');
fwrite(STDOUT, "Wire framing, fragmentation, validation, reset and action checks passed\n");

if (class_exists(\SimpleLogger::class)) {
	require __DIR__ . '/ConnectionTest.php';
	ConnectionTest::run();
}

if (class_exists(Config::class)) {
	$config = new Config(dirname(__DIR__) . '/resources/config.yml', Config::YAML);
	$defaults = BridgeConfig::load($config);
	expect(!$defaults->punish && $defaults->port === 19140, 'The default configuration does not start in monitor mode');

	foreach ([
		['connection.host', ''],
		['connection.host', 'host/path'],
		['connection.port', 0],
		['connection.port', 65536],
		['connection.port', '19140'],
		['punishments.enabled', 'false'],
		['punishments.ban-duration-seconds', -1],
		['alerts.enabled-by-default', 1],
		['alerts.cooldown-milliseconds', 99],
		['alerts.cooldown-milliseconds', 60001],
		['alerts.log', null],
	] as [$key, $value]) {
		$invalid = clone $config;
		$invalid->setNested($key, $value);
		rejected(static fn () => BridgeConfig::load($invalid));
	}

	fwrite(STDOUT, "Configuration defaults and invalid setting checks passed\n");
}
