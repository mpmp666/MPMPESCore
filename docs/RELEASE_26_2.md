# 26.2 参考适配更新

本次面向 MPMPESCore 旧 MCPE 客户端协议，加入 26.2 结构资源、末地生成、现有生物行为及附魔修复。它不等同于完整 Minecraft Java 26.2 服务端；以下兼容限制是当前实现的一部分。

## 主要变化

- 转换并接入 1,212 个结构模板、188 个拼图池及 1,355 个战利品表，提供自然生成、容器适配及 `/structure` 放置命令。
- 末地基础噪声与末地城递归布局参考 26.2 源码，并有 Java 对照；保留旧世界高度和材质替代限制。
- 为已有 26 类生物提供统一 tick、目标选择、视线、受击响应和有界导航，避免原有多个定时器互相覆盖运动。
- 保留旧协议附魔 ID 0–24，修正合法等级、适用物品、互斥、附魔台/铁砧费用及战斗/工具/钓鱼效果。
- 附魔结果由服务端计算；修复铁砧交付被插件取消、同步回调关闭菜单时的物品丢失，并加入回归。
- 新增 `/locate [structure] <name> [x z]`，通过候选距离下界判断最近，搜索未完成时明确提示。
- `/tp` 支持已有世界、安全出生点及坐标，保留旧玩家互传、相对坐标与朝向写法。
- 正常关闭时取消看门狗线程；PHAR 构建包含结构资源。

## 安装和升级

需要自行配置 **64 位 PHP 8.4 ZTS、pmmpthread 6.x** 及项目扩展。`bin/` 不在源码中。

运行时配置 `genisys.yml`、`pocketmine.yml`、`server.properties` 从此不再被 Git 跟踪，首次启动会按项目默认值生成。已有部署在更新前应将自己的配置复制到工作区之外备份，更新后确认并恢复；不要用示例文件覆盖自己的配置。

地图、玩家数据、OP 名单、插件运行目录、日志和备份不会加入仓库。升级前备份这些数据。默认末地名仍为 `ender`；新建末地默认继承主世界种子，已存在世界不会自动重建。

新建筑只在新填充区块出现。旧自定义末地与新生成区块可能存在边界；重新生成末地前先备份，并在关服时操作世界目录。

## 已知限制

- 世界高度 Y0–127。超高原版末地城布局会裁剪，定位结果会提示。
- 新版方块、实体、物品和机制仅在旧客户端可表示范围内适配；村民现代 Brain、袭击、试炼机制等尚未完整移植。
- AI 使用旧引擎碰撞和运动模型；火球使用可显示的旧实体外观，深海探索者使用现有速度属性适配。
- 附魔仅覆盖已知的 25 个旧版 ID。旧客户端菜单显示和数据包操作顺序仍需要逐版本真机验证。
- 原始归档/JAR 不随仓库发布；转换资源保留源摘要和映射报告，其来源说明见建筑文档。

## 验证

常规测试不需要运行中服务器、已有存档、原始归档或 Java。先配置项目 PHP，再运行：

```bash
python3 -B tools/test_structure_resources.py
python3 -B tools/structure_import/test_import.py
PHP_BINARY=/path/to/php
for name in structures end_noise end_city end_features end_integration locate teleport mob_behavior26 navigation26 enchanting26 enchant_effects26; do
  "$PHP_BINARY" "tools/test_${name}.php" || exit
done
```

独立实服检查使用临时世界和独立端口：

```bash
python3 tools/test_structure_server.py --php "$PHP_BINARY" --port 19142
python3 tools/test_ai_enchant_server.py --php "$PHP_BINARY" --port 19152
```

重建源码 oracle 和资源需要自行提供 MCP-Reborn 26.2 归档；预览重绘另需 Pillow。详见 [建筑](../STRUCTURES.md)、[末地](END_26_2.md)、[生物 AI](AI_26_2.md)、[附魔](ENCHANTMENTS_26_2.md)、[中文使用说明](AI_ENCHANT_26_2_使用说明.md) 和 [传送命令](TELEPORT.md)。
