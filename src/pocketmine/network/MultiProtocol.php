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
use pocketmine\network\protocol\DataPacket;
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

	/** First protocol version of the 0.16.x family (0.16.0/0.16.1=90, 0.16.2=91) */
	const PROTOCOL_0_16 = 90;

	/** 0.13 客户端不认识的 0.14 新增物品/方块 id(出现在创造背包/配方里会让 0.13 崩溃) */
	public static $oldProtocolUnknownItems = [23, 125, 154, 165, 179, 180, 182, 356, 380, 389, 395, 461, 462];

	/** 0.13 官方创造栏白名单(从 Genisys 0.13 initCreativeItems 提取, 精确匹配 0.13 客户端) */
	public static $oldProtocolCreativeItems = [1,2,3,4,5,6,7,12,13,14,15,16,17,18,19,20,21,22,24,25,27,28,30,31,32,35,37,38,39,40,41,42,44,45,46,47,48,49,50,52,53,54,56,57,58,61,65,66,67,69,70,72,73,76,77,78,79,80,81,82,85,86,87,88,89,91,96,98,99,100,101,102,103,106,107,108,109,110,111,112,113,114,116,120,121,123,126,128,129,131,133,134,135,136,139,143,145,146,147,148,151,152,153,155,156,158,159,161,162,163,164,167,170,171,172,173,174,175,183,184,185,186,187,243,245,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,318,319,320,321,322,323,324,325,328,330,331,332,333,334,337,338,339,341,345,346,347,348,349,350,351,352,353,354,355,357,359,360,361,362,363,364,365,366,367,369,370,371,372,373,374,375,376,377,378,379,382,383,384,388,390,391,392,393,394,396,397,400,406,411,412,413,414,415,427,428,429,430,431,438,458,460,463,466];

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
	 *
	 * @param int|null $protocol
	 *
	 * @return int
	 */
	public static function wireFamily($protocol){
		if($protocol === null){
			return 0;
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
	 * 0.14.x = 0x8e, 0.15.x/0.16.x = 0xfe, 0.13.x = no marker at all.
	 *
	 * @param int $family
	 *
	 * @return string
	 */
	public static function encapPrefixForFamily($family){
		if($family === 1 or $family === 3){
			return "\xfe";
		}
		return $family === 2 ? "" : "\x8e";
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

	/** Reads a varint-length string from $b at $off (advanced). */
	private static function readStr16($b, &$off){
		$len = Binary::readUnsignedVarInt($b, $off);
		$s = substr($b, $off, $len);
		$off += strlen($s);
		return $s;
	}
}
