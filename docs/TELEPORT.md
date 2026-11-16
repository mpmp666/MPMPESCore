# /tp 跨世界与旧语法

权限保持 `pocketmine.command.teleport`，OP 默认可用。控制台命令不写开头的 `/`。

## 推荐跨世界写法

```text
/tp world ender
/tp world ender 100 49 0
/tp Steve world ender
/tp Steve world ender 100 49 0
/tp Steve world world 216 70 56
```

`world` 是明确指定目标世界的关键字。最后一行第一个 `world` 是关键字，第二个 `world` 是主世界名称。

没有坐标时使用目标世界的安全出生点。目标世界必须已经存在；尚未加载的已有世界会尝试加载，拼错名字不会创建新世界。

## 保留的旧语法

| 写法 | 用途 |
| --- | --- |
| `/tp Alex` | 自己传送到在线玩家 Alex |
| `/tp Steve Alex` | 把 Steve 传送到 Alex，支持控制台 |
| `/tp 216 70 56` | 自己在当前世界传送 |
| `/tp Steve 216 70 56` | 把 Steve 传送到当前所在世界的坐标 |
| `/tp ~10 ~ ~-5` | 相对自己当前坐标传送 |
| `/tp Steve ~10 ~ ~-5` | 相对 Steve 的当前坐标传送 |
| `/tp 216 70 56 90 0` | 自己传送并设置 yaw/pitch |
| `/tp Steve 216 70 56 90 0` | 给 Steve 指定坐标和朝向 |

坐标及朝向中的 `~` 都相对于被移动的玩家。指定目标世界时，`~` 仍以该玩家传送前的位置为基准。

## 简写兼容

```text
/tp ender
/tp Steve ender
/tp ender 100 49 0
/tp Steve ender 100 49 0
/tp 100 49 0 ender
/tp Steve 100 49 0 ender
```

在线玩家匹配优先于同名世界。遇到玩家和世界重名，或数字世界名称，请使用带 `world` 关键字的明确写法。

坐标支持有限数值及相对坐标，X/Z 范围为 ±30,000,000，Y 为 0..127；朝向必须成对提供。缺坐标、非法数字、缺玩家、无权限、世界不存在或传送被取消时，不会报告成功。

## 验证

```bash
./bin/php7/bin/php tools/test_teleport.php
```

已通过 33 种有效写法、41 种非法写法和 357 项断言，覆盖旧语法、世界加载、相对坐标、朝向、同名优先级和失败反馈。命令使用原有 `Player::teleport()` 流程及绑定目标 Level 的 `Position`；测试尚未包含真实 MCPE 客户端跨世界后的画面观察。
