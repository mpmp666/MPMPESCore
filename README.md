# MPMPESCore

**MPMPESCore** 是一个修改版的 Minecraft: 基岩版（MCPE）服务端核心，由 mpmpes 基于 **Genisys** 构建。

- 核心名称：`MPMPESCore`
- 版本：`1.0`
- 基于：[Genisys](https://github.com/iTXTech/Genisys)（iTX Technologies 出品的 PocketMine-MP 分支）
- 源码：https://github.com/mpmp666/MPMPESCore

> 本核心是基于 Genisys 的修改构建。原版 Genisys 是 iTX Technologies LLC 制作的 PocketMine-MP 分支。

## 运行环境要求

- 需要开启了 **ZTS**（Zend 线程安全）的 **PHP 8.4**，并装有 `pmmpthread` 扩展
- 所需扩展：`sockets`、`curl`、`yaml`、`sqlite3`、`zlib`
- 需要与目标协议匹配的 Minecraft: 基岩版客户端

### PHP 二进制文件

可从官方 pmmp 二进制仓库下载预编译的 PHP：

**https://github.com/pmmp/PHP-Binaries**

> 请使用 **PHP 8.4** —— 这是目前唯一验证过可运行 MPMPESCore 的版本（需 ZTS + pmmpthread 6.x）。

自带的 `bin/php7/bin/php` 是一个 PHP 8.4.16 ZTS 构建，已包含 `pmmpthread` 6.3.0，因此你可以直接运行，无需自己编译 PHP。

## 如何运行

```bash
./start.sh
```

或者直接用命令运行：

```bash
./bin/php7/bin/php ./src/pocketmine/PocketMine.php
```

首次运行会生成 `server.properties`、`pocketmine.yml` 和 `genisys.yml`。按需修改后重启即可。

## 指令

- `ver` / `version` / `about` —— 显示核心名称、版本、API 版本和 PHP 版本。
- `status` —— 显示 TPS / 在线玩家数。
- 支持标准的 PocketMine / Genisys 指令。

## 配置要点（`genisys.yml`）

- `redstone.enable: true` —— 红石机关
- `ai.enable: true` —— 实体 AI
- `level.mobgenerate: true` —— 怪物生成

## 许可证

MPMPESCore 衍生自 Genisys，Genisys 采用 **LGPL** 许可。MPMPESCore 以相同的 LGPL 许可分发。

## 致谢

- PocketMine-MP 团队
- iTX Technologies LLC（Genisys）
- pmmp / pmmpthread 团队
- mpmpes（MPMPESCore 的修改）

感谢所有为 MPMPESCore 所基于项目做出贡献的人。

## 测试服

当前在线测试服地址：

- **IP：** `148.100.112.191`
- **端口（Port）：** `19132`
- **版本：** Minecraft: 基岩版（MCPE）0.14.x
- **连接方式：** 在 Minecraft 基岩版客户端的「添加服务器」中填写上述 IP 与端口即可

> 测试服由 mpmpes 维护，服务器名为「金安卓」。
