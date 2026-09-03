<?php

/*
 *
 *  ____            _        _   __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | | (_| |   <| |  _ <
 * |_|  \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\__,_|_|\_\_|_| \___|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author MPMPESCore
 * @link https://github.com/mpmp666/MPMPESCore
 *
*/

namespace pocketmine\mpapi;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\Player;

/**
 * MPApi — MPMPESCore 高性能 API 体系(当前版本 1.0)
 *
 * 插件用法:
 * 1. 在 plugin.yml 中声明 `mpapi: "1.0"`(可选;不声明也能正常加载,
 *    但仅保证普通 API 的兼容性;声明版本高于服务端 MPApi 版本的插件会被禁用)。
 * 2. 通过本门面类调用,如 MPApi::getFullStateAt($level, $x, $y, $z);
 *    或直接调用 Level 上的同名方法 $level->getFullStateAt(...),二者等价。
 * 3. MPApi 方法与普通 API 可以在同一插件内混用。
 *
 * MPApi 方法保证:不构造 Block 对象、不触碰 blockCache、不触发区块加载、
 * 循环内按列取 chunk,性能显著高于对应的普通 API 实现。
 *
 * @api
 */
final class MPApi{

	const VERSION = "1.0";

	private function __construct(){
	}

	/**
	 * 判断服务端 MPApi 版本是否 >= 插件要求的版本。
	 * 插件可在运行时调用以决定是否使用 MPApi 特性。
	 *
	 * @param string $version 插件要求的 MPApi 版本,如 "1.0"
	 *
	 * @return bool
	 */
	public static function hasVersion(string $version) : bool{
		return version_compare($version, self::VERSION, "<=");
	}

	/**
	 * 获取玩家进入服务器时使用的端口。
	 * 经内置 frp 隧道 + PROXY v2 还原后, 返回的是玩家的真实端口(外网端口);
	 * 直连时返回客户端连接的端口。
	 *
	 * @param Player $player
	 *
	 * @return int
	 */
	public static function getPlayerPort(Player $player) : int{
		return $player->getPort();
	}

	/**
	 * 获取玩家进入服务器时使用的 IP(经 frp PROXY v2 还原后的真实 IP)。
	 *
	 * @param Player $player
	 *
	 * @return string
	 */
	public static function getPlayerAddress(Player $player) : string{
		return $player->getAddress();
	}

	/**
	 * 获取玩家进入时的完整地址 "IP:端口"(真实地址, frp PROXY 还原后)。
	 *
	 * @param Player $player
	 *
	 * @return string
	 */
	public static function getPlayerEntryAddress(Player $player) : string{
		return $player->getAddress() . ":" . $player->getPort();
	}

	/**
	 * AABB 内所有对实体有碰撞(hasEntityCollision)的方块,按 blockHash 索引。
	 * 性能约为对 AABB 内逐点 getBlock() + 过滤的数倍。
	 *
	 * @param Level         $level
	 * @param AxisAlignedBB $bb
	 *
	 * @return Block[]
	 */
	public static function getEntityCollidingBlocks(Level $level, AxisAlignedBB $bb) : array{
		return $level->getEntityCollidingBlocks($bb);
	}

	/**
	 * 读取原始方块状态(id << 4 | meta),零分配、零 clone、不触碰缓存、不加载区块。
	 * 空气/越界/区块未加载返回 0。
	 * 对比 getBlock()->getId()+getDamage():无 Block 对象分配,快数倍。
	 *
	 * @param Level $level
	 * @param int   $x
	 * @param int   $y
	 * @param int   $z
	 *
	 * @return int 0-4095
	 */
	public static function getFullStateAt(Level $level, int $x, int $y, int $z) : int{
		return $level->getFullStateAt($x, $y, $z);
	}

	/**
	 * 快速实体/方块碰撞相关的固体判定,零分配。
	 * 等价于 getBlock(...)->isSolid(),但无 Block 对象分配。
	 * 未加载区块/越界返回 false(空气不视为固体)。
	 *
	 * @param Level $level
	 * @param int   $x
	 * @param int   $y
	 * @param int   $z
	 *
	 * @return bool
	 */
	public static function isBlockSolidAt(Level $level, int $x, int $y, int $z) : bool{
		return $level->isBlockSolidAt($x, $y, $z);
	}

	/**
	 * 原始写方块:不构造 Block 对象、不做灯光更新、不触发 BlockUpdateEvent、
	 * 不做 updateAround;方块变更进入 changedBlocks 队列,玩家下个 tick 会收到更新,
	 * 区块加载器(含玩家)会收到 onBlockChanged 通知。
	 * 语义约等于 setBlock($pos, $block, false, false) 的免对象版,适合批量程序化改方块。
	 * 需要灯光/事件连锁时请改用普通 API Level::setBlock()。
	 *
	 * @param Level $level
	 * @param int   $x
	 * @param int   $y
	 * @param int   $z
	 * @param int   $id   0-255
	 * @param int   $meta 0-15
	 *
	 * @return bool 是否成功写入
	 */
	public static function setBlockRaw(Level $level, int $x, int $y, int $z, int $id, int $meta = 0) : bool{
		return $level->setBlockRaw($x, $y, $z, $id, $meta);
	}

	/**
	 * 获取生物 AI 控制器(AIHolder)。
	 * 可用于: 刷怪(spawnZombie/spawnCow/...)、刷怪计时器控制、
	 * 调整僵尸仇恨半径(setZombieHatred_r)与追击速度(setZombieHate_v)等。
	 * 未开启 AI(aiEnabled=false)时返回 null。
	 *
	 * @return \pocketmine\entity\ai\AIHolder|null
	 */
	public static function getAI(){
		$server = \pocketmine\Server::getInstance();
		return $server !== null ? $server->getAIHolder() : null;
	}

	/**
	 * 以 $center 为中心、$radius 为半径查找实体(AABB 三轴同时扩展)。
	 * 底层走区块索引, 不会遍历全实体表。
	 *
	 * @param Level       $level
	 * @param Vector3     $center
	 * @param float       $radius
	 * @param Entity|null $except 排除的实体(通常是调用者自身)
	 *
	 * @return Entity[]
	 */
	public static function getNearbyEntities(Level $level, Vector3 $center, float $radius, Entity $except = null) : array{
		return $level->getNearbyEntities(new AxisAlignedBB(
			$center->x - $radius, $center->y - $radius, $center->z - $radius,
			$center->x + $radius, $center->y + $radius, $center->z + $radius
		), $except);
	}

	/**
	 * 获取某列最高非空气方块的 Y(基于区块 heightMap, 零扫描开销)。
	 * 未加载区块返回 -1。heightMap 偶尔可能滞后于实际地形,
	 * 需要精确值可先调用 Level::getChunk(...)->recalculateHeightMap() 校准。
	 *
	 * @param Level $level
	 * @param int   $x
	 * @param int   $z
	 *
	 * @return int
	 */
	public static function getHighestBlockAt(Level $level, int $x, int $z) : int{
		$chunk = $level->getChunk($x >> 4, $z >> 4, false);
		if($chunk === null){
			return -1;
		}

		return $chunk->getHeightMap($x & 0x0f, $z & 0x0f);
	}

	/**
	 * 批量原始填充方块(两个坐标确定的长方体, 含边界)。
	 * 语义与 setBlockRaw 相同: 无灯光更新/无事件/updateAround, 玩家下个 tick 收到变更。
	 * 注意: 未加载的区块会被自动加载生成, 大范围填充前请自行评估范围。
	 *
	 * @param Level $level
	 * @param int   $x1
	 * @param int   $y1
	 * @param int   $z1
	 * @param int   $x2
	 * @param int   $y2
	 * @param int   $z2
	 * @param int   $id   0-255
	 * @param int   $meta 0-15
	 *
	 * @return int 实际写入的方块数
	 */
	public static function fillBlocksRaw(Level $level, int $x1, int $y1, int $z1, int $x2, int $y2, int $z2, int $id, int $meta = 0) : int{
		$minX = min($x1, $x2);
		$maxX = max($x1, $x2);
		$minY = max(0, min($y1, $y2));
		$maxY = min(127, max($y1, $y2));
		$minZ = min($z1, $z2);
		$maxZ = max($z1, $z2);

		$count = 0;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					if($level->setBlockRaw($x, $y, $z, $id, $meta)){
						$count++;
					}
				}
			}
		}

		return $count;
	}

	/* ==================== 地图媒体 API(Bad Apple / 显示图片) ==================== */

	/** @var int 动态(虚拟)地图 ID 从这里开始, 不会与世界持久地图冲突 */
	private static $nextDynamicMapId = 20000;

	/** @var MapColor[][][] mapId => MapColor[y][x] 动态地图纹理缓存(供客户端请求时响应) */
	private static $dynamicColors = [];

	/**
	 * 创建一张动态(虚拟)地图: 只存在于内存与客户端, 不写入世界目录。
	 * 适合视频播放(Bad Apple)等临时纹理。配合 setMapImageFromFile / setMapColors 使用。
	 * 注意: 物品 damage 只有 16 位, 放入展示框时地图 ID 会按 damage 截断,
	 * 因此动态地图 ID 从 20000 起, 与持久地图(顺序小 ID)天然错开。
	 *
	 * @return int 动态地图 ID
	 */
	public static function createDynamicMap() : int{
		return self::$nextDynamicMapId++;
	}

	/**
	 * 把图片文件(png/jpg/gif/bmp, GD 支持)转为 128x128 地图纹理。
	 *
	 * @param string $path 图片文件路径
	 *
	 * @return MapColor[][]|null MapColor[y][x], 失败返回 null
	 */
	public static function imageToMapColors(string $path){
		if(!is_file($path)){
			return null;
		}
		$img = imagecreatefromstring(file_get_contents($path));
		if($img === false){
			return null;
		}
		$canvas = imagecreatetruecolor(128, 128);
		imagecopyresampled($canvas, $img, 0, 0, 0, 0, 128, 128, imagesx($img), imagesy($img));
		$colors = [];
		for($y = 0; $y < 128; ++$y){
			for($x = 0; $x < 128; ++$x){
				$rgb = imagecolorat($canvas, $x, $y);
				$colors[$y][$x] = new \pocketmine\utils\MapColor(($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);
			}
		}
		imagedestroy($img);
		imagedestroy($canvas);
		return $colors;
	}

	/**
	 * 把颜色数据广播为本世界所有玩家的地图纹理。
	 * 客户端会按 mapId 缓存纹理: 手持 getMapItem 的地图 / 物品展示框里的地图都会显示。
	 *
	 * @param Level          $level
	 * @param int            $mapId
	 * @param MapColor[][]   $colors MapColor[y][x]
	 *
	 * @return bool
	 */
	public static function setMapColors(Level $level, int $mapId, $colors) : bool{
		if(!is_array($colors) or count($colors) === 0){
			return false;
		}
		self::$dynamicColors[$mapId] = $colors;  //缓存, 供客户端 MapInfoRequest 时响应
		$pk = new \pocketmine\network\protocol\ClientboundMapItemDataPacket();
		$pk->mapId = $mapId;
		$pk->colors = $colors;
		foreach($level->getPlayers() as $p){
			$p->dataPacket($pk);
		}
		return true;
	}

	/**
	 * 处理客户端的地图数据请求(0.14 协议闭环): 客户端装备/查看地图时会发
	 * MapInfoRequest, 服务端按 持久地图 → 动态地图缓存 → 手持物品 damage 的顺序响应。
	 * 插件一般不需要直接调用。
	 *
	 * @param Player $player
	 * @param Level  $level
	 * @param int    $mapId 客户端请求的地图 ID
	 *
	 * @return bool 是否成功响应
	 */
	public static function serveMapRequest(Player $player, Level $level, int $mapId) : bool{
		$md = \pocketmine\maps\MapData::get($level);
		$colors = null;
		if($md->haveMap($mapId)){
			$colors = $md->getMapData($mapId);
		}elseif(isset(self::$dynamicColors[$mapId])){
			$colors = self::$dynamicColors[$mapId];
		}elseif($player->getInventory()->getItemInHand()->getId() === \pocketmine\item\Item::FILLED_MAP){
			//兜底: 请求 ID 与物品 damage 不一致时, 按手持物品 damage 提供
			$mid = $player->getInventory()->getItemInHand()->getDamage();
			if($md->haveMap($mid)){
				$mapId = $mid;
				$colors = $md->getMapData($mid);
			}elseif(isset(self::$dynamicColors[$mid])){
				$mapId = $mid;
				$colors = self::$dynamicColors[$mid];
			}
		}
		if($colors === null){
			return false;
		}
		$pk = new \pocketmine\network\protocol\ClientboundMapItemDataPacket();
		$pk->mapId = $mapId;
		$pk->colors = $colors;
		$player->dataPacket($pk);
		return true;
	}

	/**
	 * 加载图片文件并作为地图纹理广播(动态地图/持久地图均可)。
	 * 播放视频(Bad Apple): 把视频逐帧导出为图片, 用重复任务逐帧调用本方法即可。
	 * 持久地图(ID < 20000 且已有数据文件)会同时保存, 动态地图只广播不落盘。
	 *
	 * @param Level  $level
	 * @param int    $mapId
	 * @param string $path 图片文件路径
	 *
	 * @return bool
	 */
	public static function setMapImageFromFile(Level $level, int $mapId, string $path) : bool{
		$colors = self::imageToMapColors($path);
		if($colors === null){
			return false;
		}
		if(!self::setMapColors($level, $mapId, $colors)){
			return false;
		}
		if($mapId < self::$nextDynamicMapId){
			$md = \pocketmine\maps\MapData::get($level);
			if($md->haveMap($mapId)){
				$md->saveMapData($mapId, $colors);
			}
		}
		return true;
	}

	/**
	 * 得到一张绑定指定地图 ID 的填充地图物品。
	 * 放入物品展示框 / 手持均可显示该地图的当前纹理。
	 *
	 * @param int $mapId
	 *
	 * @return \pocketmine\item\Item
	 */
	public static function getMapItem(int $mapId) : \pocketmine\item\Item{
		$item = \pocketmine\item\Item::get(\pocketmine\item\Item::FILLED_MAP, 0, 1);
		$item->setDamage($mapId & 0xFFFF);
		$item->setNamedTag(new \pocketmine\nbt\tag\CompoundTag('', [
			new \pocketmine\nbt\tag\StringTag('map_uuid', (string) $mapId),
		]));
		return $item;
	}

	/* ==================== frp 隧道相关 API ==================== */

	/**
	 * 是否已启用内置 frp 隧道(存在有效的 frp.toml / frp_*.toml 且已启动)。
	 *
	 * @return bool
	 */
	public static function isFrpEnabled() : bool{
		$mgr = self::getFrpManager();
		return $mgr !== null and count($mgr->getTunnels()) > 0;
	}

	/**
	 * 获取 frp 隧道管理器(未初始化时为 null)
	 *
	 * @return \pocketmine\frp\FrpManager|null
	 */
	public static function getFrpManager(){
		$server = \pocketmine\Server::getInstance();
		return $server !== null ? $server->getFrpManager() : null;
	}

	/**
	 * 获取所有 frp 隧道状态信息:
	 * name / logFile / proxyProtocolVersion / pid(是否在运行)
	 *
	 * @return array<string, array{name:string,logFile:string,proxyProtocolVersion:string,pid:int|false}>
	 */
	public static function getFrpTunnels() : array{
		$mgr = self::getFrpManager();
		return $mgr !== null ? $mgr->getTunnels() : [];
	}

	/**
	 * 重启所有 frp 隧道(frp 自动重试/手动修复)。
	 *
	 * @return bool 是否成功触发
	 */
	public static function restartFrp() : bool{
		$mgr = self::getFrpManager();
		if($mgr === null){
			return false;
		}
		$mgr->restartAll();
		return true;
	}

	/**
	 * 停止全部 frp 隧道。
	 *
	 * @return bool
	 */
	public static function stopFrp() : bool{
		$mgr = self::getFrpManager();
		if($mgr === null){
			return false;
		}
		$mgr->shutdown();
		return true;
	}
}
