# MPMPESCore

**MPMPESCore** is a modified Minecraft: Bedrock Edition (MCPE) server core, built on top of **Genisys** by mpmpes.

- Core name: `MPMPESCore`
- Version: `1.0`
- Based on: [Genisys](https://github.com/iTXTech/Genisys) (a fork of PocketMine-MP by iTX Technologies)
- Source: https://github.com/mpmp666/MPMPESCore

> This core is a modified build based on Genisys. The original Genisys is a fork of PocketMine-MP made by iTX Technologies LLC.

## What's different from stock Genisys

- **True multithreading** via the `pmmpthread` extension (real OS threads, not the old pthreads shim).
  - RakLib network thread runs on its own OS thread.
  - Async worker tasks run in parallel worker threads.
  - Verified at runtime: the PHP process spawns multiple OS threads (kernel `Threads: 6+`).
- **PHP 8.4** runtime (ZTS build with `pmmpthread` 6.3).
- Core startup banner declares it is **based on Genisys** and shows the **PHP version** (ZTS/NTS).
- `ver` / `version` / `about` command additionally shows the PHP version and OS.
- `E_DEPRECATED` warnings are suppressed at the entry point to keep console output clean on PHP 8.4.
- Startup gate: requires **PHP >= 8.0**, **ZTS** build, and the **pmmpthread** extension — otherwise it refuses to start.

## Requirements

- PHP **8.0+** built with **ZTS** (Zend Thread Safety) enabled
- `pmmpthread` extension (6.x)
- Extensions: `sockets`, `curl`, `yaml`, `sqlite3`, `zlib`
- A Minecraft: Bedrock Edition client matching the target protocol

### PHP binary

Download a prebuilt PHP from the official pmmp binaries repository:

**https://github.com/pmmp/PHP-Binaries**

> **Use PHP 8.4** — this is the only version that has been verified to run MPMPESCore. Other PHP versions are untested and may not work (ZTS + pmmpthread 6.x required).

The bundled `bin/php7/bin/php` is a PHP 8.4.16 ZTS build with `pmmpthread` 6.3.0 included, so you can run it directly without compiling PHP yourself. If you need to replace it, grab a **PHP 8.4 ZTS** build from the link above.

## Running

```bash
./start.sh
```

Or directly:

```bash
./bin/php7/bin/php ./src/pocketmine/PocketMine.php
```

On first run it will generate `server.properties`, `pocketmine.yml`, and `genisys.yml`. Edit them as needed, then restart.

## Commands

- `ver` / `version` / `about` — show core name, version, API version, and PHP version.
- `status` — show TPS / player count.
- Standard PocketMine/Genisys commands are supported.

## Configuration highlights (`genisys.yml`)

- `redstone.enable: true` — redstone mechanics
- `ai.enable: true` — entity AI
- `level.mobgenerate: true` — mob spawning

## License

MPMPESCore is derived from Genisys, which is licensed under the **LGPL**. MPMPESCore is distributed under the same LGPL license.

## Credits

- PocketMine-MP Team
- iTX Technologies LLC (Genisys)
- pmmpthread / pmmp team (true multithreading)
- mpmpes (MPMPESCore modifications)
