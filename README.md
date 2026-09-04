# MPMPESCore

**MPMPESCore** 是一个修改版的 Minecraft: 基岩版（MCPE）服务端核心，由 mpmpes 基于 **Genisys** 构建。

- 核心名称：`MPMPESCore`
- 版本：`1.0`
- 基于：[Genisys](https://github.com/iTXTech/Genisys)（iTX Technologies 出品的 PocketMine-MP 分支）
- 源码：https://github.com/mpmp666/MPMPESCore
- 此代码100%AI生成 请勿找茬

> 本核心是基于 Genisys 的修改构建。原版 Genisys 是 iTX Technologies LLC 制作的 PocketMine-MP 分支。

## 使用到的 AI 模型

本仓库的全部代码、文档、示例均由 AI 生成 / 协作完成，使用到的模型包括：

- **DeepSeek v4 Flash 0731**
- **Hy3**
- **MiniMax M3**
- **Nemotron 3 Ultra**
- **GLM-5.3-Flash**
- **MiMo V2.5 Free**

## ✨ 新增功能与改进

### 🌍 多维度世界生成

| 维度 | 生成器 | 特性 |
|------|--------|------|
| **主世界** | Normal | 原版生态群系、洞穴、结构 |
| **地狱** | Nether | 石英矿、辉石、熔岩湖、地狱要塞 |
| **星界 (Astral)** | Astral | 末地石浮岛 + **按种子生成的小荧石岛**（无黑曜石柱） |
| **末地 (Ender)** | Ender | 主岛 + 10 根环形黑曜石柱（去掉中心柱）、外岛带、末影人 |

- `genisys.yml` 中通过 `astral.allow-astral`、`ender.allow-ender`、`nether.allow-nether` 独立开关
- 维度名称可自定义（`astral.level-name`、`ender.level-name`、`nether.level-name`）

### ⚡ MPApi 1.0 —— 高性能插件 API

零分配、零缓存污染、不触发区块加载的高性能接口，适合大规模循环/批量操作：

- **原始方块读写**：`getFullStateAt()` / `setBlockRaw()` / `fillBlocksRaw()` —— 无 `Block` 对象分配，快数倍
- **碰撞查询**：`getEntityCollidingBlocks()` / `isBlockSolidAt()` —— 按列走 chunk 索引
- **实体检索**：`getNearbyEntities()` —— AABB 三轴扩展，走区块实体索引不遍历全表
- **高度图**：`getHighestBlockAt()` —— 基于 chunk heightMap，零扫描开销
- **AI 控制器**：`getAI()` —— 刷怪、仇恨半径、追击速度动态调整
- **地图媒体 API**：`createDynamicMap()` / `setMapImageFromFile()` / `getMapItem()` —— 支持 **Bad Apple 视频播放**、**图片展示**、**物品展示框渲染**（动态地图 ID 从 20000 起，不落盘，重登需补发）
- **frp 隧道 API**：`isFrpEnabled()` / `getFrpTunnels()` / `restartFrp()` / `stopFrp()`
- **玩家真实地址**：`getPlayerAddress()` / `getPlayerPort()` / `getPlayerEntryAddress()` —— 经 PROXY v2 还原

> 插件在 `plugin.yml` 声明 `mpapi: "1.0"` 即可使用；版本高于服务端的插件会被自动禁用。

### 🚇 内置 frp 内网穿透（同进程零依赖）

- 服务器根目录放 `frp.toml`（默认）或 `frp_<名字>.toml`（多隧道）即可自动启动
- 纯 PHP 实现 `frpc.php`，与服务端同进程/线程非阻塞运行，**无额外 PID、无额外日志文件**
- 支持 `transport.proxyProtocolVersion = "v2"`，RakLib 自动解析 PROXY 头，**还原玩家真实公网 IP/端口**
- 指令 `/frp [status|restart|stop]`（仅 OP/控制台）管理隧道

### 🛠️ 漏斗 / 漏斗矿车 修复与增强

- **Hopper（固定漏斗）**：
  - 新增 `findFirstItem()` 通用取物方法，**不依赖 `firstOccupied()`**，兼容所有 `BaseInventory` 子类（箱子、熔炉、发射器等）
  - 每 tick 仅吸 **1 个掉落物**，防止一 tick 吞掉整堆
  - **已修复并排漏斗刷物 bug**：两个并排放置的漏斗同时拾取同一掉落物的问题，通过在拾取循环中增加 `isAlive` / `closed` 检查修复
  - 红石锁定：`isPowered` 为 true 时暂停传输
  - 8 tick 传输冷却，`transferCooldown` 纯 PHP 属性避免 NBT 序列化 NPE

- **MinecartHopper（漏斗矿车）**：
  - 实体 ID 96，自动吸取上方容器/掉落物、向下方容器推送
  - 移动中同样工作，支持 NBT 持久化 `TransferCooldown`
  - 已注册实体网络 ID，客户端可正常显示

#### 🐛 已知问题

- **地图客户端崩溃**：玩家手持填充地图（Filled Map）时，客户端会崩溃。该问题在引入 FRP 直接喂包（frpc → RakNet）特性时出现，目前在局域网和 FRP 两种连接方式下均能复现。SCAXE 原版核心无此问题，已对比 RakNet 层代码但尚未定位到根因。

### 🎮 其他特性

- **Enderman 传送音效**：传送门触发、末影人传送均播放 `EndermanTeleportSound`
- **末地时间锁定**：`ender` 维度时间永远锁定为夜晚（`Level::TIME_NIGHT`）
- **Synapse API 预留**：`synapse.enabled` 支持跨服通信（需外部 Synapse 服务端）
- **多服统一查询**：`dserver` 支持多服务器 Motd/Query 聚合
- **JSON 配方/创造物品表**：`recipes-from-json`、`creative-items-from-json` 可选读取外部 JSON

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

- `ver` / `version` / `about` —— 显示核心名称、版本、API 版本、PHP 版本、**MPApi 版本**
- `status` —— 显示 TPS / 在线玩家数
- `frp` —— 内置 frp 隧道管理（仅 OP/控制台）：`/frp [status|restart|stop]`
- 支持标准的 PocketMine / Genisys 指令

## 许可证

MPMPESCore 衍生自 Genisys，Genisys 采用 **LGPL** 许可。MPMPESCore 以相同的 LGPL 许可分发。

## 致谢

- PocketMine-MP 团队
- iTX Technologies LLC（Genisys）
- pmmp / pmmpthread 团队
- mpmpes（MPMPESCore 的修改）

感谢所有为 MPMPESCore 所基于项目做出贡献的人。