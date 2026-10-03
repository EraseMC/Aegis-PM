# Aegis-PM

A standalone PocketMine-MP bridge for [Aegis](https://github.com/EraseMC/Aegis).

The plugin forwards player input, attacks and server observations to the Aegis sidecar over TCP. Aegis runs the checks; this plugin displays detections and applies configured punishments.

## Requirements

- PocketMine-MP 5.44 or later in the 5.x series.
- PHP 8.3 or later, 64-bit, with the sockets extension.
- A running Aegis sidecar using wire protocol version 2, including the animation frame added in October 2026.

The plugin has no runtime Composer dependencies. It works independently of Erase Practice.

## Installation

1. Download `Aegis-PM.phar` from [Releases](https://github.com/EraseMC/Aegis-PM/releases/latest).
2. Put it in the server's `plugins` directory and start PocketMine-MP.
3. Set the sidecar host and port in `plugin_data/AegisPM/config.yml`.
4. Restart the server and check `/aegis status`.

To build and run the sidecar:

```sh
git clone https://github.com/EraseMC/Aegis.git
cd Aegis
go build -o aegis ./cmd/aegis
cp aegis.example.json aegis.json
./aegis -config aegis.json
```

Aegis requires Go 1.25.1 or later to build. Set its `listen` address to `127.0.0.1:19140` when both processes run on the same host. The TCP connection is a trusted server interface without authentication; keep it on loopback or a private network restricted to the server.

For containers, use a shared private network and the Aegis service hostname. The plugin resolves hostnames asynchronously.

## Configuration

```yaml
connection:
  host: 127.0.0.1
  port: 19140

punishments:
  enabled: false
  ban-duration-seconds: 1209600

alerts:
  enabled-by-default: true
  cooldown-milliseconds: 1000
  log: true
```

The default is monitor mode: detections are reported, but players are not kicked or banned. Enable `punishments.enabled` after validating the checks with your server's movement and combat rules. Check thresholds and actions are configured in the Aegis sidecar.

Supported actions are `none`, `kick` and `ban`. Bans use PocketMine's name ban list. The default duration is 14 days; set `ban-duration-seconds` to `0` for permanent bans. Alert preferences are kept until the player leaves. The cooldown applies to each player's individual check; punishment notifications are sent immediately.

If Aegis disconnects, PocketMine continues running. The bridge retries every five seconds and resends online players and their observations. Socket queues, frame sizes and telemetry work per tick are bounded; exceeding a budget rebuilds the connection instead of checking incomplete state.

## Commands and permissions

| Command | Purpose |
| --- | --- |
| `/aegis` or `/aegis status` | Show the connection and punishment mode |
| `/aegis alerts` | Toggle your detection alerts |
| `/aegis alerts on` | Enable your detection alerts |
| `/aegis alerts off` | Disable your detection alerts |

| Permission | Default | Purpose |
| --- | --- | --- |
| `aegis.command` | Operator | Use the administration command |
| `aegis.alerts` | Operator | Receive alerts and change alert preferences |
| `aegis.bypass` | Nobody | Skip alerts and punishments for this player |

## Integration

Other plugins can listen to `aegis\event\DetectionEvent`. Its getters expose the player, detection and whether the verdict requests a punishment. Cancelling the event skips both notifications and enforcement. The detection includes the check name, violation count, threshold, evidence and action.

## Development

Use PocketMine's PHP binary so the required extensions are available:

```sh
composer install
composer lint
composer test
composer analyse
composer build
```

The build produces `dist/Aegis-PM.phar`. Tests cover fragmented wire frames, invalid verdicts, bounded batches, socket handshakes, reconnects, backpressure and handshake timeouts. PHPStan runs at level 8. GitHub Actions checks each change and builds the phar; version tags publish a release after the checks pass.

## License

[MIT](LICENSE).
