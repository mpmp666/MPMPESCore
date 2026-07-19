# MPMPESCore

**MPMPESCore** is a modified Minecraft: Bedrock Edition (MCPE) server core, built on top of **Genisys** by mpmpes.

- Core name: `MPMPESCore`
- Version: `1.0`
- Based on: [Genisys](https://github.com/iTXTech/Genisys) (a fork of PocketMine-MP by iTX Technologies)
- Source: https://github.com/mpmp666/MPMPESCore

> This core is a modified build based on Genisys. The original Genisys is a fork of PocketMine-MP made by iTX Technologies LLC.

## Requirements

- PHP **8.4** built with **ZTS** (Zend Thread Safety) enabled and the `pmmpthread` extension
- Extensions: `sockets`, `curl`, `yaml`, `sqlite3`, `zlib`
- A Minecraft: Bedrock Edition client matching the target protocol

### PHP binary

Download a prebuilt PHP from the official pmmp binaries repository:

**https://github.com/pmmp/PHP-Binaries**

> Use **PHP 8.4** — this is the only version that has been verified to run MPMPESCore (ZTS + pmmpthread 6.x required).

The bundled `bin/php7/bin/php` is a PHP 8.4.16 ZTS build with `pmmpthread` 6.3.0 included, so you can run it directly without compiling PHP yourself.

## How to run

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
- pmmp / pmmpthread team
- mpmpes (MPMPESCore modifications)

Thanks to everyone who contributed to the projects MPMPESCore is built upon.

## 测试服 (Test Server)

当前在线测试服地址：

- **IP:** `148.100.112.191`
- **端口 (Port):** `19132`
- **版本:** Minecraft: Bedrock Edition (MCPE) 0.14.x
- **连接方式:** 在 Minecraft 基岩版客户端「添加服务器」中填写上述 IP 与端口即可

> 测试服由 mpmpes 维护，服务器名「金安卓」。
