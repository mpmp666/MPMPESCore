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

use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info;
use pocketmine\utils\Binary;

class MultiProtocol{

	/** First protocol version of the 0.15.x family */
	const PROTOCOL_0_15 = 81;

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

	private static function init(){
		if(self::$toServer === null){
			self::$toServer = array_flip(self::$toClient);
		}
	}

	/**
	 * Whether the given client protocol belongs to the 0.15.x family (new wire format)
	 *
	 * @param int|null $protocol
	 *
	 * @return bool
	 */
	public static function isNewProtocol($protocol){
		return $protocol !== null and $protocol >= self::PROTOCOL_0_15;
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
					$out .= Binary::writeMetadata($packet->metadata);
					$out .= Binary::writeShort(count($packet->links));
					foreach($packet->links as $link){
						$out .= Binary::writeLong($link[0]) . Binary::writeLong($link[1]) . chr($link[2]);
					}
					return [$out];
				}
				//raw buffer fallback: cannot rescale without parsing metadata, remap id only
				return [chr(0x0b) . substr($buf, 1)];

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
}
