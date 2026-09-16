<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace pocketmine\network\protocol;

#include <rules/DataPacket.h>

use pocketmine\utils\Binary;
use pocketmine\utils\UUID;


class LoginPacket extends DataPacket{
	const NETWORK_ID = Info::LOGIN_PACKET;

	public $username;
	public $protocol1;
	public $protocol2;
	public $clientId;

	public $clientUUID;
	public $serverAddress;
	public $clientSecret;

	public $skinName = null;
	public $skin = null;

	public function decode(){
		//Cross-version sniff: 0.15.x (protocol 81+) logins start with a protocol
		//int followed by zlib-compressed JWT chain data; 0.14.x logins start with
		//the username string (whose first 4 bytes can never be a small int).
		$peek = Binary::readInt(substr($this->buffer, $this->offset, 4));
		if($peek >= 81 and $peek <= 83){
			$this->decode015($peek);
			return;
		}

		$this->username = $this->getString();
		$this->protocol1 = $this->getInt();
		$this->protocol2 = $this->getInt();
		$this->clientId = $this->getLong();
		$this->clientUUID = $this->getUUID();
		$this->serverAddress = $this->getString();
		$this->clientSecret = $this->getString();

		$this->skinName = $this->getString();
		$this->skin = $this->getString();
	}

	/**
	 * MCPE 0.15.x login: [protocol int][zlib int len + data]
	 * decompressed: [LInt chain json][LInt skin jwt]
	 * The JWTs are read without signature verification (same as Genisys 0.15.0).
	 */
	private function decode015($protocol){
		$this->protocol1 = $this->getInt();
		$this->protocol2 = 0;

		$str = @zlib_decode($this->get($this->getInt()));
		if($str === false){
			throw new \InvalidStateException("Invalid compressed login data");
		}
		$this->setBuffer($str, 0);

		$this->clientSecret = "";
		$chainData = json_decode($this->get($this->getLInt()));
		if(isset($chainData->{"chain"}) and is_array($chainData->{"chain"})){
			foreach($chainData->{"chain"} as $chain){
				$webtoken = self::decodeToken($chain);
				if(isset($webtoken["extraData"])){
					if(isset($webtoken["extraData"]["displayName"])){
						$this->username = $webtoken["extraData"]["displayName"];
					}
					if(isset($webtoken["extraData"]["identity"])){
						$this->clientUUID = UUID::fromString($webtoken["extraData"]["identity"]);
					}
				}
			}
		}

		$skinToken = self::decodeToken($this->get($this->getLInt()));
		if(isset($skinToken["ClientRandomId"])){
			$this->clientId = $skinToken["ClientRandomId"];
		}
		if(isset($skinToken["ServerAddress"])){
			$this->serverAddress = $skinToken["ServerAddress"];
		}
		if(isset($skinToken["SkinData"])){
			$this->skin = base64_decode($skinToken["SkinData"]);
		}
		if(isset($skinToken["SkinId"])){
			$this->skinName = $skinToken["SkinId"];
		}
		if($this->skinName === null){
			$this->skinName = "Standard_Custom";
		}
		if($this->clientUUID === null){
			$this->clientUUID = UUID::fromRandom();
		}
	}

	public function encode(){

	}

	private static function decodeToken($token){
		$tokens = explode(".", $token);
		if(count($tokens) < 2){
			return [];
		}
		list($headB64, $payloadB64, $sigB64) = $tokens;

		return json_decode(base64_decode($payloadB64), true) ?: [];
	}

}
