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

namespace pocketmine\network;

use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\network\protocol\Info;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface{

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var string[] */
	private $identifiers;

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	public function __construct(Server $server, $proxyProtocol = false, $frpFeed = false){

		$this->server = $server;
		$this->identifiers = [];

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $this->server->getPort(), $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp(), $proxyProtocol, $frpFeed);
		// NOTE: RakLibServer's constructor already calls start() (registers with
		// ThreadManager + runs onStart() which creates the UDP socket). Do NOT
		// call start() again here or it double-registers.
		$this->interface = new ServerHandler($this->rakLib, $this);
	}

	public function setNetwork(Network $network){
		$this->network = $network;
	}

	/** 供 FrpManager 获取底层 RakLibServer 以直接喂包/取回包 */
	public function getRakLibServer(){
		return $this->rakLib;
	}

	public function process(){
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			$lasttime = time();
			while($this->interface->handlePacket()){
				$diff = time() - $lasttime;
				if($diff >= 1) break;
			}
		}

		if($this->rakLib->isTerminated()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	public function closeSession($identifier, $reason){
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($player->getLeaveMessage(), $reason);
		}
	}

	public function close(Player $player, $reason = "unknown reason"){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown(){
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->interface->emergencyShutdown();
	}

	public function openSession($identifier, $address, $port, $clientID){
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	public function handleEncapsulated($identifier, EncapsulatedPacket $packet, $flags){
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$player = $this->players[$identifier];
					$pk = $this->getPacket($packet->buffer, $player->getProtocol());
					if($pk !== null){
						$pk->decode();
						$player->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				$logger = $this->server->getLogger();
				if(\pocketmine\DEBUG > 1 and isset($pk)){
					$logger->debug("Exception in packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
				}
				$logger->logException($e);
			}
		}
	}

	public function blockAddress($address, $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	public function handleRaw($address, $port, $payload){
		$this->server->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket($address, $port, $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK($identifier, $identifierACK){

	}

	public function setName($name){

		if($this->server->isDServerEnabled()){
			if($this->server->dserverConfig["motdMaxPlayers"] > 0) $pc = $this->server->dserverConfig["motdMaxPlayers"];
			elseif($this->server->dserverConfig["motdAllPlayers"]) $pc = $this->server->getDServerMaxPlayers();
			else $pc = $this->server->getMaxPlayers();

			if($this->server->dserverConfig["motdPlayers"]) $poc = $this->server->getDServerOnlinePlayers();
			else $poc = count($this->server->getOnlinePlayers());
		}else{
			$info = $this->server->getQueryInformation();
			$pc = $info->getMaxPlayerCount();
			$poc = $info->getPlayerCount();
		}

		$this->interface->sendOption("name",
			"MCPE;" . addcslashes($name, ";") . ";" .
			ProtocolInfo::CURRENT_PROTOCOL . ";" .
			\pocketmine\MINECRAFT_VERSION_NETWORK . ";" .
			$poc . ";" .
			$pc
		);
	}

	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", (bool) $name);
	}

	public function handleOption($name, $value){
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, $needACK = false, $immediate = false){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			$pk = null;
			$newProto = MultiProtocol::isNewProtocol($player->getProtocol());
			if(!$packet->isEncoded){
				$packet->encode();
			}elseif(!$needACK){
				//encapsulation cache is per wire format: 0.14 content is 0x8e-wrapped,
				//0.15 content is 0xfe-wrapped with translated ids/fields
				$cacheProp = $newProto ? "__encapsulatedPacket81" : "__encapsulatedPacket";
				if(!isset($packet->$cacheProp)){
					$buffers = $newProto ? MultiProtocol::translateOutgoing($packet) : [$packet->buffer];
					if(count($buffers) === 0){
						return null; //packet does not exist in this client's protocol
					}
					$packet->$cacheProp = new CachedEncapsulatedPacket;
					$packet->$cacheProp->identifierACK = null;
					$packet->$cacheProp->buffer = chr($newProto ? 0xfe : 0x8e) . $buffers[0];
					$packet->$cacheProp->reliability = 3;
					$packet->$cacheProp->orderChannel = 0;
				}
				$pk = $packet->$cacheProp;
			}

			if(!$immediate and !$needACK and $packet::NETWORK_ID !== ProtocolInfo::BATCH_PACKET
				and Network::$BATCH_THRESHOLD >= 0
				and strlen($packet->buffer) >= Network::$BATCH_THRESHOLD){
				$this->server->batchPackets([$player], [$packet], true);
				return null;
			}

			if($pk === null){
				$buffers = $newProto ? MultiProtocol::translateOutgoing($packet) : [$packet->buffer];
				if(count($buffers) === 0){
					return null; //packet does not exist in this client's protocol
				}
				foreach($buffers as $outBuffer){
					$pk = new EncapsulatedPacket();
					$pk->buffer = chr($newProto ? 0xfe : 0x8e) . $outBuffer;
					$packet->reliability = 3;
					$packet->orderChannel = 0;

					if($needACK === true){
						$pk->identifierACK = $this->identifiersACK[$identifier]++;
					}

					$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));
				}

				return $pk->identifierACK;
			}

			$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));

			return $pk->identifierACK;
		}

		return null;
	}

	private function getPacket($buffer, $protocol = null){
		if(MultiProtocol::isNewProtocol($protocol)){
			//0.15.x wire: [pid][payload] or [0xfe][pid][payload]
			$pid = ord($buffer[0]);
			$start = 1;
			if($pid === 0xfe){
				$pid = ord($buffer[1]);
				$start = 2;
			}
			$mapped = MultiProtocol::toServerPid($pid);
			if($mapped === null or ($data = $this->network->getPacket($mapped)) === null){
				return null;
			}
			$data->setBuffer($buffer, $start);

			return $data;
		}

		$pid = ord($buffer[1]);

		if(($data = $this->network->getPacket($pid)) === null){
			$pid = ord($buffer[0]);
			if(($data = $this->network->getPacket($pid)) === null){
				//0.15.x wire (protocol not known yet, e.g. LOGIN): optional 0xfe
				//prefix, then [pid][payload] with 0.15 ids
				$start = 1;
				if($pid === 0xfe){
					$pid = ord($buffer[1]);
					$start = 2;
				}
				$mapped = MultiProtocol::toServerPid($pid);
				if($mapped === null or ($data = $this->network->getPacket($mapped)) === null){
					return null;
				}
				$data->setBuffer($buffer, $start);
				return $data;
			}
			$data->setBuffer($buffer, 1);
			return $data;
		}
		$data->setBuffer($buffer, 2);

		return $data;
	}
}
