<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\ADVERTISE_SYSTEM;
use raklib\protocol\DATA_PACKET_0;
use raklib\protocol\DATA_PACKET_1;
use raklib\protocol\DATA_PACKET_2;
use raklib\protocol\DATA_PACKET_3;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DATA_PACKET_5;
use raklib\protocol\DATA_PACKET_6;
use raklib\protocol\DATA_PACKET_7;
use raklib\protocol\DATA_PACKET_8;
use raklib\protocol\DATA_PACKET_9;
use raklib\protocol\DATA_PACKET_A;
use raklib\protocol\DATA_PACKET_B;
use raklib\protocol\DATA_PACKET_C;
use raklib\protocol\DATA_PACKET_D;
use raklib\protocol\DATA_PACKET_E;
use raklib\protocol\DATA_PACKET_F;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PING_OPEN_CONNECTIONS;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;

class SessionManager{
	protected $packetPool = [];

	/** @var RakLibServer */
	protected $server;

	protected $socket;

	protected $receiveBytes = 0;
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessions = [];

	protected $name = "";

	protected $packetLimit = 1000;

	protected $shutdown = false;

	protected $ticks = 0;
	protected $lastMeasure;

	protected $block = [];
	protected $ipSec = [];

	/** @var bool 端口校验 (强制关闭: 内外网端口不一致时客户端握手会被拒, MOTD 能看到但进不去) */
	public $portChecking = false;

	/** @var bool 是否启用 frp 直接喂包 (frpc 走跨线程队列喂包, 不走本地 UDP socket/PROXY) */
	public $frpFeed = false;

	/** @var string[] 来自 frp 喂入的会话 (key = ip:port), 回包走 frp 队列而非本地 socket */
	protected $frpSessions = [];

	/** @var bool PROXY protocol(v2/v1)还原真实 IP, 由 frp.toml 配置驱动 */
	public $proxyProtocol = false;

	public function __construct(RakLibServer $server, UDPServerSocket $socket){
		$this->server = $server;
		$this->socket = $socket;
		$this->registerPackets();

		$this->serverId = mt_rand(0, PHP_INT_MAX);

		// PHP 8.5 port: do NOT enter the blocking loop here. The main server
		// loop pumps tick() via RakLibServer::onTick().
	}

	public function getPort(){
		return $this->server->getPort();
	}

	public function getLogger(){
		return $this->server->getLogger();
	}

	public function run(){
		$this->tickProcessor();
	}

	/**
	 * Single non-blocking network step (PHP 8.5 port).
	 * Replaces one iteration of the old tickProcessor() loop.
	 */
	public function tickOnce(){
		$max = 5000;
		while(--$max and $this->receivePacket()) ;
		$this->drainFrpInbound();
		while($this->receiveStream()) ;
		$this->tick();
	}

	/**
	 * 从跨线程队列读取 frpc 喂入的 UDP 数据包, 以真实地址直接送入会话处理。
	 * 数据包裸 RakNet 字节, 无 PROXY 头(地址直接传进来)。
	 */
	private function drainFrpInbound(){
		if(!$this->frpFeed){
			return;
		}
		while(($frame = $this->server->readFrpInbound()) !== null and strlen($frame) > 0){
			$offset = 0;
			if($frame[$offset] !== "\x00"){
				continue;
			}
			$offset++;
			$tlen = ord($frame[$offset++]);
			$tunnel = substr($frame, $offset, $tlen);
			$offset += $tlen;
			$len = ord($frame[$offset++]);
			$ip = substr($frame, $offset, $len);
			$offset += $len;
			$port = Binary::readShort(substr($frame, $offset, 2));
			$offset += 2;
			$data = substr($frame, $offset);

			if($data === "" or $data === "\0"){
				continue;
			}
			$this->receiveBytes += strlen($data);
			if(isset($this->block[$ip])){
				continue;
			}
			if(isset($this->ipSec[$ip])){
				$this->ipSec[$ip]++;
			}else{
				$this->ipSec[$ip] = 1;
			}

			$key = $ip . ":" . $port;
			$this->frpSessions[$key] = $tunnel; //记录玩家归属隧道, 回包路由用

			if(ord($data[0]) === UNCONNECTED_PING::$ID or ord($data[0]) === UNCONNECTED_PING_OPEN_CONNECTIONS::$ID){
				$packet = new UNCONNECTED_PING_OPEN_CONNECTIONS();
				if(ord($data[0]) === UNCONNECTED_PING::$ID){
					$packet = new UNCONNECTED_PING();
				}
				$packet->buffer = $data;
				$packet->decode();
				$pk = new UNCONNECTED_PONG();
				$pk->serverID = $this->getID();
				$pk->pingID = $packet->pingID;
				$pk->serverName = $this->getName();
				$this->sendPacket($pk, $ip, $port);
				continue;
			}

			$packet2 = $this->getPacketFromPool(ord($data[0]));
			if($packet2 !== null){
				$packet2->buffer = $data;
				$this->getSession($ip, $port)->handlePacket($packet2);
			}else{
				$this->streamRaw($ip, $port, $data);
			}
		}
	}

	private function tickProcessor(){
		$this->lastMeasure = microtime(true);

		while(!$this->shutdown){
			$start = microtime(true);
			$this->tickOnce();
			$time = microtime(true) - $start;
			if($time < 0.05){
				@time_sleep_until(microtime(true) + 0.05 - $time);
			}
			$this->tick();
		}
	}

	private function tick(){
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		foreach($this->ipSec as $address => $count){
			if($count >= $this->packetLimit){
				$this->blockAddress($address);
			}
		}
		$this->ipSec = [];


		if(($this->ticks & 0b1111) === 0){
			$diff = max(0.005, $time - $this->lastMeasure);
			$this->streamOption("bandwidth", serialize([
				"up" => $this->sendBytes / $diff,
				"down" => $this->receiveBytes / $diff
			]));
			$this->lastMeasure = $time;
			$this->sendBytes = 0;
			$this->receiveBytes = 0;

			// 清理已无活跃会话的 frp 地址标记(防内存累积)
			if(count($this->frpSessions) > 0){
				foreach($this->frpSessions as $addr => $v){
					if(!isset($this->sessions[$addr])){
						unset($this->frpSessions[$addr]);
					}
				}
			}

			if(count($this->block) > 0){
				asort($this->block);
				$now = microtime(true);
				foreach($this->block as $address => $timeout){
					if($timeout <= $now){
						unset($this->block[$address]);
					}else{
						break;
					}
				}
			}
		}

		++$this->ticks;
	}


	private function receivePacket(){
		if(($len = $this->socket->readPacket($buffer, $source, $port)) > 0){
			$this->receiveBytes += $len;
			//MPApi/frp: PROXY protocol 还原真实 IP。$source/$port 被替换为真实客户端地址,
			//$transportSource/$transportPort 保留原始传输地址(回复必须走它才能经 frp 回到玩家)
			$transportSource = null;
			$transportPort = null;
			if($this->proxyProtocol and $this->parseProxyProtocolHeader($buffer, $source, $port, $transportSource, $transportPort)){
				if(isset($this->block[$source])){
					return true;
				}
			}
			if(isset($this->block[$source])){
				return true;
			}

			if(isset($this->ipSec[$source])){
				$this->ipSec[$source]++;
			}else{
				$this->ipSec[$source] = 1;
			}

			$pid = ord($buffer[0]);

			if($pid == UNCONNECTED_PONG::$ID){
				return false;
			}

			if(($packet = $this->getPacketFromPool($pid)) !== null){
				$packet->buffer = $buffer;
				$this->getSession($source, $port, $transportSource, $transportPort)->handlePacket($packet);
				return true;
			}elseif($pid === UNCONNECTED_PING::$ID){
				//No need to create a session for just pings
				$packet = new UNCONNECTED_PING;
				$packet->buffer = $buffer;
				$packet->decode();

				$pk = new UNCONNECTED_PONG();
				$pk->serverID = $this->getID();
				$pk->pingID = $packet->pingID;
				$pk->serverName = $this->getName();
				$this->sendPacket($pk, $transportSource !== null ? $transportSource : $source, $transportPort !== null ? $transportPort : $port);
			}elseif($buffer !== ""){
				$this->streamRaw($source, $port, $buffer);
				return true;
			}else{
				return false;
			}
		}

		return false;
	}

	/**
	 * frp/PROXY protocol v2 头解析(frps 经 frpc UDP 转发时, 每个玩家会话的首包带此头)。
	 * 成功时: $buffer 去掉头, $source/$port 替换为真实客户端地址,
	 * $transportSource/$transportPort 写入实际收到包的传输地址(用于回复路由)。
	 *
	 * @param string $buffer
	 * @param string $source
	 * @param int    $port
	 * @param string $transportSource
	 * @param int    $transportPort
	 *
	 * @return bool 是否为 PROXY 头且解析成功
	 */
	private function parseProxyProtocolHeader(&$buffer, &$source, &$port, &$transportSource, &$transportPort){
		static $signature = "\r\n\r\n\x00\r\nQUIT\n";
		if(strlen($buffer) < 16 + 12 or substr($buffer, 0, 12) !== $signature){
			return false;
		}
		$verCmd = ord($buffer[12]);
		if(($verCmd >> 4) !== 2){
			return false;
		}
		if(($verCmd & 0x0F) !== 1){ //cmd 1 = PROXY (带地址); 0 = LOCAL, 无地址, 直接忽略剩余内容
			return false;
		}
		$famProto = ord($buffer[13]);
		$fam = $famProto >> 4;
		$len = (ord($buffer[14]) << 8) | ord($buffer[15]);
		$total = 16 + $len;
		if(strlen($buffer) < $total){
			return false;
		}
		$payload = substr($buffer, 16, $len);
		if($fam === 1 and $len >= 12){ //AF_INET
			$srcIp = ord($payload[0]) . '.' . ord($payload[1]) . '.' . ord($payload[2]) . '.' . ord($payload[3]);
			$srcPort = (ord($payload[8]) << 8) | ord($payload[9]);
		}elseif($fam === 2 and $len >= 36){ //AF_INET6
			$srcIp = self::ipv6ToString(substr($payload, 0, 16));
			$srcPort = (ord($payload[16]) << 8) | ord($payload[17]);
		}else{
			return false;
		}
		$transportSource = $source;
		$transportPort = $port;
		$source = $srcIp;
		$port = $srcPort;
		$buffer = substr($buffer, $total);
		return true;
	}

	/**
	 * @param string $raw 16 字节 IPv6 地址
	 *
	 * @return string
	 */
	private static function ipv6ToString($raw){
		$groups = [];
		for($i = 0; $i < 16; $i += 2){
			$groups[] = sprintf('%x', (ord($raw[$i]) << 8) | ord($raw[$i + 1]));
		}
		return implode(':', $groups);
	}

	public function sendPacket(Packet $packet, $dest, $port){
		$packet->encode();
		// frp 返回路径: 若目标会话来自 frp 喂入, 回包走跨线程队列回对应隧道 frpc, 而非本地 UDP socket
		if($this->frpFeed and isset($this->frpSessions[$dest . ":" . $port])){
			$this->server->pushFrpOutbound($this->frpSessions[$dest . ":" . $port], $dest, $port, $packet->buffer);
			$this->sendBytes += strlen($packet->buffer);
			return;
		}
		$this->sendBytes += $this->socket->writePacket($packet->buffer, $dest, $port);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){
		$id = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toBinary(true);
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamRaw($address, $port, $payload){
		$buffer = chr(RakLib::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamClose($identifier, $reason){
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamInvalid($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOpen(Session $session){
		$identifier = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamACK($identifier, $identifierACK){
		$buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOption($name, $value){
		$buffer = chr(RakLib::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->server->pushThreadToMainPacket($buffer);
	}

	private function checkSessions(){
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $i => $s){
				if($s->isTemporal()){
					unset($this->sessions[$i]);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function receiveStream(){
		if(strlen($packet = $this->server->readMainToThreadPacket()) > 0){
			$id = ord($packet[0]);
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet[$offset++]);
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				if(isset($this->sessions[$identifier])){
					$flags = ord($packet[$offset++]);
					$buffer = substr($packet, $offset);
					$this->sessions[$identifier]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer, true), $flags);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_RAW){
				$len = ord($packet[$offset++]);
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				if($this->frpFeed and isset($this->frpSessions[$address . ":" . $port])){
					$this->server->pushFrpOutbound($this->frpSessions[$address . ":" . $port], $address, $port, $payload);
					$this->sendBytes += strlen($payload);
				}else{
					$this->socket->writePacket($payload, $address, $port);
				}
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet[$offset++]);
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet[$offset++]);
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === RakLib::PACKET_SET_OPTION){
				$len = ord($packet[$offset++]);
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
				switch($name){
					case "name":
						$this->name = $value;
						break;
					case "portChecking":
						$this->portChecking = (bool) $value;
						break;
					case "packetLimit":
						$this->packetLimit = (int) $value;
						break;
				}
			}elseif($id === RakLib::PACKET_BLOCK_ADDRESS){
				$len = ord($packet[$offset++]);
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$timeout = Binary::readInt(substr($packet, $offset, 4));
				$this->blockAddress($address, $timeout);
			}elseif($id === RakLib::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}

				$this->socket->close();
				$this->shutdown = true;
			}elseif($id === RakLib::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}else{
				return false;
			}

			return true;
		}

		return false;
	}

	public function blockAddress($address, $timeout = 300){
		$final = microtime(true) + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				$this->getLogger()->notice("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	/**
	 * @param string $ip
	 * @param int    $port
	 *
	 * @return Session
	 */
	/**
	 * @param string      $ip
	 * @param int         $port
	 * @param string|null $transportIp PROXY 协议场景下的实际回复地址
	 * @param int|null    $transportPort
	 *
	 * @return Session
	 */
	public function getSession($ip, $port, $transportIp = null, $transportPort = null){
		$id = $ip . ":" . $port;
		if(!isset($this->sessions[$id])){
			$this->checkSessions();
			$this->sessions[$id] = new Session($this, $ip, $port);
			if($transportIp !== null){
				$this->sessions[$id]->setTransportAddress($transportIp, $transportPort);
			}
		}

		return $this->sessions[$id];
	}

	public function removeSession(Session $session, $reason = "unknown"){
		$id = $session->getAddress() . ":" . $session->getPort();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			unset($this->sessions[$id]);
			unset($this->frpSessions[$id]);
			$this->streamClose($id, $reason);
		}
	}

	public function openSession(Session $session){
		$this->streamOpen($session);
	}

	public function notifyACK(Session $session, $identifierACK){
		$this->streamACK($session->getAddress() . ":" . $session->getPort(), $identifierACK);
	}

	public function getName() : string{
		return $this->name;
	}

	public function getID(){
		return $this->serverId;
	}

	private function registerPacket($id, $class){
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param $id
	 *
	 * @return Packet
	 */
	public function getPacketFromPool($id){
		if(isset($this->packetPool[$id])){
			return clone $this->packetPool[$id];
		}

		return null;
	}

	private function registerPackets(){
		//$this->registerPacket(UNCONNECTED_PING::$ID, UNCONNECTED_PING::class);
		$this->registerPacket(UNCONNECTED_PING_OPEN_CONNECTIONS::$ID, UNCONNECTED_PING_OPEN_CONNECTIONS::class);
		$this->registerPacket(OPEN_CONNECTION_REQUEST_1::$ID, OPEN_CONNECTION_REQUEST_1::class);
		$this->registerPacket(OPEN_CONNECTION_REPLY_1::$ID, OPEN_CONNECTION_REPLY_1::class);
		$this->registerPacket(OPEN_CONNECTION_REQUEST_2::$ID, OPEN_CONNECTION_REQUEST_2::class);
		$this->registerPacket(OPEN_CONNECTION_REPLY_2::$ID, OPEN_CONNECTION_REPLY_2::class);
		$this->registerPacket(UNCONNECTED_PONG::$ID, UNCONNECTED_PONG::class);
		$this->registerPacket(ADVERTISE_SYSTEM::$ID, ADVERTISE_SYSTEM::class);
		$this->registerPacket(DATA_PACKET_0::$ID, DATA_PACKET_0::class);
		$this->registerPacket(DATA_PACKET_1::$ID, DATA_PACKET_1::class);
		$this->registerPacket(DATA_PACKET_2::$ID, DATA_PACKET_2::class);
		$this->registerPacket(DATA_PACKET_3::$ID, DATA_PACKET_3::class);
		$this->registerPacket(DATA_PACKET_4::$ID, DATA_PACKET_4::class);
		$this->registerPacket(DATA_PACKET_5::$ID, DATA_PACKET_5::class);
		$this->registerPacket(DATA_PACKET_6::$ID, DATA_PACKET_6::class);
		$this->registerPacket(DATA_PACKET_7::$ID, DATA_PACKET_7::class);
		$this->registerPacket(DATA_PACKET_8::$ID, DATA_PACKET_8::class);
		$this->registerPacket(DATA_PACKET_9::$ID, DATA_PACKET_9::class);
		$this->registerPacket(DATA_PACKET_A::$ID, DATA_PACKET_A::class);
		$this->registerPacket(DATA_PACKET_B::$ID, DATA_PACKET_B::class);
		$this->registerPacket(DATA_PACKET_C::$ID, DATA_PACKET_C::class);
		$this->registerPacket(DATA_PACKET_D::$ID, DATA_PACKET_D::class);
		$this->registerPacket(DATA_PACKET_E::$ID, DATA_PACKET_E::class);
		$this->registerPacket(DATA_PACKET_F::$ID, DATA_PACKET_F::class);
		$this->registerPacket(NACK::$ID, NACK::class);
		$this->registerPacket(ACK::$ID, ACK::class);
	}
}
