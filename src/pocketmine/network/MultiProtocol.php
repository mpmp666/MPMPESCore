<?php

/*
 * MPMPESCore cross-version protocol bridge (MCPE 0.14.x <-> 0.15.x)
 *
 * Keeps the core native to protocol 70 (0.14.x) and translates packets
 * per-player for protocol 81+ (0.15.x) clients, so 0.14 and 0.15 players
 * can play on the same world, on the same port.
 *
 * Wire differences handled here:
 *   - 0.15.0 renumbered every packet id (0x8f..0xca -> 0x01..0x41)
 *   - 0.15.0 dropped the 0x8e encapsulation marker; server wraps with 0xfe instead
 *   - UpdateBlockPacket / SetEntityMotionPacket: 0.15 removed the leading count int
 *   - MoveEntityPacket: 0.15 is single-entity with byte rotation (multi packets)
 *   - AddEntityPacket: 0.15 scales yaw/pitch by 0.71111 and appends a modifiers int
 *   - ChangeDimensionPacket: 0.15 adds x,y,z floats
 *   - RemovePlayerPacket: removed in 0.15 (use RemoveEntityPacket instead)
 */

namespace pocketmine\network;

use pocketmine\entity\Entity;
use pocketmine\inventory\FurnaceRecipe;
use pocketmine\inventory\ShapedRecipe;
use pocketmine\inventory\ShapelessRecipe;
use pocketmine\item\Item;
use pocketmine\network\protocol\AvailableCommandsPacket;
use pocketmine\network\protocol\CommandStepPacket;
use pocketmine\network\protocol\CraftingDataPacket;
use pocketmine\network\protocol\ContainerSetContentPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\FullChunkDataPacket;
use pocketmine\network\protocol\Info;
use pocketmine\network\protocol\ResourcePacksInfoPacket;
use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\utils\UUID;

class MultiProtocol{

	/** First protocol version of the 0.15.x family */
	const PROTOCOL_0_15 = 81;

	/** First/last protocol version of the 0.13.x family (0.13.0=37, 0.13.1=38, 0.13.2=39) */
	const PROTOCOL_0_13 = 37;
	const PROTOCOL_0_13_LAST = 39;

	/** MCPE 0.12.1 protocol (Prismarine-0.12 reports CURRENT_PROTOCOL = 34) */
	const PROTOCOL_0_12 = 34;

	/** First protocol version of the 0.16.x family (0.16.0/0.16.1=90, 0.16.2=91) */
	const PROTOCOL_0_16 = 90;

	/** MCPE 0.11.x protocol range (Steadfast2-011 reports 23; 0.11 builds span 21-33) */
	const PROTOCOL_0_11 = 23;
	const PROTOCOL_0_11_MIN = 21;
	const PROTOCOL_0_11_MAX = 33;

	/** 0.11 (protocol 23) packet ID => native 0.14 packet ID */
	private static $toServer11 = [
		0x82 => 0x8f, //LOGIN
		0x83 => 0x90, //PLAY_STATUS
		0x84 => 0x91, //DISCONNECT
		0x85 => 0x93, //TEXT
		0x86 => 0x94, //SET_TIME
		0x87 => 0x95, //START_GAME
		0x88 => 0x96, //ADD_PLAYER
		0x89 => 0x97, //REMOVE_PLAYER
		0x8a => 0x98, //ADD_ENTITY
		0x8b => 0x99, //REMOVE_ENTITY
		0x8c => 0x9a, //ADD_ITEM_ENTITY
		0x8d => 0x9b, //TAKE_ITEM_ENTITY
		0x8e => 0x9c, //MOVE_ENTITY
		0x8f => 0x9d, //MOVE_PLAYER
		0x90 => 0x9e, //REMOVE_BLOCK
		0x91 => 0x9f, //UPDATE_BLOCK
		0x92 => 0xa0, //ADD_PAINTING
		0x93 => 0xa1, //EXPLODE
		0x94 => 0xa2, //LEVEL_EVENT
		0x95 => 0xa3, //TILE_EVENT -> BLOCK_EVENT
		0x96 => 0xa4, //ENTITY_EVENT
		0x97 => 0xa5, //MOB_EFFECT
		0x98 => 0xa7, //MOB_EQUIPMENT
		0x99 => 0xa8, //MOB_ARMOR_EQUIPMENT
		0x9a => 0xa9, //INTERACT
		0x9b => 0xaa, //USE_ITEM
		0x9c => 0xab, //PLAYER_ACTION
		0x9d => 0xac, //HURT_ARMOR
		0x9e => 0xad, //SET_ENTITY_DATA
		0x9f => 0xae, //SET_ENTITY_MOTION
		0xa0 => 0xaf, //SET_ENTITY_LINK
		0xa1 => 0xb0, //SET_HEALTH
		0xa2 => 0xb1, //SET_SPAWN_POSITION
		0xa3 => 0xb2, //ANIMATE
		0xa4 => 0xb3, //RESPAWN
		0xa5 => 0xb4, //DROP_ITEM
		0xa6 => 0xb5, //CONTAINER_OPEN
		0xa7 => 0xb6, //CONTAINER_CLOSE
		0xa8 => 0xb7, //CONTAINER_SET_SLOT
		0xa9 => 0xb8, //CONTAINER_SET_DATA
		0xaa => 0xb9, //CONTAINER_SET_CONTENT
		0xac => 0xbc, //ADVENTURE_SETTINGS
		0xad => 0xbd, //BLOCK_ENTITY_DATA
		0xaf => 0xbf, //FULL_CHUNK_DATA
		0xb0 => 0xc0, //SET_DIFFICULTY
		0xb1 => 0x92, //BATCH
	];

	/** Native 0.14 packet ID => 0.11 protocol 23 packet ID */
	private static $toClient11 = null;

	/** 0.13 客户端不认识的 0.14 新增物品/方块 id(出现在创造背包/配方里会让 0.13 崩溃) */
	public static $oldProtocolUnknownItems = [23, 125, 154, 165, 179, 180, 182, 356, 380, 389, 395, 461, 462];

	/** 0.13 官方创造栏白名单(从 Genisys 0.13 initCreativeItems 提取, 精确匹配 0.13 客户端) */
	public static $oldProtocolCreativeItems = [1,2,3,4,5,6,7,12,13,14,15,16,17,18,19,20,21,22,24,25,27,28,30,31,32,35,37,38,39,40,41,42,44,45,46,47,48,49,50,52,53,54,56,57,58,61,65,66,67,69,70,72,73,76,77,78,79,80,81,82,85,86,87,88,89,91,96,98,99,100,101,102,103,106,107,108,109,110,111,112,113,114,116,120,121,123,126,128,129,131,133,134,135,136,139,143,145,146,147,148,151,152,153,155,156,158,159,161,162,163,164,167,170,171,172,173,174,175,183,184,185,186,187,243,245,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,318,319,320,321,322,323,324,325,328,330,331,332,333,334,337,338,339,341,345,346,347,348,349,350,351,352,353,354,355,357,359,360,361,362,363,364,365,366,367,369,370,371,372,373,374,375,376,377,378,379,382,383,384,388,390,391,392,393,394,396,397,400,406,411,412,413,414,415,427,428,429,430,431,438,458,460,463,466];

	/** 0.12 官方创造栏白名单(从 Prismarine-0.12 initCreativeItems 提取, 236 个 id) */
	public static $oldProtocolCreativeItems12 = [1,2,3,4,5,6,7,12,13,14,15,16,17,18,19,20,21,22,24,25,27,28,30,31,32,35,37,39,40,41,42,44,45,46,47,48,49,50,52,53,54,56,57,58,61,65,66,67,69,70,72,73,75,77,78,79,80,81,82,86,87,88,89,91,96,98,99,100,101,102,103,106,107,108,109,110,111,112,113,114,116,120,121,122,126,128,129,131,133,134,135,136,139,143,145,146,147,148,151,152,153,155,156,158,159,161,162,163,164,167,170,171,172,173,174,175,183,184,185,186,187,243,244,245,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,318,319,320,321,322,323,324,325,328,330,331,334,337,338,339,341,345,346,347,348,350,351,352,353,354,355,357,359,360,361,362,363,364,365,366,367,369,370,371,372,374,375,376,377,378,379,382,383,384,388,390,391,392,393,394,396,397,400,406,411,412,413,414,415,427,428,429,430,431,458];

	/**
	 * 该物品 id 是否是 0.13 客户端不认识的 0.14 新增物品
	 *
	 * @param int $itemId
	 *
	 * @return bool
	 */
	public static function isUnknownToOldProtocol($itemId){
		return in_array($itemId, self::$oldProtocolUnknownItems, true);
	}

	/**
	 * 该物品 id 是否在 0.13 官方创造栏中
	 *
	 * @param int $itemId
	 *
	 * @return bool
	 */
	public static function isOldProtocolCreativeItem($itemId){
		return in_array($itemId, self::$oldProtocolCreativeItems, true);
	}

	/**
	 * 该物品 id 是否在 0.12 官方创造栏中(0.12 认识的物品比 0.13 少, 给 0.12 客户端发
	 * 0.13/0.14 的物品会让客户端在打开创造背包/加载地形时崩溃, 所以按 0.12 自己的清单过滤）
	 *
	 * @param int $itemId
	 *
	 * @return bool
	 */
	public static function is012ProtocolCreativeItem($itemId){
		return in_array($itemId, self::$oldProtocolCreativeItems12, true);
	}

	/**
	 * 0.11 官方创造栏精确清单 (id:meta 对, 提取自 Steadfast2-011 Item::initCreativeItems)。
	 * 0.11 客户端打开创造背包时会校验每个条目, 下发它不认识的 id/meta 组合会直接闪退。
	 */
	public static $creativeItems11 = ['4:0','98:0','98:1','98:2','98:3','48:0','5:0','5:1','5:2','5:3','5:4','5:5','45:0','1:0','1:1','1:2','1:3','1:4','1:5','1:6','3:0','243:0','2:0','110:0','82:0','172:0','159:0','159:7','159:6','159:5','159:4','159:3','159:2','159:1','159:15','159:14','159:13','159:12','159:11','159:10','159:9','159:8','24:0','24:1','24:2','12:0','12:1','13:0','17:0','17:1','17:2','17:3','162:0','162:1','112:0','87:0','7:0','67:0','53:0','134:0','135:0','136:0','163:0','164:0','108:0','128:0','109:0','114:0','156:0','44:0','44:1','158:0','158:1','158:2','158:3','158:4','158:5','44:3','44:4','44:5','44:6','155:0','155:1','155:2','16:0','15:0','14:0','56:0','21:0','73:0','129:0','49:0','79:0','80:0','121:0','139:0','139:1','41:0','42:0','57:0','22:0','173:0','133:0','152:0','78:0','20:0','89:0','106:0','247:0','65:0','19:0','102:0','324:0','96:0','85:0','85:1','85:2','85:3','85:4','85:5','107:0','184:0','183:0','186:0','185:0','187:0','101:0','355:0','47:0','321:0','58:0','245:0','54:0','61:0','120:0','37:0','38:0','39:0','40:0','81:0','103:0','86:0','91:0','30:0','170:0','31:1','31:2','32:0','6:0','6:1','6:2','6:3','6:4','6:5','18:0','18:1','18:2','18:3','161:0','161:1','354:0','323:0','52:0','35:0','35:7','35:6','35:5','35:4','35:3','35:2','35:1','35:15','35:14','35:13','35:12','35:11','35:10','35:9','35:8','171:0','171:7','171:6','171:5','171:4','171:3','171:2','171:1','171:15','171:14','171:13','171:12','171:11','171:10','171:9','171:8','50:0','325:0','325:1','325:8','325:10','46:0','292:0','256:0','267:0','261:0','359:0','259:0','347:0','345:0','328:0','383:10','383:11','383:12','383:13','338:0','296:0','295:0','362:0','361:0','391:0','392:0','458:0','344:0','349:0','349:1','349:2','349:3','350:0','350:1','351:0','351:7','351:6','351:5','351:4','351:3','351:2','351:1','351:15','351:14','351:13','351:12','351:11','351:10','351:9','351:8'];

	private static $creativeSet11 = null;

	/**
	 * 0.11 创造栏过滤: 只保留 0.11 官方创造栏里精确存在的 (id, meta) 组合。
	 */
	public static function is011CreativeItem($itemId, $meta = 0){
		if(self::$creativeSet11 === null){
			self::$creativeSet11 = array_fill_keys(self::$creativeItems11, true);
		}
		return isset(self::$creativeSet11[$itemId . ":" . $meta]);
	}

	/** 0.14 (protocol 70) packet id => 0.15 (protocol 81) packet id */
	private static $toClient = [
		0x8f => 0x01, //LOGIN
		0x90 => 0x02, //PLAY_STATUS
		0x91 => 0x05, //DISCONNECT
		0x92 => 0x06, //BATCH
		0x93 => 0x07, //TEXT
		0x94 => 0x08, //SET_TIME
		0x95 => 0x09, //START_GAME
		0x96 => 0x0a, //ADD_PLAYER
		//0x97 REMOVE_PLAYER: does not exist in 0.15 (use REMOVE_ENTITY)
		0x98 => 0x0b, //ADD_ENTITY
		0x99 => 0x0c, //REMOVE_ENTITY
		0x9a => 0x0d, //ADD_ITEM_ENTITY
		0x9b => 0x0e, //TAKE_ITEM_ENTITY
		0x9c => 0x0f, //MOVE_ENTITY
		0x9d => 0x10, //MOVE_PLAYER
		0x9e => 0x12, //REMOVE_BLOCK
		0x9f => 0x13, //UPDATE_BLOCK
		0xa0 => 0x14, //ADD_PAINTING
		0xa1 => 0x15, //EXPLODE
		0xa2 => 0x16, //LEVEL_EVENT
		0xa3 => 0x17, //BLOCK_EVENT
		0xa4 => 0x18, //ENTITY_EVENT
		0xa5 => 0x19, //MOB_EFFECT
		0xa6 => 0x1a, //UPDATE_ATTRIBUTES
		0xa7 => 0x1b, //MOB_EQUIPMENT
		0xa8 => 0x1c, //MOB_ARMOR_EQUIPMENT
		0xa9 => 0x1e, //INTERACT
		0xaa => 0x1f, //USE_ITEM
		0xab => 0x20, //PLAYER_ACTION
		0xac => 0x21, //HURT_ARMOR
		0xad => 0x22, //SET_ENTITY_DATA
		0xae => 0x23, //SET_ENTITY_MOTION
		0xaf => 0x24, //SET_ENTITY_LINK
		0xb0 => 0x25, //SET_HEALTH
		0xb1 => 0x26, //SET_SPAWN_POSITION
		0xb2 => 0x27, //ANIMATE
		0xb3 => 0x28, //RESPAWN
		0xb4 => 0x29, //DROP_ITEM
		0xb5 => 0x2a, //CONTAINER_OPEN
		0xb6 => 0x2b, //CONTAINER_CLOSE
		0xb7 => 0x2c, //CONTAINER_SET_SLOT
		0xb8 => 0x2d, //CONTAINER_SET_DATA
		0xb9 => 0x2e, //CONTAINER_SET_CONTENT
		0xba => 0x2f, //CRAFTING_DATA
		0xbb => 0x30, //CRAFTING_EVENT
		0xbc => 0x31, //ADVENTURE_SETTINGS
		0xbd => 0x32, //BLOCK_ENTITY_DATA
		0xbe => 0x33, //PLAYER_INPUT
		0xbf => 0x34, //FULL_CHUNK_DATA
		0xc0 => 0x35, //SET_DIFFICULTY
		0xc1 => 0x36, //CHANGE_DIMENSION
		0xc2 => 0x37, //SET_PLAYER_GAMETYPE
		0xc3 => 0x38, //PLAYER_LIST
		0xc6 => 0x3b, //CLIENTBOUND_MAP_ITEM_DATA
		0xc7 => 0x3c, //MAP_INFO_REQUEST
		0xc8 => 0x3d, //REQUEST_CHUNK_RADIUS
		0xc9 => 0x3e, //CHUNK_RADIUS_UPDATED
		0xca => 0x3f, //ITEM_FRAME_DROP_ITEM
	];

	/** 0.15 packet id => 0.14 packet id (inverse of $toClient) */
	private static $toServer = null;

	/** 0.14 (protocol 70) packet id => 0.16 (protocol 90/91) packet id */
	private static $toClient16 = [
		0x8f => 0x01, //LOGIN
		0x90 => 0x02, //PLAY_STATUS
		0x91 => 0x05, //DISCONNECT
		0x92 => 0x06, //BATCH
		0x93 => 0x0a, //TEXT
		0x94 => 0x0b, //SET_TIME
		0x95 => 0x0c, //START_GAME
		0x96 => 0x0d, //ADD_PLAYER
		//0x97 REMOVE_PLAYER: does not exist in 0.16 (use REMOVE_ENTITY)
		0x98 => 0x0e, //ADD_ENTITY
		0x99 => 0x0f, //REMOVE_ENTITY
		0x9a => 0x10, //ADD_ITEM_ENTITY
		0x9b => 0x12, //TAKE_ITEM_ENTITY
		0x9c => 0x13, //MOVE_ENTITY
		0x9d => 0x14, //MOVE_PLAYER
		0x9e => 0x16, //REMOVE_BLOCK
		0x9f => 0x17, //UPDATE_BLOCK
		0xa0 => 0x18, //ADD_PAINTING
		0xa1 => 0x19, //EXPLODE
		0xa2 => 0x1b, //LEVEL_EVENT
		0xa3 => 0x1c, //BLOCK_EVENT
		0xa4 => 0x1d, //ENTITY_EVENT
		0xa5 => 0x1e, //MOB_EFFECT
		0xa6 => 0x1f, //UPDATE_ATTRIBUTES
		0xa7 => 0x20, //MOB_EQUIPMENT
		0xa8 => 0x21, //MOB_ARMOR_EQUIPMENT
		0xa9 => 0x22, //INTERACT
		0xaa => 0x23, //USE_ITEM
		0xab => 0x24, //PLAYER_ACTION
		0xac => 0x25, //HURT_ARMOR
		0xad => 0x26, //SET_ENTITY_DATA
		0xae => 0x27, //SET_ENTITY_MOTION
		0xaf => 0x28, //SET_ENTITY_LINK
		0xb0 => 0x29, //SET_HEALTH
		0xb1 => 0x2a, //SET_SPAWN_POSITION
		0xb2 => 0x2b, //ANIMATE
		0xb3 => 0x2c, //RESPAWN
		0xb4 => 0x2d, //DROP_ITEM
		0xb5 => 0x2f, //CONTAINER_OPEN
		0xb6 => 0x30, //CONTAINER_CLOSE
		0xb7 => 0x31, //CONTAINER_SET_SLOT
		0xb8 => 0x32, //CONTAINER_SET_DATA
		0xb9 => 0x33, //CONTAINER_SET_CONTENT
		0xba => 0x34, //CRAFTING_DATA
		0xbb => 0x35, //CRAFTING_EVENT
		0xbc => 0x36, //ADVENTURE_SETTINGS
		0xbd => 0x37, //BLOCK_ENTITY_DATA
		0xbe => 0x38, //PLAYER_INPUT
		0xbf => 0x39, //FULL_CHUNK_DATA
		0xc0 => 0x3b, //SET_DIFFICULTY
		0xc1 => 0x3c, //CHANGE_DIMENSION
		0xc2 => 0x3d, //SET_PLAYER_GAMETYPE
		0xc3 => 0x3e, //PLAYER_LIST
		0xc8 => 0x43, //REQUEST_CHUNK_RADIUS
		0xc9 => 0x44, //CHUNK_RADIUS_UPDATED
		0xca => 0x45, //ITEM_FRAME_DROP_ITEM
		//0xc6/0xc7 map packets: intentionally unmapped for 0.16 (dropped)
	];

	/** 0.16 packet id => 0.14 packet id (inverse of $toClient16) */
	private static $toServer16 = null;

	private static function init(){
		if(self::$toServer === null){
			self::$toServer = array_flip(self::$toClient);
			self::$toServer16 = array_flip(self::$toClient16);
		}
	}

	/**
	 * Wire family of a client protocol:
	 *   0 = 0.14.x (native: 0x8e encapsulation, current field layouts)
	 *   1 = 0.15.x (0xfe encapsulation, renumbered ids, adjusted fields)
	 *   2 = 0.13.x (no encapsulation marker, three packets lack the 0.14 tail fields)
	 *   3 = 0.16.x (0xfe encapsulation, own id space, varint/LFloat primitives)
	 *   4 = 0.12.x (no encapsulation marker, 0.13 tail trims + four 0.12 quirks)
	 *
	 * @param int|null $protocol
	 *
	 * @return int
	 */
	public static function wireFamily($protocol){
		if($protocol === null){
			return 0;
		}
		if(self::is011Protocol($protocol)){
			return 5;
		}
		if($protocol >= self::PROTOCOL_0_16){
			return 3;
		}
		if($protocol >= self::PROTOCOL_0_15){
			return 1;
		}
		if($protocol >= self::PROTOCOL_0_13 and $protocol <= self::PROTOCOL_0_13_LAST){
			return 2;
		}
		if($protocol === self::PROTOCOL_0_12){
			return 4;
		}
		return 0;
	}

	/**
	 * Whether the given client protocol belongs to the 0.15.x family (new wire format)
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function isNewProtocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_15 and $protocol < self::PROTOCOL_0_16;
	}

	/**
	 * Whether the given client protocol belongs to the 0.16.x family
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function is016Protocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_16;
	}

	/**
	 * Whether the given client protocol uses a JWT-chain login (0.15.x and 0.16.x)
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function isJwtProtocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_15;
	}

	/**
	 * Whether the given client protocol belongs to the 0.13.x family
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function isOldProtocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_13 and $protocol <= self::PROTOCOL_0_13_LAST;
	}

	/**
	 * Whether the given client protocol belongs to the 0.12.x family (protocol 34)
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function is012Protocol($protocol){
		return $protocol !== null and $protocol === self::PROTOCOL_0_12;
	}

	/** Whether the given client is MCPE 0.11.x (protocol 20-33). */
	public static function is011Protocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_11_MIN and $protocol <= self::PROTOCOL_0_11_MAX;
	}

	/** Native 0.14 packet ID to 0.11 protocol-23 packet ID. */
	public static function toClientPid11($pid){
		if(self::$toClient11 === null){
			self::$toClient11 = array_flip(self::$toServer11);
		}
		return isset(self::$toClient11[$pid]) ? self::$toClient11[$pid] : null;
	}

	/** 0.11 protocol-23 packet ID to native 0.14 packet ID. */
	public static function toServerPid11($pid){
		return isset(self::$toServer11[$pid]) ? self::$toServer11[$pid] : null;
	}

	/**
	 * Maps a 0.14-native packet id to its 0.15 counterpart, or null if the
	 * packet does not exist in 0.15 (caller should drop it for 0.15 clients).
	 *
	 * @param int $pid
	 *
	 * @return int|null
	 */
	public static function toClientPid($pid){
		return isset(self::$toClient[$pid]) ? self::$toClient[$pid] : null;
	}

	/**
	 * Maps a 0.15 packet id back to the 0.14-native id, or null if unknown.
	 *
	 * @param int $pid
	 *
	 * @return int|null
	 */
	public static function toServerPid($pid){
		self::init();
		return isset(self::$toServer[$pid]) ? self::$toServer[$pid] : null;
	}

	/**
	 * Translates an encoded packet for the given wire family (see wireFamily()).
	 *
	 * @param int              $family
	 * @param DataPacket|string $packet
	 *
	 * @return string[]
	 */
	public static function translateForFamily($family, $packet){
		if($family === 1){
			return self::translateOutgoing($packet);
		}
		if($family === 2){
			return self::translateOutgoingOld($packet);
		}
		if($family === 3){
			return self::translateOutgoing16($packet);
		}
		if($family === 4){
			return self::translateOutgoing12($packet);
		}
		if($family === 5){
			return self::translateOutgoing11($packet);
		}
		//family 0 (native 0.14.x): 0.16-only virtual packets must never reach it
		if($packet instanceof DataPacket){
			if($packet::NETWORK_ID >= 0xec){
				return [];
			}
			if(!$packet->isEncoded){
				$packet->encode();
			}
			return [$packet->buffer];
		}
		if(strlen($packet) > 0 and ord($packet[0]) >= 0xec){
			return [];
		}
		return [$packet];
	}

	/**
	 * RakNet encapsulation marker for a wire family:
	 * 0.14.x = 0x8e, 0.15.x/0.16.x = 0xfe, 0.13.x/0.12.x = no marker at all.
	 *
	 * @param int $family
	 *
	 * @return string
	 */
	public static function encapPrefixForFamily($family){
		if($family === 1 or $family === 3){
			return "\xfe";
		}
		return ($family === 2 or $family === 4 or $family === 5) ? "" : "\x8e";
	}

	/**
	 * Per-family encapsulation cache property on a pre-encoded packet object.
	 *
	 * @param int $family
	 *
	 * @return string
	 */
	public static function cachePropForFamily($family){
		if($family === 1){
			return "__encapsulatedPacket81";
		}
		if($family === 3){
			return "__encapsulatedPacket16";
		}
		if($family === 4){
			return "__encapsulatedPacket12";
		}
		if($family === 5){
			return "__encapsulatedPacket11";
		}
		return $family === 2 ? "__encapsulatedPacket13" : "__encapsulatedPacket";
	}

	/**
	 * Translates an encoded 0.14-native packet buffer for a 0.15.x client.
	 * Returns an array of complete packet payloads ([pid][fields]) — usually one,
	 * but packets like MoveEntityPacket split into several in 0.15.
	 * An empty array means the packet must not be sent to this client.
	 *
	 * @param DataPacket|string $packet encoded DataPacket or raw encoded buffer
	 *
	 * @return string[]
	 */
	public static function translateOutgoing($packet){
		if($packet instanceof DataPacket){
			if($packet::NETWORK_ID >= 0xec){
				return []; //0.16-only virtual packet
			}
			if(!$packet->isEncoded){
				$packet->encode();
			}
			$buf = $packet->buffer;
			$pid = $packet::NETWORK_ID;
			if(strlen($buf) > 0 and ord($buf[0]) !== $pid){
				return [$buf]; //already translated (e.g. cached 0.15 chunk batch)
			}
		}else{
			$buf = $packet;
			$pid = strlen($buf) > 0 ? ord($buf[0]) : -1;
			if($pid >= 0xec){
				return []; //0.16-only virtual packet
			}
		}

		switch($pid){
			case Info::UPDATE_BLOCK_PACKET:
				//0.14: [pid][int count][records...] -> 0.15: [pid][records...]
				return [chr(0x13) . substr($buf, 5)];

			case Info::SET_ENTITY_MOTION_PACKET:
				//0.14: [pid][int count][records...] -> 0.15: [pid][records...]
				return [chr(0x23) . substr($buf, 5)];

			case Info::MOVE_ENTITY_PACKET:
				//0.14: [pid][count][eid long, xyz 3f, yaw f, headYaw f, pitch f] x N
				//0.15: one packet per entity: [pid][eid long][xyz 3f][pitch b][yaw b][headYaw b]
				$out = [];
				$count = Binary::readInt(substr($buf, 1, 4));
				$off = 5;
				for($i = 0; $i < $count; $i++){
					if($off + 32 > strlen($buf)){
						break;
					}
					$eid = substr($buf, $off, 8);
					$xyz = substr($buf, $off + 8, 12);
					$yaw = Binary::readFloat(substr($buf, $off + 20, 4));
					$headYaw = Binary::readFloat(substr($buf, $off + 24, 4));
					$pitch = Binary::readFloat(substr($buf, $off + 28, 4));
					$off += 32;
					$out[] = chr(0x0f) . $eid . $xyz .
						chr(((int) ($pitch / (360 / 256))) & 0xff) .
						chr(((int) ($yaw / (360 / 256))) & 0xff) .
						chr(((int) ($headYaw / (360 / 256))) & 0xff);
				}
				return $out;

			case Info::ADD_ENTITY_PACKET:
				if($packet instanceof DataPacket){
					//0.15: same fields but yaw/pitch scaled by 0.71111 + trailing modifiers int
					$out = chr(0x0b);
					$out .= Binary::writeLong($packet->eid) . Binary::writeInt($packet->type);
					$out .= Binary::writeFloat($packet->x) . Binary::writeFloat($packet->y) . Binary::writeFloat($packet->z);
					$out .= Binary::writeFloat($packet->speedX) . Binary::writeFloat($packet->speedY) . Binary::writeFloat($packet->speedZ);
					$out .= Binary::writeFloat($packet->yaw * 0.71111) . Binary::writeFloat($packet->pitch * 0.71111);
					$out .= Binary::writeInt(0); //modifiers
					$out .= self::writeMetadata15($packet->metadata);
					$out .= Binary::writeShort(count($packet->links));
					foreach($packet->links as $link){
						$out .= Binary::writeLong($link[0]) . Binary::writeLong($link[1]) . chr($link[2]);
					}
					return [$out];
				}
				//raw buffer fallback: cannot rescale without parsing metadata, remap id only
				return [chr(0x0b) . substr($buf, 1)];

			case Info::SET_ENTITY_DATA_PACKET:
				//0.15: [pid][eid long][metadata] — inject lead metadata, otherwise
				//0.15 clients default DATA_LEAD_HOLDER to entity 0 and render every
				//mob as leashed (all mobs show a rope to something).
				$meta = substr($buf, 9);
				if(substr($meta, -1) === "\x7f"){
					$meta = substr($meta, 0, -1) . "\xf7" . "\xff\xff\xff\xff\xff\xff\xff\xff" . "\x18\x00" . "\x7f";
				}
				return [chr(0x22) . substr($buf, 1, 8) . $meta];

			case Info::REMOVE_PLAYER_PACKET:
				//0.15 removed RemovePlayerPacket; player despawn uses RemoveEntityPacket (eid long only)
				return [chr(0x0c) . substr($buf, 1, 8)];

			case Info::CHANGE_DIMENSION_PACKET:
				//0.14: [pid][dim][0] -> 0.15: [pid][dim][x f][y f][z f][0]
				//position is filled by the teleport that follows anyway
				return [chr(0x36) . $buf[1] . pack("f", 0.0) . pack("f", 0.0) . pack("f", 0.0) . "\x00"];

			default:
				$mapped = self::toClientPid($pid);
				if($mapped === null){
					return []; //no 0.15 counterpart: drop silently
				}
				return [chr($mapped) . substr($buf, 1)];
		}
	}

	/**
	 * Translates an encoded 0.14-native packet buffer for a 0.13.x client.
	 *
	 * 0.13.0-0.13.2 share the 0.14 packet id space and the 0.14 field layouts
	 * except three packets that gained tail fields in 0.14:
	 *   StartGamePacket       0.14: ...[1][1][0][string]  -> 0.13: ...[0]   (47 bytes)
	 *   ContainerOpenPacket   0.14: ...[entityId long]     -> 0.13: 无该字段 (17 bytes)
	 *   AdventureSettings     0.14: [flags][userPerm][globPerm] -> 0.13: [flags] (5 bytes)
	 * 0.13 clients also use no encapsulation marker at all (see RakLibInterface).
	 *
	 * @param DataPacket|string $packet encoded DataPacket or raw encoded buffer
	 *
	 * @return string[]
	 */
	public static function translateOutgoingOld($packet){
		if($packet instanceof DataPacket){
			if($packet::NETWORK_ID >= 0xec){
				return []; //0.16-only virtual packet
			}
			if(!$packet->isEncoded){
				$packet->encode();
			}
			$buf = $packet->buffer;
			if(strlen($buf) > 0 and ord($buf[0]) !== $packet::NETWORK_ID){
				return [$buf]; //already translated for another family
			}
		}else{
			$buf = $packet;
			if(strlen($buf) > 0 and ord($buf[0]) >= 0xec){
				return []; //0.16-only virtual packet
			}
		}

		$pid = strlen($buf) > 0 ? ord($buf[0]) : -1;
		switch($pid){
			case Info::START_GAME_PACKET:
				//pid(1)+45 field bytes, then 0.14 has [1][1][0][string], 0.13 has [0]:
				//keep the 46 header bytes and append the shared trailing 0 byte
				return [strlen($buf) >= 47 ? substr($buf, 0, 46) . "\x00" : $buf];

			case Info::CONTAINER_OPEN_PACKET:
				//pid(1)+windowid(1)+type(1)+slots(2)+x/y/z(12) = 17; the 0.14 entityId long is dropped
				return [strlen($buf) >= 17 ? substr($buf, 0, 17) : $buf];

			case Info::ADVENTURE_SETTINGS_PACKET:
				//pid(1)+flags(4); the 0.14 userPermission/globalPermission ints are dropped
				return [strlen($buf) >= 5 ? substr($buf, 0, 5) : $buf];

			default:
				return [$buf]; //identical layout, identical id
		}
	}

	/**
	 * Translates an encoded 0.14-native packet buffer for a 0.12.x client
	 * (protocol 34). 0.12 shares the 0.14 packet id space and, per a full
	 * encode/decode diff against Genisys 0.13, differs from 0.13 only in four
	 * places (StartGame/ContainerOpen/AdventureSettings need the same tail trims
	 * as 0.13, since 0.12 and 0.13 are byte-identical for those three):
	 *   ContainerSetSlotPacket  0.14: ...[slot][hotbarSlot short][item] -> 0.12: [slot][item]
	 *   ChangeDimensionPacket   0.14: [dim byte][0]  -> 0.12: [int dimId]
	 *   PlayerListPacket ADD    0.14: [..][skinName string][skin string] -> 0.12: [slim byte][skin string]
	 *   CraftingDataPacket      shaped recipe ingredient slots are written in the
	 *                           (z<height, x<width) order instead of 0.14's (z<width, x<height)
	 *
	 * @param DataPacket|string $packet encoded DataPacket or raw encoded buffer
	 *
	 * @return string[]
	 */
	public static function translateOutgoing12($packet){
		if($packet instanceof DataPacket){
			if($packet::NETWORK_ID >= 0xec){
				return []; //0.16-only virtual packet
			}
			if(!$packet->isEncoded){
				$packet->encode();
			}
			$buf = $packet->buffer;
			if(strlen($buf) > 0 and ord($buf[0]) !== $packet::NETWORK_ID){
				return [$buf]; //already translated for another family
			}
		}else{
			$buf = $packet;
			if(strlen($buf) > 0 and ord($buf[0]) >= 0xec){
				return []; //0.16-only virtual packet
			}
		}

		$pid = strlen($buf) > 0 ? ord($buf[0]) : -1;
		switch($pid){
			case Info::START_GAME_PACKET:
				//same tail trim as 0.13: keep the 46 header bytes + shared trailing 0
				return [strlen($buf) >= 47 ? substr($buf, 0, 46) . "\x00" : $buf];

			case Info::CONTAINER_OPEN_PACKET:
				//same as 0.13: drop the 0.14 entityId long (17 bytes total)
				return [strlen($buf) >= 17 ? substr($buf, 0, 17) : $buf];

			case Info::ADVENTURE_SETTINGS_PACKET:
				//same as 0.13: drop userPermission/globalPermission (5 bytes total)
				return [strlen($buf) >= 5 ? substr($buf, 0, 5) : $buf];

			case Info::CONTAINER_SET_SLOT_PACKET:
				//[pid][windowid][slot short][hotbarSlot short][item] -> drop the hotbarSlot short
				if(strlen($buf) < 6){
					return [$buf];
				}
				return [substr($buf, 0, 4) . substr($buf, 6)];

			case Info::CHANGE_DIMENSION_PACKET:
				//[pid][dim byte][0] -> [pid][int dimId]
				if(strlen($buf) < 2){
					return [$buf];
				}
				return [chr(0xc1) . Binary::writeInt(ord($buf[1]))];

			case Info::PLAYER_LIST_PACKET:
				return [self::playerList12($buf)];

			case Info::CRAFTING_DATA_PACKET:
				if($packet instanceof CraftingDataPacket){
					return [self::crafting12($packet)];
				}
				return [$buf]; //cannot reorder slots without the recipe objects

			default:
				return [$buf]; //identical layout, identical id
		}
	}

	/**
	 * Re-encodes a 0.14 PlayerListPacket for 0.12: TYPE_ADD entries carry a
	 * 1-byte slim flag instead of the 0.14 skinName string.
	 *
	 * @param string $buf native encoded PlayerListPacket
	 *
	 * @return string
	 */
	private static function playerList12($buf){
		$off = 1;
		$type = ord($buf[$off++]);
		$count = Binary::readInt(substr($buf, $off, 4)); $off += 4;
		$out = chr(0xc3) . chr($type) . Binary::writeInt($count);
		for($i = 0; $i < $count and $off < strlen($buf); $i++){
			if($type === 0){ //TYPE_ADD
				$uuid = substr($buf, $off, 16); $off += 16;
				$eid = substr($buf, $off, 8); $off += 8;
				//name (LShort length + bytes)
				$nl = Binary::readLShort(substr($buf, $off, 2)); $off += 2;
				$name = substr($buf, $off, $nl); $off += $nl;
				//skinName string -> slim byte (skin names ending in "Slim" are slim skins)
				$sl = Binary::readLShort(substr($buf, $off, 2)); $off += 2;
				$skinName = substr($buf, $off, $sl); $off += $sl;
				$slim = stripos($skinName, "slim") !== false ? 1 : 0;
				//skin string (kept as-is)
				$kl = Binary::readLShort(substr($buf, $off, 2)); $off += 2;
				$skin = substr($buf, $off, $kl); $off += $kl;
				$out .= $uuid . $eid . Binary::writeLShort(strlen($name)) . $name .
					chr($slim) . Binary::writeLShort(strlen($skin)) . $skin;
			}else{
				$out .= substr($buf, $off, 16); $off += 16; //uuid only
			}
		}
		return $out;
	}

	/**
	 * Re-encodes a CraftingDataPacket for 0.12. The envelope is 0.14-style;
	 * only the shaped-recipe ingredient slot order changes:
	 *   0.14/0.13: for(z < width) for(x < height)
	 *   0.12:      for(z < height) for(x < width)
	 *
	 * @param CraftingDataPacket $packet
	 *
	 * @return string
	 */
	private static function crafting12(CraftingDataPacket $packet){
		$out = chr(0xba) . Binary::writeInt(0); //placeholder, replaced below
		$body = "";
		$count = 0;
		foreach($packet->entries as $d){
			$entry = "";
			if($d instanceof ShapelessRecipe){
				$entry .= Binary::writeInt($d->getIngredientCount());
				foreach($d->getIngredientList() as $item){
					$entry .= self::slot14($item);
				}
				$entry .= Binary::writeInt(1);
				$entry .= self::slot14($d->getResult());
				$entry .= $d->getId()->toBinary();
				$type = 0;
			}elseif($d instanceof ShapedRecipe){
				$w = $d->getWidth();
				$h = $d->getHeight();
				$entry .= Binary::writeInt($w);
				$entry .= Binary::writeInt($h);
				for($z = 0; $z < $h; ++$z){
					for($x = 0; $x < $w; ++$x){
						$entry .= self::slot14($d->getIngredient($x, $z));
					}
				}
				$entry .= Binary::writeInt(1);
				$entry .= self::slot14($d->getResult());
				$entry .= $d->getId()->toBinary();
				$type = 1;
			}elseif($d instanceof FurnaceRecipe){
				if($d->getInput()->getDamage() !== 0){
					$entry .= Binary::writeInt(($d->getInput()->getId() << 16) | $d->getInput()->getDamage());
					$entry .= self::slot14($d->getResult());
					$type = 3;
				}else{
					$entry .= Binary::writeInt($d->getInput()->getId());
					$entry .= self::slot14($d->getResult());
					$type = 2;
				}
			}else{
				continue; //unsupported entry: skip
			}
			$body .= Binary::writeInt($type) . Binary::writeInt(strlen($entry)) . $entry;
			$count++;
		}
		return chr(0xba) . Binary::writeInt($count) . $body . chr($packet->cleanRecipes ? 1 : 0);
	}

	/**
	 * 0.12 的 ContainerSetSlotPacket 字段布局是 [windowid][slot][item],
	 * 没有 0.14/0.13 插在 slot 与 item 之间的 hotbarSlot(short)。
	 * 调用前需把 packet 的 offset 重新定位到 pid 之后。
	 *
	 * @param DataPacket $pk
	 */
	public static function decode012ContainerSetSlot($pk){
		$pk->windowid = $pk->getByte();
		$pk->slot = $pk->getShort();
		$pk->hotbarSlot = 0; //0.12 没有该字段
		$pk->item = $pk->getSlot();
	}

	/**
	 * 0.14-style item slot: short id, byte count, short damage, LShort nbt length, nbt.
	 *
	 * @param Item $item
	 *
	 * @return string
	 */
	private static function slot14(Item $item){
		if($item->getId() === 0){
			return Binary::writeShort(0) . "\x00" . Binary::writeShort(0) . Binary::writeLShort(0);
		}
		$nbt = $item->getCompoundTag();
		return Binary::writeShort($item->getId()) . chr($item->getCount()) .
			Binary::writeShort($item->getDamage()) . Binary::writeLShort(strlen($nbt)) . $nbt;
	}

	/** Encode a legacy protocol-23 item slot (id, count, damage; no NBT). */
	private static function slot11($item){
		if($item instanceof Item){
			$id = $item->getId();
			$count = $item->getCount();
			$damage = $item->getDamage();
		}else{
			$id = 0; $count = 0; $damage = 0;
		}
		return Binary::writeShort($id) . chr($count & 0xff) . Binary::writeShort($damage);
	}

	/**
	 * Block IDs known to the MCPE 0.11 client. Any block not in this set is
	 * replaced with air (0) in the chunk data sent to 0.11 clients to prevent
	 * invisible/crashing blocks.
	 */
	private static $blocks11 = null;
	private static function getBlock11Whitelist(){
		if(self::$blocks11 === null){
			self::$blocks11 = array_fill_keys([
				0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,24,26,
				27,28,30,31,32,35,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,
				52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,
				73,74,75,76,77,78,79,80,81,82,83,85,86,87,88,89,90,91,92,93,94,
				95,96,98,101,102,103,104,105,106,107,108,109,110,111,112,113,114,
				116,120,121,122,123,124,125,126,127,128,129,131,133,134,135,136,
				139,141,142,143,145,146,147,148,151,152,153,155,156,157,158,159,
				163,164,167,170,171,172,173,175,183,184,185,186,187,198,243,244,
				245,246,247
			], true);
		}
		return self::$blocks11;
	}

	/** Convert a native 0.14 FullChunkData packet to the protocol-23 format. */
	private static function chunk11FromNative($buf){
		if(strlen($buf) < 14){
			return "";
		}
		$x = Binary::readInt(substr($buf, 1, 4));
		$z = Binary::readInt(substr($buf, 5, 4));
		$order = ord($buf[9]);
		$length = Binary::readInt(substr($buf, 10, 4));
		$data = substr($buf, 14, max(0, $length));
		$ids = $data;
		$meta = ""; $sky = ""; $light = ""; $biomes = ""; $colors = ""; $tiles = "";
		if($order === FullChunkDataPacket::ORDER_LAYERED and strlen($data) >= 83200){
			$ids0 = substr($data, 0, 32768);
			$meta0 = substr($data, 32768, 16384);
			$sky0 = substr($data, 49152, 16384);
			$light0 = substr($data, 65536, 16384);
			$heightOffset = 81920;
			$heightmap0 = substr($data, $heightOffset, 256);
			$colors0 = substr($data, $heightOffset + 256, 1024);
			// The 256-byte field before biome colors is the height map (0-127) on
			// the 0.11.x release clients (protocol 27). Sending biome ids there makes
			// the client believe the ground is only ~20 blocks high, so players spawn
			// in mid-air and keep falling.
			$biomes = $heightmap0;
			// Protocol 27 (0.11.x release) consumes the chunk payload in the same
			// y-major (layered) order the core already produces, so pass the arrays
			// through unchanged (only filtering block ids 0.11 does not know).
			$whitelist = self::getBlock11Whitelist();
			$filtered = "";
			for($i = 0; $i < 32768; ++$i){
				$bid = ord($ids0[$i]);
				$filtered .= isset($whitelist[$bid]) ? $ids0[$i] : "\x00";
			}
			$ids = $filtered; $meta = $meta0; $sky = $sky0; $light = $light0; $colors = $colors0;
			$extraOffset = $heightOffset + 256 + 1024;
			if(strlen($data) >= $extraOffset + 4){
				$count = Binary::readLInt(substr($data, $extraOffset, 4));
				$extraOffset += 4 + max(0, $count) * 6;
				$tiles = $extraOffset <= strlen($data) ? substr($data, $extraOffset) : "";
			}
		}elseif(strlen($data) >= 83200){
			// ORDER_COLUMNS: blocks already in column order (x,z,y) from McRegion/LevelDB.
			$ids0 = substr($data, 0, 32768);
			$meta = substr($data, 32768, 16384);
			$sky = substr($data, 49152, 16384);
			$light = substr($data, 65536, 16384);
			$colors = substr($data, 81920 + 256, 1024);
			$biomes = substr($data, 81920, 256); // height map (0.11.x release layout)
			// Filter unknown block IDs
			$whitelist = self::getBlock11Whitelist();
			$filtered = "";
			for($i = 0; $i < 32768; ++$i){
				$bid = ord($ids0[$i]);
				$filtered .= isset($whitelist[$bid]) ? $ids0[$i] : "\x00";
			}
			$ids = $filtered;
			$extraOffset = 81920 + 256 + 1024;
			if(strlen($data) >= $extraOffset + 4){
				$count = Binary::readLInt(substr($data, $extraOffset, 4));
				$extraOffset += 4 + max(0, $count) * 6;
				$tiles = $extraOffset <= strlen($data) ? substr($data, $extraOffset) : "";
			}
		}else{
			$colors = str_repeat("\x00", 1024);
			$biomes = str_repeat("\x00", 256);
		}
		$body = $ids . $meta . $sky . $light . $biomes . $colors . $tiles;
		if(self::$chunk11Raw){
			// Protocol 27 (0.11.x release) variant: the chunk payload is sent
			// uncompressed with no inner x/z prefix; the batch layer compresses it.
			$inner = $body;
			$compressed = $body;
		}else{
			$inner = Binary::writeLInt($x) . Binary::writeLInt($z) . $body;
			$compressed = @zlib_encode($inner, ZLIB_ENCODING_DEFLATE, Server::getInstance()->networkCompressionLevel);
			if($compressed === false){
				$compressed = @zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 1);
			}
		}
		return chr(0xaf) . Binary::writeInt($x) . Binary::writeInt($z) . Binary::writeInt(strlen($compressed)) . $compressed;
	}

	/** true = send protocol-27 chunk bodies raw (no zlib / no x-z prefix). */
	private static $chunk11Raw = true;

	/** Translate a native packet to the MCPE 0.11/protocol-23 wire layout. */
	public static function translateOutgoing11($packet){
		if($packet instanceof DataPacket){
			if(!$packet->isEncoded){
				$packet->encode();
			}
			$buf = $packet->buffer;
			$pid = $packet::NETWORK_ID;
		}else{
			$buf = $packet;
			$pid = strlen($buf) > 0 ? ord($buf[0]) : -1;
		}
		if($pid === Info::FULL_CHUNK_DATA_PACKET){
			$chunk = self::chunk11FromNative($buf);
			return $chunk === "" ? [] : [$chunk];
		}
		$mapped = self::toClientPid11($pid);
		if($mapped === null){
			return [];
		}
		if(!$packet instanceof DataPacket){
			return [chr($mapped) . substr($buf, 1)];
		}
		switch($pid){
			case Info::BATCH_PACKET:
				$length = strlen($buf) >= 5 ? Binary::readInt(substr($buf, 1, 4)) : 0;
				return [chr(0xb1) . Binary::writeInt($length) . substr($buf, 5, max(0, $length))];
			case Info::TEXT_PACKET:
				//0.11 has no TYPE_SYSTEM(5); POPUP carries message only (no source).
				$type = $packet->type;
				if($type === 5){ $type = 0; }
				$out = chr(0x85) . chr($type);
				if($type === 1){ //CHAT: source + message
					$out .= Binary::writeShort(strlen($packet->source)) . $packet->source;
					$out .= Binary::writeShort(strlen($packet->message)) . $packet->message;
				}elseif($type === 2){ //TRANSLATION: message + count + params
					$out .= Binary::writeShort(strlen($packet->message)) . $packet->message;
					$out .= chr(count($packet->parameters));
					foreach($packet->parameters as $p){
						$out .= Binary::writeShort(strlen($p)) . $p;
					}
				}else{ //RAW/POPUP/TIP: message only
					$out .= Binary::writeShort(strlen($packet->message)) . $packet->message;
				}
				return [$out];
			case Info::START_GAME_PACKET:
				return [chr(0x87) . Binary::writeInt($packet->seed) . Binary::writeInt($packet->generator) . Binary::writeInt($packet->gamemode)
					. Binary::writeLong($packet->eid) . Binary::writeInt($packet->spawnX) . Binary::writeInt($packet->spawnY) . Binary::writeInt($packet->spawnZ)
					. Binary::writeFloat($packet->x) . Binary::writeFloat($packet->y) . Binary::writeFloat($packet->z)];
			case Info::ADD_PLAYER_PACKET:
				$clientId = $packet->clientID ?? $packet->eid;
				$item = $packet->item instanceof Item ? $packet->item : Item::get(0, 0, 0);
				return [chr(0x88) . Binary::writeLong($clientId) . Binary::writeLShort(strlen($packet->username)) . $packet->username
					. Binary::writeLong($packet->eid) . Binary::writeFloat($packet->x) . Binary::writeFloat($packet->y) . Binary::writeFloat($packet->z)
					. Binary::writeFloat($packet->speedX) . Binary::writeFloat($packet->speedY) . Binary::writeFloat($packet->speedZ)
					. Binary::writeFloat($packet->yaw) . Binary::writeFloat($packet->yaw) . Binary::writeFloat($packet->pitch)
					. Binary::writeShort($item->getId()) . Binary::writeShort($item->getDamage()) . chr($packet->slim ? 1 : 0)
					. Binary::writeLShort(strlen($packet->skin)) . $packet->skin . Binary::writeMetadata($packet->metadata)];
			case Info::REMOVE_PLAYER_PACKET:
				return [chr(0x89) . Binary::writeLong($packet->eid) . Binary::writeLong($packet->eid)];
			case Info::ADD_ITEM_ENTITY_PACKET:
				//0.11 slot is 5 bytes (id, count, damage) with no NBT length/data.
				$item = $packet->item instanceof Item ? $packet->item : Item::get(0, 0, 0);
				return [chr(0x8c) . Binary::writeLong($packet->eid) . self::slot11($item)
					. Binary::writeFloat($packet->x) . Binary::writeFloat($packet->y) . Binary::writeFloat($packet->z)
					. Binary::writeFloat($packet->speedX) . Binary::writeFloat($packet->speedY) . Binary::writeFloat($packet->speedZ)];
			case Info::SET_TIME_PACKET:
				return [chr(0x86) . Binary::writeInt((int) (($packet->time / \pocketmine\level\Level::TIME_FULL) * 19200)) . ($packet->started ? "\x80" : "\x00")];
			case Info::CONTAINER_OPEN_PACKET:
				return [chr(0xa6) . substr($buf, 1, 16)];
			case Info::CONTAINER_SET_SLOT_PACKET:
				$item = $packet->item instanceof Item ? $packet->item : Item::get(0, 0, 0);
				return [chr(0xa8) . chr($packet->windowid) . Binary::writeShort($packet->slot) . self::slot11($item)];
			case Info::CONTAINER_SET_CONTENT_PACKET:
				$out = chr(0xaa) . chr($packet->windowid) . Binary::writeShort(count($packet->slots));
				foreach($packet->slots as $item){ $out .= self::slot11($item); }
				if($packet->windowid === ContainerSetContentPacket::SPECIAL_INVENTORY){
					$out .= Binary::writeShort(count($packet->hotbar));
					foreach($packet->hotbar as $slot){ $out .= Binary::writeInt($slot); }
				}
				return [$out];
			case Info::MOB_EQUIPMENT_PACKET:
				$item = $packet->item instanceof Item ? $packet->item : Item::get(0, 0, 0);
				return [chr(0x98) . Binary::writeLong($packet->eid) . Binary::writeShort($item->getId()) . Binary::writeShort($item->getDamage()) . chr($packet->slot) . chr($packet->selectedSlot)];
			case Info::MOB_ARMOR_EQUIPMENT_PACKET:
				$out = chr(0x99) . Binary::writeLong($packet->eid);
				foreach($packet->slots as $item){ $out .= chr(($item instanceof Item ? $item->getId() : (int) $item) & 0xff); }
				return [$out];
			case Info::SET_SPAWN_POSITION_PACKET:
				return [chr(0xa2) . Binary::writeInt($packet->x) . Binary::writeInt($packet->z) . chr($packet->y & 0xff)];
			case Info::ADVENTURE_SETTINGS_PACKET:
				return [chr(0xac) . Binary::writeInt($packet->flags)];
			case Info::BLOCK_ENTITY_DATA_PACKET:
				return [chr(0xad) . Binary::writeInt($packet->x) . chr($packet->y & 0xff) . Binary::writeInt($packet->z) . $packet->namedtag];
			case Info::PLAYER_LIST_PACKET:
				return [];
			default:
				return [chr($mapped) . substr($buf, 1)];
		}
	}

	// --------------------------------------------------------------------
	//  MCPE 0.16.x (protocol 90/91) — full re-encode, field layouts changed
	//  everywhere: varint/zigzag ints, little-endian floats, varint-length
	//  strings, new slot format, dictionary-style entity metadata.
	// --------------------------------------------------------------------

	/** varint-length string (0.16 string primitive) */
	private static function str16($s){
		return Binary::writeUnsignedVarInt(strlen($s)) . $s;
	}

	/** zigzag varint entity id (0.16 EntityRuntimeID primitive) */
	private static function eid16($eid){
		return Binary::writeVarInt($eid);
	}

	/** 0.16 BlockCoords: varint x, byte y, varint z */
	private static function blockCoords16($x, $y, $z){
		return Binary::writeVarInt($x) . chr($y & 0xff) . Binary::writeVarInt($z);
	}

	/** 0.16 Vector3f: three little-endian floats */
	private static function vec3f16($x, $y, $z){
		return Binary::writeLFloat($x) . Binary::writeLFloat($y) . Binary::writeLFloat($z);
	}

	/** 0.16 slot: varint id, varint (damage<<8|count), LShort nbt-len, nbt */
	private static function slot16(Item $item){
		if($item->getId() === 0){
			return Binary::writeVarInt(0);
		}
		$nbt = $item->getCompoundTag();
		return Binary::writeVarInt($item->getId())
			. Binary::writeVarInt((($item->getDamage() ?? -1) << 8) | $item->getCount())
			. Binary::writeLShort(strlen($nbt)) . $nbt;
	}

	/** Reads a 0.16 slot from $b at $off (advanced). */
	private static function readSlot16($b, &$off){
		$id = Binary::readVarInt($b, $off);
		if($id <= 0){
			return Item::get(0, 0, 0);
		}
		$aux = Binary::readVarInt($b, $off);
		$damage = $aux >> 8;
		$count = $aux & 0xff;
		$nbtLen = strlen($b) - $off >= 2 ? Binary::readLShort(substr($b, $off, 2)) : 0;
		$off += 2;
		$nbt = "";
		if($nbtLen > 0){
			$nbt = substr($b, $off, $nbtLen);
			$off += strlen($nbt);
		}
		return Item::get($id, $damage, $count, $nbt);
	}

	/**
	 * 0.16 moved every attribute into the "minecraft:" namespace and renamed a
	 * few (generic.movementSpeed -> minecraft:movement). Names taken from
	 * Genisys 0.16 Attribute::init().
	 *
	 * @param string $name native (0.14) attribute name
	 *
	 * @return string
	 */
	private static function attributeName16($name){
		static $map = [
			"generic.absorption" => "minecraft:absorption",
			"player.saturation" => "minecraft:player.saturation",
			"player.exhaustion" => "minecraft:player.exhaustion",
			"generic.knockbackResistance" => "minecraft:knockback_resistance",
			"generic.health" => "minecraft:health",
			"generic.movementSpeed" => "minecraft:movement",
			"generic.followRange" => "minecraft:follow_range",
			"player.hunger" => "minecraft:player.hunger",
			"generic.attackDamage" => "minecraft:attack_damage",
			"player.level" => "minecraft:player.level",
			"player.experience" => "minecraft:player.experience",
		];
		if(isset($map[$name])){
			return $map[$name];
		}
		return strpos($name, ":") === false ? "minecraft:" . $name : $name;
	}

	/**
	 * 0.15 metadata writer: native 0.14 wire format plus the lead entries
	 * 0.15 clients need. Without DATA_LEAD_HOLDER(23)=-1 / DATA_LEAD(24)=0,
	 * 0.15 renders every mob as leashed.
	 *
	 * @param array $data native metadata array
	 *
	 * @return string
	 */
	private static function writeMetadata15(array $data){
		$meta = Binary::writeMetadata($data); //ends with 0x7f
		//chr((7<<5)|23) . LLong(-1) . chr((0<<5)|24) . chr(0)
		return substr($meta, 0, -1) . "\xf7" . "\xff\xff\xff\xff\xff\xff\xff\xff" . "\x18\x00" . "\x7f";
	}

	/**
	 * 0.16 metadata writer: dictionary format (uvarint count, per entry uvarint
	 * key + uvarint type + typed value). Translates 0.14 keys to 0.16 keys and
	 * folds the 0.14 scalar keys NO_AI/SILENT/SHOW_NAMETAG into the 0.16 flags
	 * long. Always injects DATA_LEAD_HOLDER_EID(38) = -1 so mobs never render
	 * as leashed.
	 *
	 * @param array $data native metadata array
	 *
	 * @return string
	 */
	private static function writeMetadata16(array $data){
		$flags = isset($data[0]) ? (int) $data[0][1] : 0; //bits 0..13 are identical
		if(isset($data[15]) and $data[15][1]){ //DATA_NO_AI -> IMMOBILE
			$flags |= 1 << 16;
		}
		if(isset($data[4]) and $data[4][1]){ //DATA_SILENT -> SILENT
			$flags |= 1 << 17;
		}
		if(isset($data[3]) and $data[3][1]){ //DATA_SHOW_NAMETAG -> CAN_SHOW_NAMETAG
			$flags |= 1 << 14;
		}

		//0.14 key => 0.16 key (only entries the 0.16 client understands)
		static $keyMap = [
			0 => [0, 7],   //DATA_FLAGS -> long
			1 => [7, 1],   //DATA_AIR -> key 7, short
			2 => [4, 4],   //DATA_NAMETAG -> key 4, string
			7 => [8, 2],   //DATA_POTION_COLOR -> key 8, int
			8 => [9, 0],   //DATA_POTION_AMBIENT -> key 9, byte
		];

		$out = "";
		$count = 0;
		$write = function($key, $type, $value) use (&$out, &$count){
			$entry = Binary::writeUnsignedVarInt($key) . Binary::writeUnsignedVarInt($type);
			switch($type){
				case 0: //byte
					$entry .= chr($value & 0xff);
					break;
				case 1: //short
					$entry .= Binary::writeLShort($value);
					break;
				case 2: //int
					$entry .= Binary::writeVarInt($value);
					break;
				case 3: //float
					$entry .= Binary::writeLFloat($value);
					break;
				case 4: //string
					$entry .= self::str16($value);
					break;
				case 5: //slot [id, count, damage]
					$entry .= self::slot16(Item::get($value[0], $value[2], $value[1]));
					break;
				case 6: //pos
					$entry .= self::blockCoords16($value[0], $value[1], $value[2]);
					break;
				case 7: case 8: //long (0.14 long=8, 0.16 long=7)
					$entry = Binary::writeUnsignedVarInt($key) . Binary::writeUnsignedVarInt(7) . Binary::writeVarInt($value);
					break;
				default:
					return;
			}
			$out .= $entry;
			$count++;
		};

		foreach($data as $key => $d){
			if($key === 0){
				$write(0, 7, $flags); //flags as long, with folded bits
				continue;
			}
			if($key === 1){
				//0.16 氧气条: 参考端给玩家的 metadata 只有 nametag, 完全不发
				//DATA_AIR/DATA_MAX_AIR; 我们发(即使 400/400)客户端就会显示气泡条。
				//所以这里直接跳过, 与参考端保持一致。
				continue;
			}
			if($key === 3 or $key === 4 or $key === 15){
				continue; //folded into flags above
			}
			if(!isset($keyMap[$key])){
				continue; //unknown on 0.16: skip
			}
			$write($keyMap[$key][0], $keyMap[$key][1], $d[1]);
		}
		//Real-device report: without an explicit holder the 0.16 client defaults
		//DATA_LEAD_HOLDER_EID to another entity and draws a leash between players
		//("会拴着别人"). Injecting -1 (no holder) removes it, same as 0.15.
		$write(38, 7, -1); //DATA_LEAD_HOLDER_EID = -1: never leashed

		return Binary::writeUnsignedVarInt($count) . $out;
	}

	/**
	 * Translates an encoded packet for a 0.16.x client. Field layouts changed so
	 * much in 0.16 that packets are re-encoded from the DataPacket object's
	 * properties. Returns an array of complete packet payloads ([pid][fields]);
	 * empty array = do not send to this client.
	 *
	 * TODO(0.16 剩余已知问题; 多数问题已通过"参考服务端 Genisys 0.16 逐字节对比
	 *  + 严格封包校验器"修复并验证):
	 *  已修并验证: 创造移速(属性名需 minecraft: 命名空间)/拴绳(注入 LEAD_HOLDER
	 *    -1)/氧气条(补 DATA_MAX_AIR=44 并把 air 换算到 400 容量)/暂停菜单重复
	 *    (0.16 客户端自加, 服务端不再给它发自己的条目)/登录各包逐字节校验全过
	 *  1. [暂不修] 斜杠指令在真机被客户端本地校验拦下回显用法 —— 服务器链路
	 *     已验证正常(模拟器发 CommandStep 后客户端能收到 SetPlayerGameType),
	 *     指令表下发时序已对齐参考端(StartGame 之后); 待真机抓 0x4b/0x4c 对比
	 *  2. [待复测] 同一账号先 0.14 进服再 0.16 进不来 —— 模拟器复现不出,
	 *     需要真机复现时的服务端日志定位
	 *
	 * @param DataPacket|string $packet
	 *
	 * @return string[]
	 */
	public static function translateOutgoing16($packet){
		if(!$packet instanceof DataPacket){
			//raw buffer: only chunk batches / trivial packets arrive this way
			$buf = $packet;
			$pid = strlen($buf) > 0 ? ord($buf[0]) : -1;
			if($pid === Info::FULL_CHUNK_DATA_PACKET){
				return [self::chunk16FromNative($buf)];
			}
			if($pid === Info::BATCH_PACKET){
				return [self::batch16FromNative($buf)];
			}
			if($pid === Info::PLAY_STATUS_PACKET){
				return [chr(0x02) . substr($buf, 1, 4)];
			}
			if($pid === Info::CONTAINER_CLOSE_PACKET){
				return [chr(0x30) . substr($buf, 1, 1)];
			}
			return []; //cannot translate an unknown raw buffer for 0.16
		}

		$pid = $packet::NETWORK_ID;
		if($packet->isEncoded and strlen($packet->buffer) > 0 and ord($packet->buffer[0]) !== $pid){
			return [$packet->buffer]; //already translated for 0.16 (e.g. cached chunk batch)
		}
		switch($pid){
			case ResourcePacksInfoPacket::NETWORK_ID:
				//bool mustAccept=false, 0 behaviour packs, 0 resource packs
				return ["\x07\x00\x00\x00\x00\x00"];

			case AvailableCommandsPacket::NETWORK_ID:
				return [chr(0x4b) . self::str16($packet->commands) . self::str16($packet->unknown)];

			case Info::BATCH_PACKET:
				if(!$packet->isEncoded){
					$packet->encode();
				}
				return [self::batch16FromNative($packet->buffer)];

			case Info::PLAY_STATUS_PACKET:
				return [chr(0x02) . Binary::writeInt($packet->status)];

			case Info::DISCONNECT_PACKET:
				return [chr(0x05) . "\x00" . self::str16($packet->message)]; //hideDisconnectionScreen=false

			case Info::TEXT_PACKET:
				$out = chr(0x0a) . chr($packet->type);
				switch($packet->type){
					case 3: //TYPE_POPUP
					case 1: //TYPE_CHAT
						$out .= self::str16($packet->source);
						//fall through: popup/chat carry source AND message
					case 0: //TYPE_RAW
					case 4: //TYPE_TIP
					case 5: //TYPE_SYSTEM
						$out .= self::str16($packet->message);
						break;
					case 2: //TYPE_TRANSLATION
						$out .= self::str16($packet->message);
						$out .= Binary::writeUnsignedVarInt(count($packet->parameters));
						foreach($packet->parameters as $p){
							$out .= self::str16($p);
						}
				}
				return [$out];

			case Info::SET_TIME_PACKET:
				return [chr(0x0b) . Binary::writeVarInt($packet->time) . ($packet->started ? "\x01" : "\x00")];

			case Info::START_GAME_PACKET:
				$server = Server::getInstance();
				$out = chr(0x0c);
				$out .= self::eid16(0) . self::eid16(0); //entityUniqueId + entityRuntimeId
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= Binary::writeLFloat(0) . Binary::writeLFloat(0); //yaw/pitch unknowns
				$out .= Binary::writeVarInt($packet->seed);
				$out .= Binary::writeVarInt($packet->dimension);
				$out .= Binary::writeVarInt($packet->generator);
				$out .= Binary::writeVarInt($packet->gamemode);
				$out .= Binary::writeVarInt($server->getDifficulty());
				$out .= self::blockCoords16($packet->spawnX, $packet->spawnY, $packet->spawnZ);
				$out .= "\x01"; //hasAchievementsDisabled
				$out .= Binary::writeVarInt(-1); //dayCycleStopTime: not stopped
				$out .= "\x00"; //eduMode
				$out .= Binary::writeLFloat(0) . Binary::writeLFloat(0); //rain/lightning level
				$out .= "\x01"; //commandsEnabled
				$out .= "\x00"; //isTexturePacksRequired
				$out .= self::str16(""); //unknown
				$out .= self::str16($server->getMotd()); //worldName
				return [$out];

			case Info::ADD_PLAYER_PACKET:
				$out = chr(0x0d);
				$out .= $packet->uuid->toBinary();
				$out .= self::str16($packet->username);
				$out .= self::eid16($packet->eid) . self::eid16($packet->eid);
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= self::vec3f16($packet->speedX, $packet->speedY, $packet->speedZ);
				$out .= Binary::writeLFloat($packet->pitch);
				$out .= Binary::writeLFloat($packet->yaw); //headYaw
				$out .= Binary::writeLFloat($packet->yaw);
				$out .= self::slot16($packet->item);
				$out .= self::writeMetadata16($packet->metadata);
				return [$out];

			case Info::REMOVE_PLAYER_PACKET:
				//0.16 removed RemovePlayerPacket; player despawn uses RemoveEntityPacket
				return [chr(0x0f) . self::eid16($packet->eid)];

			case Info::ADD_ENTITY_PACKET:
				$out = chr(0x0e);
				$out .= self::eid16($packet->eid) . self::eid16($packet->eid);
				$out .= Binary::writeUnsignedVarInt($packet->type);
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= self::vec3f16($packet->speedX, $packet->speedY, $packet->speedZ);
				$out .= Binary::writeLFloat($packet->yaw * (256 / 360)) . Binary::writeLFloat($packet->pitch * (256 / 360));
				$out .= Binary::writeUnsignedVarInt(0); //modifiers/attributes
				$out .= self::writeMetadata16($packet->metadata);
				$out .= Binary::writeUnsignedVarInt(count($packet->links));
				foreach($packet->links as $link){
					$out .= self::eid16($link[0]) . self::eid16($link[1]) . chr($link[2]);
				}
				return [$out];

			case Info::REMOVE_ENTITY_PACKET:
				return [chr(0x0f) . self::eid16($packet->eid)];

			case Info::ADD_ITEM_ENTITY_PACKET:
				$out = chr(0x10);
				$out .= self::eid16($packet->eid) . self::eid16($packet->eid);
				$out .= self::slot16($packet->item);
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= self::vec3f16($packet->speedX, $packet->speedY, $packet->speedZ);
				return [$out];

			case Info::TAKE_ITEM_ENTITY_PACKET:
				return [chr(0x12) . self::eid16($packet->target) . self::eid16($packet->eid)];

			case Info::MOVE_ENTITY_PACKET:
				//0.16: one packet per entity
				$out = [];
				foreach($packet->entities as $d){
					$out[] = chr(0x13) . self::eid16($d[0])
						. self::vec3f16($d[1], $d[2], $d[3])
						. chr(((int) ($d[6] / (360 / 256))) & 0xff) //pitch
						. chr(((int) ($d[4] / (360 / 256))) & 0xff) //yaw
						. chr(((int) ($d[5] / (360 / 256))) & 0xff); //headYaw
				}
				return $out;

			case Info::MOVE_PLAYER_PACKET:
				//native prop order is yaw/bodyYaw/pitch; 0.16 writes pitch/yaw/bodyYaw
				$out = chr(0x14);
				$out .= self::eid16($packet->eid);
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= Binary::writeLFloat($packet->pitch);
				$out .= Binary::writeLFloat($packet->yaw);
				$out .= Binary::writeLFloat($packet->bodyYaw);
				$out .= chr($packet->mode);
				$out .= $packet->onGround ? "\x01" : "\x00";
				return [$out];

			case Info::UPDATE_BLOCK_PACKET:
				//0.16: one packet per block, no leading count
				$out = [];
				foreach($packet->records as $r){ //[x, z, y, blockId, blockData, flags]
					$out[] = chr(0x17) . self::blockCoords16($r[0], $r[2], $r[1])
						. Binary::writeUnsignedVarInt($r[3])
						. Binary::writeUnsignedVarInt(($r[5] << 4) | $r[4]);
				}
				return $out;

			case Info::ADD_PAINTING_PACKET:
				$out = chr(0x18);
				$out .= self::eid16($packet->eid) . self::eid16($packet->eid);
				$out .= self::blockCoords16($packet->x, $packet->y, $packet->z);
				$out .= Binary::writeVarInt($packet->direction);
				$out .= self::str16($packet->title);
				return [$out];

			case Info::EXPLODE_PACKET:
				$out = chr(0x19);
				$out .= self::vec3f16($packet->x, $packet->y, $packet->z);
				$out .= Binary::writeLFloat($packet->radius);
				$out .= Binary::writeUnsignedVarInt(count($packet->records));
				foreach($packet->records as $record){ //relative signed offsets, same as 0.16
					$out .= self::blockCoords16($record->x, $record->y, $record->z);
				}
				return [$out];

			case Info::LEVEL_EVENT_PACKET:
				return [chr(0x1b) . Binary::writeVarInt($packet->evid)
					. self::vec3f16($packet->x, $packet->y, $packet->z)
					. Binary::writeVarInt($packet->data)];

			case Info::BLOCK_EVENT_PACKET:
				return [chr(0x1c) . self::blockCoords16($packet->x, $packet->y, $packet->z)
					. Binary::writeVarInt($packet->case1) . Binary::writeVarInt($packet->case2)];

			case Info::ENTITY_EVENT_PACKET:
				return [chr(0x1d) . self::eid16($packet->eid) . chr($packet->event) . Binary::writeVarInt(0)];

			case Info::MOB_EFFECT_PACKET:
				return [chr(0x1e) . self::eid16($packet->eid) . chr($packet->eventId)
					. Binary::writeVarInt($packet->effectId) . Binary::writeVarInt($packet->amplifier)
					. ($packet->particles ? "\x01" : "\x00") . Binary::writeVarInt($packet->duration)];

			case Info::UPDATE_ATTRIBUTES_PACKET:
				$out = chr(0x1f) . self::eid16($packet->entityId);
				$out .= Binary::writeUnsignedVarInt(count($packet->entries));
				foreach($packet->entries as $entry){
					$out .= Binary::writeLFloat($entry->getMinValue());
					$out .= Binary::writeLFloat($entry->getMaxValue());
					$out .= Binary::writeLFloat($entry->getValue());
					$out .= Binary::writeLFloat($entry->getDefaultValue());
					//0.16 renamed every attribute into the minecraft: namespace;
					//unknown names are silently ignored by the client, which made
					//it fall back to its built-in walk speed (players ran too fast)
					$out .= self::str16(self::attributeName16($entry->getName()));
				}
				return [$out];

			case Info::MOB_EQUIPMENT_PACKET:
				return [chr(0x20) . self::eid16($packet->eid) . self::slot16($packet->item)
					. chr($packet->slot) . chr($packet->selectedSlot) . "\x00"];

			case Info::MOB_ARMOR_EQUIPMENT_PACKET:
				$out = chr(0x21) . self::eid16($packet->eid);
				for($i = 0; $i < 4; $i++){
					$out .= self::slot16($packet->slots[$i]);
				}
				return [$out];

			case Info::INTERACT_PACKET:
				return [chr(0x22) . chr($packet->action) . self::eid16($packet->target)];

			case Info::HURT_ARMOR_PACKET:
				return [chr(0x25) . Binary::writeVarInt($packet->health)];

			case Info::SET_ENTITY_DATA_PACKET:
				return [chr(0x26) . self::eid16($packet->eid) . self::writeMetadata16($packet->metadata)];

			case Info::SET_ENTITY_MOTION_PACKET:
				//0.16: one packet per entity
				$out = [];
				foreach($packet->entities as $d){
					$out[] = chr(0x27) . self::eid16($d[0]) . self::vec3f16($d[1], $d[2], $d[3]);
				}
				return $out;

			case Info::SET_ENTITY_LINK_PACKET:
				return [chr(0x28) . self::eid16($packet->from) . self::eid16($packet->to) . chr($packet->type)];

			case Info::SET_HEALTH_PACKET:
				return [chr(0x29) . Binary::writeVarInt($packet->health)];

			case Info::SET_SPAWN_POSITION_PACKET:
				return [chr(0x2a) . Binary::writeVarInt(0)
					. self::blockCoords16($packet->x, $packet->y, $packet->z) . "\x00"];

			case Info::ANIMATE_PACKET:
				return [chr(0x2b) . Binary::writeVarInt($packet->action) . self::eid16($packet->eid)];

			case Info::RESPAWN_PACKET:
				return [chr(0x2c) . self::vec3f16($packet->x, $packet->y, $packet->z)];

			case Info::CONTAINER_OPEN_PACKET:
				return [chr(0x2f) . chr($packet->windowid) . chr($packet->type)
					. Binary::writeVarInt($packet->slots)
					. self::blockCoords16($packet->x, $packet->y, $packet->z)
					. self::eid16($packet->entityId)];

			case Info::CONTAINER_CLOSE_PACKET:
				return [chr(0x30) . chr($packet->windowid)];

			case Info::CONTAINER_SET_SLOT_PACKET:
				return [chr(0x31) . chr($packet->windowid)
					. Binary::writeVarInt($packet->slot) . Binary::writeVarInt($packet->hotbarSlot)
					. self::slot16($packet->item)];

			case Info::CONTAINER_SET_DATA_PACKET:
				return [chr(0x32) . chr($packet->windowid)
					. Binary::writeVarInt($packet->property) . Binary::writeVarInt($packet->value)];

			case Info::CONTAINER_SET_CONTENT_PACKET:
				$out = chr(0x33) . chr($packet->windowid);
				$out .= Binary::writeUnsignedVarInt(count($packet->slots));
				foreach($packet->slots as $slot){
					$out .= self::slot16($slot);
				}
				if($packet->windowid === 0 and count($packet->hotbar) > 0){ //SPECIAL_INVENTORY
					$out .= Binary::writeUnsignedVarInt(count($packet->hotbar));
					foreach($packet->hotbar as $slot){
						$out .= Binary::writeVarInt($slot);
					}
				}else{
					$out .= Binary::writeUnsignedVarInt(0);
				}
				return [$out];

			case Info::CRAFTING_DATA_PACKET:
				return [self::crafting16($packet)];

			case Info::ADVENTURE_SETTINGS_PACKET:
				//ability bit positions shifted in 0.16:
				//0.14: autoJump=0x40 allowFlight=0x80 noClip=0x100
				//0.16: autoJump=0x20 allowFlight=0x40 noClip=0x80 isFlying=0x200
				//(sending 0.14 bits verbatim made 0.16 clients read allowFlight as
				//noClip: creative players got stuck flying, unable to walk)
				$f = $packet->flags;
				$f16 = $f & 0x0f; //worldImmutable/noPvp/noPvm/noMvp are identical
				if($f & 0x40){ $f16 |= 0x20; }
				if($f & 0x80){ $f16 |= 0x40; }
				if($f & 0x100){ $f16 |= 0x80; }
				return [chr(0x36) . Binary::writeUnsignedVarInt($f16)
					. Binary::writeUnsignedVarInt($packet->userPermission)];

			case Info::BLOCK_ENTITY_DATA_PACKET:
				return [chr(0x37) . self::blockCoords16($packet->x, $packet->y, $packet->z)
					. $packet->namedtag];

			case Info::FULL_CHUNK_DATA_PACKET:
				if(!$packet->isEncoded){
					$packet->encode();
				}
				return [self::chunk16FromNative($packet->buffer)];

			case Info::SET_DIFFICULTY_PACKET:
				return [chr(0x3b) . Binary::writeUnsignedVarInt($packet->difficulty)];

			case Info::CHANGE_DIMENSION_PACKET:
				return [chr(0x3c) . Binary::writeVarInt($packet->dimension)
					. self::vec3f16(0, 0, 0) . "\x00"];

			case Info::SET_PLAYER_GAMETYPE_PACKET:
				return [chr(0x3d) . Binary::writeUnsignedVarInt($packet->gamemode)];

			case Info::PLAYER_LIST_PACKET:
				$out = chr(0x3e) . chr($packet->type);
				$out .= Binary::writeUnsignedVarInt(count($packet->entries));
				foreach($packet->entries as $d){
					if($packet->type === 0){ //TYPE_ADD
						$out .= $d[0]->toBinary();
						$out .= self::eid16($d[1]);
						$out .= self::str16($d[2]); //name
						$out .= self::str16($d[3]); //skinId
						$out .= self::str16($d[4]); //skin
					}else{
						$out .= $d[0]->toBinary();
					}
				}
				return [$out];

			case Info::CHUNK_RADIUS_UPDATE_PACKET:
				return [chr(0x44) . Binary::writeVarInt($packet->radius)];

			default:
				return []; //no 0.16 counterpart (maps etc.): drop silently
		}
	}

	/**
	 * Re-encodes the native CraftingDataPacket entries with 0.16 primitives.
	 * 0.16 entries are self-delimiting (no per-entry length) and the header
	 * count is an unsigned varint.
	 *
	 * @param CraftingDataPacket $packet
	 *
	 * @return string
	 */
	private static function crafting16(CraftingDataPacket $packet){
		$body = "";
		$count = 0;
		foreach($packet->entries as $entry){
			if($entry instanceof ShapelessRecipe){
				$body .= Binary::writeVarInt(0); //ENTRY_SHAPELESS
				$body .= Binary::writeUnsignedVarInt($entry->getIngredientCount());
				foreach($entry->getIngredientList() as $item){
					$body .= self::slot16($item);
				}
				$body .= Binary::writeUnsignedVarInt(1);
				$body .= self::slot16($entry->getResult());
				$body .= $entry->getId()->toBinary();
				$count++;
			}elseif($entry instanceof ShapedRecipe){
				$body .= Binary::writeVarInt(1); //ENTRY_SHAPED
				$body .= Binary::writeVarInt($entry->getWidth());
				$body .= Binary::writeVarInt($entry->getHeight());
				for($z = 0; $z < $entry->getHeight(); ++$z){
					for($x = 0; $x < $entry->getWidth(); ++$x){
						$body .= self::slot16($entry->getIngredient($x, $z));
					}
				}
				$body .= Binary::writeUnsignedVarInt(1);
				$body .= self::slot16($entry->getResult());
				$body .= $entry->getId()->toBinary();
				$count++;
			}elseif($entry instanceof FurnaceRecipe){
				if($entry->getInput()->getDamage() !== 0){
					$body .= Binary::writeVarInt(3); //ENTRY_FURNACE_DATA
					$body .= Binary::writeVarInt($entry->getInput()->getId());
					$body .= Binary::writeVarInt($entry->getInput()->getDamage());
				}else{
					$body .= Binary::writeVarInt(2); //ENTRY_FURNACE
					$body .= Binary::writeVarInt($entry->getInput()->getId());
				}
				$body .= self::slot16($entry->getResult());
				$count++;
			}
			//EnchantmentList etc.: not supported by the 0.16 client list, skip
		}
		return chr(0x34) . Binary::writeUnsignedVarInt($count) . $body
			. ($packet->cleanRecipes ? "\x01" : "\x00");
	}

	/**
	 * 0.16 FullChunkDataPacket from a native buffer:
	 * native [0xbf][int x][int z][byte order][int len][data]
	 * 0.16   [0x39][varint x][varint z][byte order][varint len][data]
	 * (chunk payload itself is unchanged)
	 *
	 * @param string $buf
	 *
	 * @return string
	 */
	private static function chunk16FromNative($buf){
		$x = Binary::readInt(substr($buf, 1, 4));
		$z = Binary::readInt(substr($buf, 5, 4));
		$order = $buf[9];
		$dlen = Binary::readInt(substr($buf, 10, 4));
		$data = substr($buf, 14, $dlen);
		return chr(0x39) . Binary::writeVarInt($x) . Binary::writeVarInt($z) . $order
			. Binary::writeUnsignedVarInt(strlen($data)) . $data;
	}

	/**
	 * 0.16 BatchPacket wrapper from a native buffer:
	 * native [0x92][int len][zlib] -> 0.16 [0x06][varint len][zlib]
	 * (inner packet framing is handled in Server::batchPackets)
	 *
	 * @param string $buf
	 *
	 * @return string
	 */
	private static function batch16FromNative($buf){
		$len = Binary::readInt(substr($buf, 1, 4));
		return chr(0x06) . Binary::writeUnsignedVarInt($len) . substr($buf, 5, $len);
	}

	/**
	 * Parses an inbound 0.16 packet ([pid][payload], 0xfe already stripped) into
	 * the equivalent native packet object with its properties filled, ready for
	 * Player::handleDataPacket() WITHOUT calling decode() again.
	 * Returns null when the packet should be ignored.
	 *
	 * @param string $buffer
	 *
	 * @return DataPacket|null
	 */
	public static function decodeIncoming16($buffer){
		if(strlen($buffer) < 1){
			return null;
		}
		$pid = ord($buffer[0]);
		$b = substr($buffer, 1);
		$off = 0;
		$l = strlen($b);

		switch($pid){
			case 0x06: //BATCH
				$len = Binary::readUnsignedVarInt($b, $off);
				$pk = new \pocketmine\network\protocol\BatchPacket();
				$pk->payload = substr($b, $off, $len);
				return $pk;

			case 0x09: //RESOURCE_PACK_CLIENT_RESPONSE: no packs offered, nothing to do
				return null;

			case 0x0a: //TEXT
				$pk = new \pocketmine\network\protocol\TextPacket();
				$pk->type = ord($b[$off++]);
				switch($pk->type){
					case 3: //TYPE_POPUP
					case 1: //TYPE_CHAT
						$pk->source = self::readStr16($b, $off);
						//fall through
					case 0: //TYPE_RAW
					case 4: //TYPE_TIP
					case 5: //TYPE_SYSTEM
						$pk->message = self::readStr16($b, $off);
						break;
					case 2: //TYPE_TRANSLATION
						$pk->message = self::readStr16($b, $off);
						$count = Binary::readUnsignedVarInt($b, $off);
						for($i = 0; $i < $count and $i < 64; $i++){
							$pk->parameters[] = self::readStr16($b, $off);
						}
				}
				return $pk;

			case 0x14: //MOVE_PLAYER: eid, pos LFloat x3, pitch/yaw/bodyYaw LFloat, mode byte, onGround bool
				$pk = new \pocketmine\network\protocol\MovePlayerPacket();
				$pk->eid = Binary::readVarInt($b, $off);
				$pk->x = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->y = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->z = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->pitch = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->yaw = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->bodyYaw = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->mode = ord($b[$off++]);
				$pk->onGround = ($off < $l and ord($b[$off]) > 0);
				return $pk;

			case 0x16: //REMOVE_BLOCK: blockcoords
				$pk = new \pocketmine\network\protocol\RemoveBlockPacket();
				$pk->eid = 0;
				$pk->x = Binary::readVarInt($b, $off);
				$pk->y = ord($b[$off++]);
				$pk->z = Binary::readVarInt($b, $off);
				return $pk;

			case 0x1d: //ENTITY_EVENT: eid varint, event byte, unknown varint
				$pk = new \pocketmine\network\protocol\EntityEventPacket();
				$pk->eid = Binary::readVarInt($b, $off);
				$pk->event = ord($b[$off++]);
				return $pk;

			case 0x20: //MOB_EQUIPMENT: eid varint, slot16, byte slot, byte selectedSlot, byte unknown
				$pk = new \pocketmine\network\protocol\MobEquipmentPacket();
				$pk->eid = Binary::readVarInt($b, $off);
				$pk->item = self::readSlot16($b, $off);
				$pk->slot = ord($b[$off++]);
				$pk->selectedSlot = ord($b[$off++]);
				return $pk;

			case 0x22: //INTERACT: byte action, eid varint
				$pk = new \pocketmine\network\protocol\InteractPacket();
				$pk->action = ord($b[$off++]);
				$pk->target = Binary::readVarInt($b, $off);
				return $pk;

			case 0x23: //USE_ITEM: blockcoords, varint face, vec3f, vec3f, varint slot, slot16
				$pk = new \pocketmine\network\protocol\UseItemPacket();
				$pk->x = Binary::readVarInt($b, $off);
				$pk->y = ord($b[$off++]);
				$pk->z = Binary::readVarInt($b, $off);
				$pk->face = Binary::readVarInt($b, $off);
				$pk->fx = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->fy = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->fz = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->posX = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->posY = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->posZ = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->slot = Binary::readVarInt($b, $off);
				$pk->item = self::readSlot16($b, $off);
				return $pk;

			case 0x24: //PLAYER_ACTION: eid varint, action varint, blockcoords, face varint
				$pk = new \pocketmine\network\protocol\PlayerActionPacket();
				$pk->eid = Binary::readVarInt($b, $off);
				$pk->action = Binary::readVarInt($b, $off);
				$pk->x = Binary::readVarInt($b, $off);
				$pk->y = ord($b[$off++]);
				$pk->z = Binary::readVarInt($b, $off);
				$pk->face = Binary::readVarInt($b, $off);
				return $pk;

			case 0x2b: //ANIMATE: action varint, eid varint
				$pk = new \pocketmine\network\protocol\AnimatePacket();
				$pk->action = Binary::readVarInt($b, $off);
				$pk->eid = Binary::readVarInt($b, $off);
				return $pk;

			case 0x2d: //DROP_ITEM: byte type, slot16
				$pk = new \pocketmine\network\protocol\DropItemPacket();
				$pk->type = ord($b[$off++]);
				$pk->item = self::readSlot16($b, $off);
				return $pk;

			case 0x30: //CONTAINER_CLOSE
				$pk = new \pocketmine\network\protocol\ContainerClosePacket();
				$pk->windowid = ord($b[$off++]);
				return $pk;

			case 0x31: //CONTAINER_SET_SLOT: byte windowid, varint slot, varint hotbarSlot, slot16
				$pk = new \pocketmine\network\protocol\ContainerSetSlotPacket();
				$pk->windowid = ord($b[$off++]);
				$pk->slot = Binary::readVarInt($b, $off);
				$pk->hotbarSlot = Binary::readVarInt($b, $off);
				$pk->item = self::readSlot16($b, $off);
				return $pk;

			case 0x35: //CRAFTING_EVENT: byte windowId, varint type, uuid, slots16 in, slots16 out
				$pk = new \pocketmine\network\protocol\CraftingEventPacket();
				$pk->windowId = ord($b[$off++]);
				$pk->type = Binary::readVarInt($b, $off);
				$pk->id = UUID::fromBinary(substr($b, $off, 16)); $off += 16;
				$size = Binary::readUnsignedVarInt($b, $off);
				for($i = 0; $i < $size and $i < 128 and $off < $l; $i++){
					$pk->input[] = self::readSlot16($b, $off);
				}
				$size = Binary::readUnsignedVarInt($b, $off);
				for($i = 0; $i < $size and $i < 128 and $off < $l; $i++){
					$pk->output[] = self::readSlot16($b, $off);
				}
				return $pk;

			case 0x37: //BLOCK_ENTITY_DATA: blockcoords + nbt
				$pk = new \pocketmine\network\protocol\BlockEntityDataPacket();
				$pk->x = Binary::readVarInt($b, $off);
				$pk->y = ord($b[$off++]);
				$pk->z = Binary::readVarInt($b, $off);
				$pk->namedtag = substr($b, $off);
				return $pk;

			case 0x38: //PLAYER_INPUT: LFloat motX, LFloat motY, bool jumping, bool sneaking
				$pk = new \pocketmine\network\protocol\PlayerInputPacket();
				$pk->motX = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->motY = Binary::readLFloat(substr($b, $off, 4)); $off += 4;
				$pk->jumping = ($off < $l and ord($b[$off++]) > 0);
				$pk->sneaking = ($off < $l and ord($b[$off]) > 0);
				return $pk;

			case 0x43: //REQUEST_CHUNK_RADIUS: varint radius
				$pk = new \pocketmine\network\protocol\RequestChunkRadiusPacket();
				$pk->radius = Binary::readVarInt($b, $off);
				return $pk;

			case 0x45: //ITEM_FRAME_DROP_ITEM: blockcoords + slot16
				$pk = new \pocketmine\network\protocol\ItemFrameDropItemPacket();
				$pk->x = Binary::readVarInt($b, $off);
				$pk->y = ord($b[$off++]);
				$pk->z = Binary::readVarInt($b, $off);
				$pk->dropItem = self::readSlot16($b, $off);
				return $pk;

			case 0x4c: //COMMAND_STEP: string command, string overload, 2x uvarint, byte, uvarint64, string args json, string
				$pk = new CommandStepPacket();
				$pk->command = self::readStr16($b, $off);
				$pk->overload = self::readStr16($b, $off);
				Binary::readUnsignedVarInt($b, $off);
				Binary::readUnsignedVarInt($b, $off);
				$off++; //bool
				Binary::readUnsignedVarInt($b, $off);
				$argsJson = self::readStr16($b, $off);
				$pk->args = json_decode($argsJson, true);
				return $pk;

			default:
				return null; //unmapped / serverbound-only / unsupported: ignore
		}
	}

	/** Reads a protocol-23 string (big-endian unsigned short length). */
	private static function readStr11($b, &$off){
		$l = strlen($b);
		if($off + 2 > $l){
			$off = $l;
			return "";
		}
		$len = Binary::readShort(substr($b, $off, 2));
		$off += 2;
		$len = max(0, min($len, $l - $off));
		$s = substr($b, $off, $len);
		$off += $len;
		return $s;
	}

	/** Reads a protocol-23 item slot (short id, byte count, short damage). */
	private static function readSlot11($b, &$off){
		$l = strlen($b);
		if($off + 5 > $l){
			$off = $l;
			return Item::get(0, 0, 0);
		}
		$id = Binary::readShort(substr($b, $off, 2)); $off += 2;
		$count = ord($b[$off++]);
		$damage = Binary::readShort(substr($b, $off, 2)); $off += 2;
		return Item::get($id, $damage, $count);
	}

	/** Marks a manually decoded packet as consumed through $off in $buffer. */
	private static function finishIncoming11(DataPacket $pk, $buffer, $off){
		$pk->setBuffer($buffer, min(strlen($buffer), max(1, $off)));
		$pk->isEncoded = true;
		return $pk;
	}

	/**
	 * Decodes a protocol-23 packet into the native packet object model.
	 * Protocol 23 uses bare packet IDs and legacy slot/field layouts.
	 * The returned packet offset is absolute within $buffer so raw batches can
	 * advance safely without per-packet length prefixes.
	 */
	public static function decodeIncoming11($buffer){
		if(strlen($buffer) < 1){
			return null;
		}
		$pid = ord($buffer[0]);
		$b = substr($buffer, 1);
		$off = 0;
		$l = strlen($b);
		try{
			switch($pid){
				case 0x82: //LOGIN
					$pk = new \pocketmine\network\protocol\LoginPacket();
					$pk->setBuffer($buffer, 1);
					$pk->decode();
					$pk->isEncoded = true;
					return $pk;
				case 0xb1: //BATCH: outer payload has a fixed 4-byte length
					if($l < 4){ return null; }
					$size = Binary::readInt(substr($b, 0, 4));
					if($size < 0 or $size > $l - 4){ return null; }
					$pk = new \pocketmine\network\protocol\BatchPacket();
					$pk->payload = substr($b, 4, $size);
					return self::finishIncoming11($pk, $buffer, 5 + $size);
				case 0x85: //TEXT
					$pk = new \pocketmine\network\protocol\TextPacket();
					$pk->type = $off < $l ? ord($b[$off++]) : 0;
					if($pk->type === 1){ $pk->source = self::readStr11($b, $off); }
					if($pk->type === 2){
						$pk->message = self::readStr11($b, $off);
						$count = $off < $l ? ord($b[$off++]) : 0;
						for($i = 0; $i < $count; ++$i){ $pk->parameters[] = self::readStr11($b, $off); }
					}else{ $pk->message = self::readStr11($b, $off); }
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x8f: //MOVE_PLAYER
					$pk = new \pocketmine\network\protocol\MovePlayerPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					$pk->x = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->y = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->z = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->yaw = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->bodyYaw = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->pitch = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->mode = $off < $l ? ord($b[$off++]) : 0; $pk->onGround = true;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x90: //REMOVE_BLOCK
					$pk = new \pocketmine\network\protocol\RemoveBlockPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					$pk->x = Binary::readInt(substr($b, $off, 4)); $off += 4;
					$pk->z = Binary::readInt(substr($b, $off, 4)); $off += 4;
					$pk->y = $off < $l ? ord($b[$off++]) : 0;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x96: //ENTITY_EVENT
					$pk = new \pocketmine\network\protocol\EntityEventPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					$pk->event = $off < $l ? ord($b[$off++]) : 0;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x98: //PLAYER_EQUIPMENT -> native MOB_EQUIPMENT
					$pk = new \pocketmine\network\protocol\MobEquipmentPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					$id = Binary::readShort(substr($b, $off, 2)); $off += 2;
					$damage = Binary::readShort(substr($b, $off, 2)); $off += 2;
					$pk->item = Item::get($id, $damage, $id === 0 ? 0 : 1);
					$pk->slot = $off < $l ? ord($b[$off++]) : 0;
					$pk->selectedSlot = $off < $l ? ord($b[$off++]) : 0;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x99: //PLAYER_ARMOR_EQUIPMENT
					$pk = new \pocketmine\network\protocol\MobArmorEquipmentPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					for($i = 0; $i < 4; ++$i){ $pk->slots[$i] = Item::get($off < $l ? ord($b[$off++]) : 0, 0, 1); }
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x9a: //INTERACT
					$pk = new \pocketmine\network\protocol\InteractPacket();
					$pk->action = $off < $l ? ord($b[$off++]) : 0;
					$pk->target = Binary::readLong(substr($b, $off, 8)); $off += 8;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x9b: //USE_ITEM
					$pk = new \pocketmine\network\protocol\UseItemPacket();
					$pk->x = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->y = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->z = Binary::readInt(substr($b, $off, 4)); $off += 4;
					$pk->face = $off < $l ? ord($b[$off++]) : 0;
					$id = Binary::readShort(substr($b, $off, 2)); $off += 2; $damage = Binary::readShort(substr($b, $off, 2)); $off += 2;
					$pk->item = Item::get($id, $damage, $id === 0 ? 0 : 1); $pk->slot = -1; $pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					$pk->fx = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->fy = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->fz = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					$pk->posX = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->posY = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->posZ = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x9c: //PLAYER_ACTION
					$pk = new \pocketmine\network\protocol\PlayerActionPacket();
					$pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8; $pk->action = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->x = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->y = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->z = Binary::readInt(substr($b, $off, 4)); $off += 4; $pk->face = Binary::readInt(substr($b, $off, 4)); $off += 4;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0x9e: //SET_ENTITY_DATA
					$pk = new \pocketmine\network\protocol\SetEntityDataPacket(); $pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8; $pk->metadata = Binary::readMetadata(substr($b, $off)); $off = $l;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa3: //ANIMATE
					$pk = new \pocketmine\network\protocol\AnimatePacket(); $pk->action = $off < $l ? ord($b[$off++]) : 0; $pk->eid = Binary::readLong(substr($b, $off, 8)); $off += 8;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa4: //RESPAWN
					$pk = new \pocketmine\network\protocol\RespawnPacket(); $pk->x = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->y = Binary::readFloat(substr($b, $off, 4)); $off += 4; $pk->z = Binary::readFloat(substr($b, $off, 4)); $off += 4;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa5: //DROP_ITEM
					$pk = new \pocketmine\network\protocol\DropItemPacket(); $pk->type = $off < $l ? ord($b[$off++]) : 0; $pk->item = self::readSlot11($b, $off);
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa7: //CONTAINER_CLOSE
					$pk = new \pocketmine\network\protocol\ContainerClosePacket(); $pk->windowid = $off < $l ? ord($b[$off++]) : 0;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa8: //CONTAINER_SET_SLOT
					$pk = new \pocketmine\network\protocol\ContainerSetSlotPacket(); $pk->windowid = $off < $l ? ord($b[$off++]) : 0; $pk->slot = Binary::readShort(substr($b, $off, 2)); $off += 2; $pk->hotbarSlot = 0; $pk->item = self::readSlot11($b, $off);
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xa9: //CONTAINER_SET_DATA
					$pk = new \pocketmine\network\protocol\ContainerSetDataPacket(); $pk->windowid = $off < $l ? ord($b[$off++]) : 0; $pk->property = Binary::readShort(substr($b, $off, 2)); $off += 2; $pk->value = Binary::readShort(substr($b, $off, 2)); $off += 2;
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				case 0xaa: //CONTAINER_SET_CONTENT
					$pk = new \pocketmine\network\protocol\ContainerSetContentPacket(); $pk->windowid = $off < $l ? ord($b[$off++]) : 0; $count = Binary::readShort(substr($b, $off, 2)); $off += 2; for($i = 0; $i < $count; ++$i){ $pk->slots[$i] = self::readSlot11($b, $off); } if($pk->windowid === 0 and $off + 2 <= $l){ $hc = Binary::readShort(substr($b, $off, 2)); $off += 2; for($i = 0; $i < $hc; ++$i){ $pk->hotbar[$i] = Binary::readInt(substr($b, $off, 4)); $off += 4; } }
					return self::finishIncoming11($pk, $buffer, 1 + $off);
				default:
					return null;
			}
		}catch(\Throwable $e){
			return null;
		}
	}

	/** Reads a varint-length string from $b at $off (advanced). */
	private static function readStr16($b, &$off){
		$len = Binary::readUnsignedVarInt($b, $off);
		$s = substr($b, $off, $len);
		$off += strlen($s);
		return $s;
	}
}
