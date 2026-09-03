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

namespace pocketmine\frp;

/**
 * frpc.php — MPMPESCore 内置纯 PHP frp 客户端 (无需下载 frpc.exe)
 *
 * 本文件是服务端库, 不提供独立 CLI 入口。由 FrpManager 以 require 方式加载,
 * 在服务端同一进程/同一线程内以非阻塞 tick 驱动方式运行(不产生单独 PID),
 * 日志直接走服务端 logger(PocketMine API), 不单独写 frpc.log。
 *
 * 实现要点 (协议对齐 fatedier/frp v1 wire + yamux):
 *  1. 控制连接: TCP 连接 frps → yamux 会话 → 控制流(流 ID=1)
 *     - Login('o') → LoginResp('1'); NewProxy('p') → NewProxyResp('2')
 *     - frps 发 ReqWorkConn('r') → 客户端新开 yamux 流发 NewWorkConn('w')
 *       → frps 在该流上回 StartWorkConn('s'), 该流成为 UDP 工作连接
 *  2. frp 消息帧: type(1字节) + int64BE 长度(8字节) + JSON
 *  3. yamux 帧: version(1)=0 + type(1) + flags(2BE) + streamID(4BE) + length(4BE)
 *     type: 0=Data 1=WindowUpdate 2=Ping 3=GoAway; flags: SYN=1 ACK=2 FIN=4 RST=8
 *     客户端流 ID 为奇数(1,3,5...); 打开流先发 WindowUpdate+SYN, 其 length 字段 = 接收窗口增量
 *  4. 鉴权: privilege_key = md5(token + timestamp) (frp GetAuthKey)
 *  5. UDP 转发: 每个玩家地址一条本地 UDP socket(连到 localIP:localPort),
 *     每包前置 PROXY protocol v2 头(src=玩家真实地址), 供 RakLib 还原真实 IP;
 *     本地回包再封装为 UDPPacket('u') 回传 frps
 *  6. 保活: yamux 层 Ping 每 30s + 工作连接应用层 Ping 每 30s
 */

// 本文件是服务端进程内库文件(被 FrpManager require_once), 不是独立 CLI 脚本,
// 因此绝不能在这里修改全局 error_reporting/display_errors:
// 那会覆盖 PocketMine.php 中的 "E_ALL & ~E_DEPRECATED" 屏蔽设置,
// 导致 PHP 8.4 弃用警告(Entity 坐标位运算/ArrayAccess/动态属性等)重新刷屏。
set_time_limit(0);

// -------------------- frp 消息类型 --------------------
const T_LOGIN = 0x6F;           // 'o'
const T_LOGIN_RESP = 0x31;      // '1'
const T_NEW_PROXY = 0x70;       // 'p'
const T_NEW_PROXY_RESP = 0x32;  // '2'
const T_CLOSE_PROXY = 0x63;     // 'c'
const T_NEW_WORK_CONN = 0x77;   // 'w'
const T_REQ_WORK_CONN = 0x72;   // 'r'
const T_START_WORK_CONN = 0x73; // 's'
const T_PING = 0x68;            // 'h'
const T_PONG = 0x34;            // '4'
const T_UDP_PACKET = 0x75;      // 'u'

// -------------------- yamux --------------------
const Y_DATA = 0;
const Y_WINDOW_UPDATE = 1;
const Y_PING = 2;
const Y_GO_AWAY = 3;
const F_SYN = 1;
const F_ACK = 2;
const F_FIN = 4;
const F_RST = 8;
const Y_HEADER_SIZE = 12;
const INITIAL_WINDOW = 262144;  // 256KB
const MAX_WINDOW = 6291456;     // 6MB (frp MaxStreamWindowSize)
const OPEN_DELTA = MAX_WINDOW - INITIAL_WINDOW; // 6029312

const PROXY_V2_SIG = "\r\n\r\n\x00\r\nQUIT\n";

/** @var callable|null 全局日志回调, 由 FrpManager 注入(服务端内嵌时走服务端日志) */
$FRPC_LOG_CALLBACK = null;

/**
 * 设置全局日志回调(服务端内嵌时传入 Server 的 Logger 输出函数)。
 * 独立 CLI 运行时不设置, 直接输出到 stdout。
 *
 * @param callable|null $cb function(string $level, string $msg):void
 */
function frpc_set_log_callback($cb){
	global $FRPC_LOG_CALLBACK;
	$FRPC_LOG_CALLBACK = $cb;
}

/**
 * 极简日志
 */
function flog($level, $msg){
	global $FRPC_LOG_CALLBACK;
	if(is_callable($FRPC_LOG_CALLBACK)){
		call_user_func($FRPC_LOG_CALLBACK, $level, $msg);
		return;
	}
	echo "[" . date('Y-m-d H:i:s') . "] [" . $level . "] " . $msg . PHP_EOL;
	@fflush(STDOUT);
}

/**
 * frp 控制流加密 (AES-128-CFB, 流式)。
 * frps 在 LoginResp 之后把控制流包在 golib/crypto 里:
 *  - 密钥 = PBKDF2(token, salt="frp", 64 轮, 16 字节, SHA1)
 *  - 发送方首帧写入 16 字节随机 IV, 之后按 CFB 流式加密/解密
 * 本类维护连续 CFB 状态, 可跨多个 yamux Data 帧。
 */
class FrpCfbStream{
	/** @var string 16 字节密钥 */
	private $key;
	/** @var string 16 字节当前反馈块(上一密文块) */
	private $prev;
	/** @var string 当前密钥流块 */
	private $ks = "";
	/** @var int */
	private $ksPos = 0;

	public function __construct($key, $iv){
		$this->key = $key;
		$this->prev = $iv;
	}

	/**
	 * CFB128 流式处理。加解密共用同一密钥流, 仅反馈块来源不同。
	 * $prev 始终保存最近 16 个"密文字节"(滚动窗口), 跨 update() 调用保持,
	 * 因此 16 字节块被拆到多次调用时也能正确推进。
	 * @param string $data
	 * @param bool   $isEncrypt
	 *
	 * @return string
	 */
	public function update($data, $isEncrypt){
		$out = "";
		$n = strlen($data);
		for($i = 0; $i < $n; $i++){
			if($this->ksPos === 0){
				$this->ks = openssl_encrypt($this->prev, 'aes-128-ecb', $this->key, OPENSSL_RAW_DATA);
			}
			$c = $data[$i] ^ $this->ks[$this->ksPos];
			$out .= $c;
			// 滚动窗口推入本次密文字节 (加密=输出字节, 解密=输入字节)
			$this->prev = substr($this->prev, 1) . ($isEncrypt ? $c : $data[$i]);
			$this->ksPos++;
			if($this->ksPos === 16){
				$this->ksPos = 0;
			}
		}
		return $out;
	}
}

/**
 * frp 控制流加密助手: 负责收/发两方向, 管理各自 IV
 */
class FrpCryptoEndpoint{
	/** @var string 密钥 */
	public $key;
	/** @var FrpCfbStream|null 收方向解密器 */
	public $in = null;
	/** @var string 收方向尚未集满的 IV 缓冲 */
	public $inIv = "";
	/** @var FrpCfbStream|null 发方向加密器 */
	public $out = null;
	/** @var string 发方向 IV */
	public $outIv = "";
	/** @var bool 发方向 IV 是否已发送 */
	public $outIvSent = false;

	public function __construct($key){
		$this->key = $key;
		$this->outIv = random_bytes(16);
		$this->out = new FrpCfbStream($key, $this->outIv);
	}

	/** 收方向: 先凑满 16 字节 IV, 再解密后续 */
	public function decrypt($payload){
		if($this->in === null){
			$need = 16 - strlen($this->inIv);
			if($need > 0 and $payload !== ""){
				$take = substr($payload, 0, $need);
				$this->inIv .= $take;
				$payload = substr($payload, $need);
			}
			if(strlen($this->inIv) === 16){
				$this->in = new FrpCfbStream($this->key, $this->inIv);
			}
		}
		if($this->in !== null and $payload !== ""){
			return $this->in->update($payload, false);
		}
		return $payload;
	}

	/** 发方向: 加密数据, 首帧前置 IV */
	public function encrypt($data){
		$payload = $this->out->update($data, true);
		if(!$this->outIvSent){
			$this->outIvSent = true;
			return $this->outIv . $payload;
		}
		return $payload;
	}
}

/**
 * 极简 TOML 子集解析器 (与 FrpManager::parseTOML 相同能力)
 */
function toml_parse($text){
	if(!is_string($text) or trim($text) === ""){
		return null;
	}
	$result = [];
	$ref = &$result;
	foreach(preg_split('/\r?\n/', $text) as $line){
		$line = trim($line);
		if($line === "" or $line[0] === "#" or $line[0] === ";"){
			continue;
		}
		if(preg_match('/^\[\[([A-Za-z0-9_\-\.]+)\]\]$/', $line, $m) > 0){
			$keys = explode(".", $m[1]);
			$ref = &$result;
			foreach($keys as $k){
				if(!isset($ref[$k]) or !is_array($ref[$k]) or !toml_is_list($ref[$k])){
					$ref[$k] = [];
				}
				$ref = &$ref[$k];
			}
			$ref[] = [];
			$ref = &$ref[count($ref) - 1];
			continue;
		}
		if(preg_match('/^\[([A-Za-z0-9_\-\.]+)\]$/', $line, $m) > 0){
			$keys = explode(".", $m[1]);
			$ref = &$result;
			foreach($keys as $i => $k){
				if($i === 0 and isset($ref[$k]) and is_array($ref[$k]) and toml_is_list($ref[$k]) and count($ref[$k]) > 0){
					$ref = &$ref[$k][count($ref[$k]) - 1];
					continue;
				}
				if(!isset($ref[$k]) or !is_array($ref[$k])){
					$ref[$k] = [];
				}
				$ref = &$ref[$k];
			}
			continue;
		}
		if(preg_match('/^([A-Za-z0-9_\-]+)\s*=\s*(.+)$/', $line, $m) > 0){
			$k = $m[1];
			$v = trim($m[2]);
			$v = preg_replace('/\s+#.*$/', "", $v);
			$len = strlen($v);
			if($len >= 2 and ($v[0] === '"' or $v[0] === "'") and $v[$len - 1] === $v[0]){
				$v = substr($v, 1, -1);
			}elseif(strtolower($v) === "true"){
				$v = true;
			}elseif(strtolower($v) === "false"){
				$v = false;
			}elseif(is_numeric($v)){
				$v = $v + 0;
			}
			$ref[$k] = $v;
		}
	}
	unset($ref);
	return $result;
}

function toml_is_list(array $a) : bool{
	$i = 0;
	foreach($a as $k => $v){
		if($k !== $i++){
			return false;
		}
	}
	return true;
}

/**
 * frp GetAuthKey = md5(token . timestamp)
 */
function frp_auth_key($token, $timestamp){
	return md5($token . $timestamp);
}

/**
 * frp 控制流加密密钥 = PBKDF2(token, salt="frp", 64 轮, 16 字节, SHA1)
 */
function frp_control_key($token){
	return hash_pbkdf2('sha1', $token, 'frp', 64, 16, true);
}

/**
 * 构建 PROXY protocol v2 (UDP) 头
 * 载荷布局(IPv4 12 字节): srcAddr(4) + dstAddr(4) + srcPort(2) + dstPort(2)
 * 与 go-proxyproto / RakLib parseProxyProtocolHeader 一致(srcPort 在偏移 8)
 * @return string 二进制头; 无法解析地址时返回 ""
 */
function build_proxy_v2($srcIp, $srcPort, $dstIp, $dstPort){
	$src = @inet_pton($srcIp);
	$dst = @inet_pton($dstIp);
	if($src === false or $dst === false){
		return "";
	}
	$isV6 = strlen($src) === 16;
	$famProto = $isV6 ? 0x22 : 0x12; // AF_INET6/AF_INET <<4 | DGRAM
	$payload = $src . $dst . pack('n', $srcPort & 0xFFFF) . pack('n', $dstPort & 0xFFFF);
	return PROXY_V2_SIG . chr(0x21) . chr($famProto) . pack('n', strlen($payload)) . $payload;
}

/**
 * yamux 会话 (客户端)
 */
class YamuxSession{
	/** @var resource */
	public $sock;
	/** @var string 未解析的 TCP 读缓冲 */
	public $readBuf = "";
	/** @var string 待写 TCP 缓冲 */
	public $writeBuf = "";
	/** @var array sid => [sendWindow, unacked, buf, sendQueue, role, proxyName, players, lastPing, crypto] */
	public $streams = [];
	/** @var int */
	public $nextStreamId = 1;
	/** @var int */
	public $pingId = 0;
	/** @var float */
	public $lastPing = 0;
	/** @var float 最近收到 Pong(ACK) 的时间 */
	public $lastPong = 0;
	/** @var bool */
	public $dead = false;
	/** @var callable|null function(YamuxSession $s, int $sid, int $type, array $msg):void */
	public $onMessage = null;

	public function __construct($sock){
		$this->sock = $sock;
		stream_set_blocking($sock, false);
	}

	/**
	 * 打开新流, 返回流 ID。
	 * 打开流 = 发送 WindowUpdate+SYN, 其 length 字段 = 我方通告的接收窗口增量 (与 frp 一致)
	 */
	public function openStream(){
		$sid = $this->nextStreamId;
		$this->nextStreamId += 2;
		$this->streams[$sid] = [
			'sendWindow' => INITIAL_WINDOW,
			'unacked'    => 0,
			'buf'        => "",
			'sendQueue'  => "",
			'role'       => null,
			'proxyName'  => null,
			'players'    => [],
			'lastPing'   => 0,
			'crypto'     => null,
		];
		$this->sendFrame(Y_WINDOW_UPDATE, F_SYN, $sid, "", OPEN_DELTA);
		$this->flush();
		return $sid;
	}

	/** 对流启用 frp 控制流加密 (LoginResp 之后调用) */
	public function enableCrypto($sid, $key){
		if(isset($this->streams[$sid])){
			$this->streams[$sid]['crypto'] = new FrpCryptoEndpoint($key);
		}
	}

	/** 发送 yamux 帧。length 字段 = strlen(payload), 或显式指定 $length */
	public function sendFrame($type, $flags, $sid, $payload, $length = null){
		if($length === null){
			$length = strlen($payload);
		}
		$this->writeBuf .= chr(0) . chr($type) . pack('n', $flags) . pack('N', $sid) . pack('N', $length) . $payload;
	}

	/** 尽力写空 writeBuf */
	public function flush(){
		if($this->writeBuf === ""){
			return;
		}
		$n = @fwrite($this->sock, $this->writeBuf);
		if($n === false or $n === 0){
			return;
		}
		$this->writeBuf = substr($this->writeBuf, $n);
	}

	/** 从 socket 读取并解析帧 */
	public function pump(){
		$chunk = @fread($this->sock, 65536);
		if($chunk === false){
			$this->dead = true;
			return;
		}
		if($chunk === ""){
			if(feof($this->sock)){
				$this->dead = true;
			}
			return;
		}
		$this->readBuf .= $chunk;
		$this->parseFrames();
	}

	/** 解析 readBuf 中完整的 yamux 帧 */
	public function parseFrames(){
		while(strlen($this->readBuf) >= Y_HEADER_SIZE){
			$type = ord($this->readBuf[1]);
			$flags = unpack('n', substr($this->readBuf, 2, 2))[1];
			$sid = unpack('N', substr($this->readBuf, 4, 4))[1];
			$len = unpack('N', substr($this->readBuf, 8, 4))[1];
			if($type === Y_DATA){
				// Data 帧: length = 载荷长度, 需读满 payload 才能处理
				$need = Y_HEADER_SIZE + $len;
				if(strlen($this->readBuf) < $need){
					break;
				}
				$payload = substr($this->readBuf, Y_HEADER_SIZE, $len);
				$this->readBuf = substr($this->readBuf, $need);
				$this->handleData($flags, $sid, $payload);
			}else{
				// WindowUpdate/Ping/GoAway: 无 payload, length 是窗口增量/ID/错误码
				$this->readBuf = substr($this->readBuf, Y_HEADER_SIZE);
				if($type === Y_WINDOW_UPDATE){
					if(isset($this->streams[$sid])){
						$this->streams[$sid]['sendWindow'] += $len;
						$this->flushSendQueue($sid);
					}
				}elseif($type === Y_PING){
					if($flags & F_SYN){
						// 回应 Ping: type=Ping, flags=ACK, streamID=0, length=pingID
						$this->sendFrame(Y_PING, F_ACK, 0, "", $len);
						$this->flush();
					}else{
						$this->lastPong = microtime(true);
					}
				}elseif($type === Y_GO_AWAY){
					$this->dead = true;
				}
			}
		}
	}

	private function handleData($flags, $sid, $payload){
		if($flags & F_RST){
			if(isset($this->streams[$sid])){
				$this->closeStream($sid);
			}
			return;
		}
		if($flags & F_FIN){
			if(isset($this->streams[$sid])){
				$this->closeStream($sid);
			}
			return;
		}
		if(!isset($this->streams[$sid])){
			return; // 未知流, 忽略
		}
		// 控制流加密: LoginResp 后 frps 对整个控制流做 AES-CFB, 先解密
		if(isset($this->streams[$sid]['crypto'])){
			$payload = $this->streams[$sid]['crypto']->decrypt($payload);
		}
		$this->streams[$sid]['buf'] .= $payload;
		$this->parseMessages($sid);
	}

	/** 从流缓冲解析 frp 消息: type(1) + int64BE(8) + JSON */
	private function parseMessages($sid){
		while(true){
			if(strlen($this->streams[$sid]['buf']) < 9){
				return;
			}
			$type = ord($this->streams[$sid]['buf'][0]);
			$len = unpack('J', substr($this->streams[$sid]['buf'], 1, 8))[1];
			if($len < 0 or $len > 16 * 1024 * 1024){
				flog('E', "消息长度非法: $len");
				$this->dead = true;
				return;
			}
			if(strlen($this->streams[$sid]['buf']) < 9 + $len){
				return;
			}
			$json = substr($this->streams[$sid]['buf'], 9, $len);
			$this->streams[$sid]['buf'] = substr($this->streams[$sid]['buf'], 9 + $len);

			// 消费了多少数据就向对端补回多少接收窗口
			$this->streams[$sid]['unacked'] += 9 + $len;
			if($this->streams[$sid]['unacked'] >= 131072){ // 128KB
				$delta = $this->streams[$sid]['unacked'];
				$this->streams[$sid]['unacked'] = 0;
				$this->sendFrame(Y_WINDOW_UPDATE, 0, $sid, "", $delta);
				$this->flush();
			}

			$msg = json_decode($json, true);
			if(!is_array($msg)){
				$msg = [];
			}
			if($this->onMessage !== null){
				call_user_func($this->onMessage, $this, $sid, $type, $msg);
			}
		}
	}

	/** 向流发送一条 frp 消息 (排队, 受发送窗口约束; 控制流自动加密) */
	public function sendMessage($sid, $type, array $msg){
		// Go 的 omitempty 空结构体序列化为 {} (对象); 空数组必须转成对象, 否则对端 json 解析失败
		$json = json_encode($msg === [] ? new \stdClass() : $msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$payload = chr($type) . pack('J', strlen($json)) . $json;
		if(!isset($this->streams[$sid])){
			return;
		}
		// 控制流加密: LoginResp 后对 frp 消息体做 AES-CFB, 首帧前置 IV
		if(isset($this->streams[$sid]['crypto'])){
			$payload = $this->streams[$sid]['crypto']->encrypt($payload);
		}
		$this->streams[$sid]['sendQueue'] .= $payload;
		$this->flushSendQueue($sid);
	}

	/** 按发送窗口把 sendQueue 中的字节发出去 (yamux 数据流, 无需解析消息边界) */
	private function flushSendQueue($sid){
		if(!isset($this->streams[$sid])){
			return;
		}
		$q = $this->streams[$sid]['sendQueue'];
		while($q !== ""){
			if($this->streams[$sid]['sendWindow'] <= 0){
				break; // 等待对端 WindowUpdate
			}
			$chunk = substr($q, 0, min($this->streams[$sid]['sendWindow'], strlen($q)));
			$q = substr($q, strlen($chunk));
			$this->sendFrame(Y_DATA, 0, $sid, $chunk);
			$this->streams[$sid]['sendWindow'] -= strlen($chunk);
		}
		$this->streams[$sid]['sendQueue'] = $q;
		$this->flush();
	}

	public function closeStream($sid){
		if(!isset($this->streams[$sid])){
			return;
		}
		// 直接喂包模式: 玩家映射无 socket, 无需关闭
		unset($this->streams[$sid]);
	}

	public function closeAll(){
		foreach(array_keys($this->streams) as $sid){
			$this->closeStream($sid);
		}
		if(is_resource($this->sock)){
			@fclose($this->sock);
		}
	}
}

/**
 * frpc 主客户端 — 服务端同一进程/线程内运行 (非阻塞 tick 驱动)
 *
 * 状态机:
 *   DISCONNECTED → CONNECTING → HANDSHAKE → READY
 * FrpManager/Server 每个 tick 调用一次 tick(), 每次做非阻塞 I/O。
 * 不再产生单独 PID, 也不单独写日志文件(日志走服务端 logger)。
 */
class Frpc{
	const ST_DISCONNECTED = 0;
	const ST_CONNECTING = 1;
	const ST_HANDSHAKE = 2;
	const ST_READY = 3;

	/** @var array */
	private $conf;
	/** @var YamuxSession|null */
	private $session;
	/** @var resource|null 正在非阻塞连接的 socket */
	private $pendingSock = null;
	/** @var float */
	private $connectStart = 0;
	/** @var float */
	private $handshakeStart = 0;
	/** @var float 下次重连时间 */
	private $nextReconnectAt = 0;
	/** @var int */
	private $state = self::ST_DISCONNECTED;
	/** @var int 控制流 ID (恒为 1) */
	private $controlSid = 1;
	/** @var string */
	private $runId = "";
	/** @var bool */
	private $useTls = false;
	/** @var bool 是否已登录 */
	private $loggedIn = false;
	/** @var array name => proxy config */
	private $proxies = [];
	/** @var float */
	private $lastSweep = 0;
	/** @var int 已收到的 NewProxyResp 数 */
	private $proxyResps = 0;
	/** @var int 已发送的 NewProxy 数 */
	private $proxySent = 0;
	/** @var \raklib\server\RakLibServer|null 直接喂包目标 (FrpManager 注入) */
	private $rakLib = null;
	/** @var string 本隧道名 (如 frp / frp_second), 回包走独立队列 */
	private $tunnelName = "";

	public function __construct(array $conf){
		$this->conf = $conf;
		foreach($conf['proxies'] as $p){
			$this->proxies[$p['name']] = $p;
		}
	}

	/** 注入 RakLibServer 引用, 使数据包直接喂入 RakNet 而非走本地 UDP socket */
	public function setRakLib($rakLib){
		$this->rakLib = $rakLib;
	}

	/** 设置本隧道名 (FrpManager 注入), 回包读对应独立队列 */
	public function setTunnelName(string $name){
		$this->tunnelName = $name;
	}

	/**
	 * 启动(首次调用 tick 前)
	 */
	public function start(){
		$this->state = self::ST_DISCONNECTED;
		$this->nextReconnectAt = microtime(true);
	}

	/**
	 * 服务端每 tick 调用一次 (非阻塞)
	 */
	public function tick(){
		switch($this->state){
			case self::ST_DISCONNECTED:
				if(microtime(true) >= $this->nextReconnectAt){
					$this->beginConnect();
				}
				break;
			case self::ST_CONNECTING:
				$this->pumpConnect();
				break;
			case self::ST_HANDSHAKE:
				$this->pumpHandshake();
				break;
			case self::ST_READY:
				$this->pumpReady();
				break;
		}
	}

	/** 当前状态名(供 /frp 命令与 API) */
	public function getStateName() : string{
		switch($this->state){
			case self::ST_CONNECTING: return "connecting";
			case self::ST_HANDSHAKE: return "handshake";
			case self::ST_READY: return "ready";
			default: return "disconnected";
		}
	}

	/** 是否就绪(已登录并注册代理) */
	public function isReady() : bool{
		return $this->state === self::ST_READY;
	}

	public function getRunId() : string{
		return $this->runId;
	}

	/**
	 * 强制停止(服务端关闭时调用)
	 */
	public function stop(){
		if($this->session !== null){
			$this->session->closeAll();
			$this->session = null;
		}
		if(is_resource($this->pendingSock)){
			@fclose($this->pendingSock);
			$this->pendingSock = null;
		}
		$this->state = self::ST_DISCONNECTED;
		$this->loggedIn = false;
		$this->runId = "";
		$this->proxyResps = 0;
		$this->proxySent = 0;
	}

	// ==================== 状态机 ====================

	private function scheduleReconnect(){
		if($this->session !== null){
			$this->session->closeAll();
			$this->session = null;
		}
		if(is_resource($this->pendingSock)){
			@fclose($this->pendingSock);
			$this->pendingSock = null;
		}
		$this->loggedIn = false;
		$this->runId = "";
		$this->proxyResps = 0;
		$this->proxySent = 0;
		// 首次连接失败(未登录)时切换 TLS 再试一次
		if(!$this->useTls and $this->conf['tryTlsOnFailure']){
			$this->useTls = true;
			flog('I', "切换到 TLS 模式重试");
		}
		$this->state = self::ST_DISCONNECTED;
		$this->nextReconnectAt = microtime(true) + 3;
	}

	private function beginConnect(){
		$addr = $this->conf['serverAddr'];
		$port = (int) $this->conf['serverPort'];
		flog('I', "连接 frps {$addr}:{$port} (" . ($this->useTls ? "TLS" : "明文") . ") ...");

		$ctx = stream_context_create(['ssl' => [
			'verify_peer'       => false,
			'verify_peer_name'  => false,
			'allow_self_signed' => true,
		]]);
		// 非阻塞连接
		$sock = @stream_socket_client('tcp://' . $addr . ':' . $port, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);
		if($sock === false){
			flog('E', "连接失败: $errstr ($errno)");
			$this->scheduleReconnect();
			return;
		}
		stream_set_blocking($sock, false);
		$this->pendingSock = $sock;
		$this->connectStart = microtime(true);
		$this->state = self::ST_CONNECTING;
	}

	private function pumpConnect(){
		$sock = $this->pendingSock;
		$read = null;
		$write = [$sock];
		$except = null;
		$tv = [0, 0]; // 非阻塞
		$n = @stream_select($read, $write, $except, $tv[0], $tv[1]);
		if($n === false or !is_resource($sock) or feof($sock)){
			flog('E', "连接被拒绝/失败");
			$this->scheduleReconnect();
			return;
		}
		if($n === 0){
			// 连接仍在进行
			if(microtime(true) - $this->connectStart > 10){
				flog('E', "连接超时");
				$this->scheduleReconnect();
			}
			return;
		}
		// 已连接(write 就绪)
		if($this->useTls){
			stream_set_blocking($sock, true);
			$ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			if($ok !== true){
				@fclose($sock);
				$this->pendingSock = null;
				flog('E', "TLS 握手失败");
				$this->scheduleReconnect();
				return;
			}
			stream_set_blocking($sock, false);
			flog('I', "TLS 握手成功");
		}
		$this->pendingSock = null;

		$this->session = new YamuxSession($sock);
		$this->session->onMessage = function($s, $sid, $type, $msg){
			$this->handleMessage($sid, $type, $msg);
		};
		$this->session->lastPing = microtime(true);
		$this->session->lastPong = microtime(true);

		// 打开控制流并登录
		$this->controlSid = $this->session->openStream();
		$ts = time();
		$login = [
			'version'       => '0.61.2',
			'hostname'      => gethostname() ?: 'MPMPEServer',
			'os'            => PHP_OS_FAMILY,
			'arch'          => php_uname('m'),
			'user'          => $this->conf['user'],
			'privilege_key' => frp_auth_key($this->conf['token'], $ts),
			'timestamp'     => $ts,
			'run_id'        => "",
			'pool_count'    => 1,
		];
		$this->session->sendMessage($this->controlSid, T_LOGIN, $login);

		$this->handshakeStart = microtime(true);
		$this->loggedIn = false;
		$this->runId = "";
		$this->proxyResps = 0;
		$this->proxySent = 0;
		$this->state = self::ST_HANDSHAKE;
	}

	private function pumpHandshake(){
		if($this->session->dead){
			throw new \RuntimeException("frps 关闭了连接");
		}
		if(microtime(true) - $this->handshakeStart > 15){
			throw new \RuntimeException("登录/注册超时");
		}
		$this->pumpOnce();

		// 登录成功且代理未发送 → 发 NewProxy
		if($this->loggedIn and $this->proxySent === 0){
			foreach($this->proxies as $name => $p){
				$this->session->sendMessage($this->controlSid, T_NEW_PROXY, [
					'proxy_name'      => $this->conf['user'] . '.' . $name,
					'proxy_type'      => 'udp',
					'remote_port'     => (int) $p['remotePort'],
					'use_encryption'  => false,
					'use_compression' => false,
				]);
				$this->proxySent++;
			}
			flog('I', "已发送 " . count($this->proxies) . " 个 UDP 代理注册请求");
		}

		if($this->loggedIn and $this->proxySent > 0 and $this->proxyResps >= $this->proxySent){
			flog('I', "控制连接就绪, run_id=" . $this->runId);
			$this->state = self::ST_READY;
		}
	}

	private function pumpReady(){
		if($this->session->dead){
			throw new \RuntimeException("frps 连接已断开");
		}
		$this->pumpOnce();
		$this->doTimers();
	}

	/** 非阻塞: 一次 select + pump + 回包队列回读 */
	private function pumpOnce(){
		$read = [$this->session->sock];
		$write = [];
		if($this->session->writeBuf !== ""){
			$write[] = $this->session->sock;
		}
		$except = null;
		$tv = [0, 0]; // 非阻塞, 每次 tick 快速返回
		$n = @stream_select($read, $write, $except, $tv[0], $tv[1]);
		if($n === false){
			throw new \RuntimeException("stream_select 失败");
		}

		foreach($read as $r){
			if($r === $this->session->sock){
				$this->session->pump();
				if($this->session->dead){
					throw new \RuntimeException("frps 连接已断开");
				}
			}
		}
		if(in_array($this->session->sock, $write, true)){
			$this->session->flush();
		}
		// 回包: 每个隧道有独立 frpOutQueues[tunnelName] 队列,
		// 本实例只 drain 自己的队列, 无需全局路由
		$this->drainFrpOutbound();
	}

	/**
	 * 回包: 从本隧道独立的 frpOutQueues[tunnelName] 队列读取, 封装为 UDPPacket 回传 frps
	 */
	private function drainFrpOutbound(){
		if($this->rakLib === null){
			return;
		}
		while(($frame = $this->rakLib->readFrpOutbound($this->tunnelName)) !== null and strlen($frame) > 0){
			$offset = 0;
			if($frame[$offset] !== "\x01"){
				continue;
			}
			$offset++;
			$len = ord($frame[$offset++]);
			$playerIp = substr($frame, $offset, $len);
			$offset += $len;
			$playerPort = \raklib\Binary::readShort(substr($frame, $offset, 2));
			$offset += 2;
			$data = substr($frame, $offset);
			if($data === "" or $data === "\0"){
				continue;
			}
			$key = $playerIp . ':' . $playerPort;
			// 找到该玩家所属的工作流
			$sid = null;
			foreach($this->session->streams as $cand => $st){
				if(isset($st['players'][$key])){
					$sid = $cand;
					break;
				}
			}
			if($sid === null){
				continue;
			}
			$this->session->streams[$sid]['players'][$key]['last'] = microtime(true);
			$this->session->sendMessage($sid, T_UDP_PACKET, [
				'c' => base64_encode($data),
				'r' => ['IP' => $playerIp, 'Port' => $playerPort, 'Zone' => ""],
			]);
		}
		// 回包可能有积压, 立即 flush
		if($this->session->writeBuf !== ""){
			$this->session->flush();
		}
	}

	/** 发送 yamux 保活 Ping 与应用层保活 */
	private function doTimers(){
		$now = microtime(true);
		// yamux keepalive 每 30s
		if($now - $this->session->lastPing >= 30){
			$this->session->pingId++;
			$this->session->sendFrame(Y_PING, F_SYN, 0, "", $this->session->pingId);
			$this->session->lastPing = $now;
			$this->session->flush();
		}
		// 工作连接应用层 Ping 每 30s (frps 对工作流 60s 读超时)
		foreach($this->session->streams as $sid => $st){
			if($st['role'] === 'work' and $st['proxyName'] !== null){
				if($now - $st['lastPing'] >= 30){
					$this->session->sendMessage($sid, T_PING, []);
					$this->session->streams[$sid]['lastPing'] = $now;
				}
			}
		}
		// 玩家映射空闲清理 (30s) — 直接喂包模式无 socket, 仅清理映射
		if($now - $this->lastSweep >= 5){
			$this->lastSweep = $now;
			foreach($this->session->streams as $sid => $st){
				foreach($st['players'] as $key => $p){
					if($now - $p['last'] > 30){
						unset($this->session->streams[$sid]['players'][$key]);
						flog('I', "玩家 $key 空闲超时, 已回收");
					}
				}
			}
		}
		// 长时间未收到 pong → 判定断线
		if($now - $this->session->lastPong > 90){
			throw new \RuntimeException("yamux 保活超时");
		}
	}

	/**
	 * 处理 frp 消息
	 */
	private function handleMessage($sid, $type, array $msg){
		if($sid === $this->controlSid){
			$this->handleControl($type, $msg);
		}else{
			$this->handleWork($sid, $type, $msg);
		}
	}

	private function handleControl($type, array $msg){
		if($type === T_LOGIN_RESP){
			if(!empty($msg['error'])){
				flog('E', "登录被拒绝: " . $msg['error']);
				$this->session->dead = true;
				return;
			}
			$this->runId = (string) ($msg['run_id'] ?? "");
			$this->loggedIn = true;
			// frps 在 LoginResp 之后对整个控制流启用 AES-CFB 加密
			$this->session->enableCrypto($this->controlSid, frp_control_key($this->conf['token']));
			flog('I', "登录成功, frps 版本=" . ($msg['version'] ?? "?") . ", run_id={$this->runId}, 控制流加密已启用");
		}elseif($type === T_NEW_PROXY_RESP){
			$this->proxyResps++;
			if(!empty($msg['error'])){
				flog('E', "代理注册失败: " . ($msg['proxy_name'] ?? "?") . " → " . $msg['error']);
			}else{
				flog('I', "代理 " . ($msg['proxy_name'] ?? "?") . " 注册成功, 远程地址 " . ($msg['remote_addr'] ?? "?"));
			}
		}elseif($type === T_REQ_WORK_CONN){
			$this->openWorkConn();
		}elseif($type === T_CLOSE_PROXY){
			flog('I', "frps 关闭代理: " . ($msg['proxy_name'] ?? "?"));
		}elseif($type === T_PING){
			$this->session->sendMessage($this->controlSid, T_PONG, []);
		}
	}

	private function handleWork($sid, $type, array $msg){
		if($type === T_START_WORK_CONN){
			if(!empty($msg['error'])){
				flog('E', "工作连接错误: " . $msg['error']);
				$this->session->closeStream($sid);
				return;
			}
			$proxyName = $msg['proxy_name'] ?? "";
			$stripped = str_replace($this->conf['user'] . '.', '', (string) $proxyName);
			if(!isset($this->proxies[$stripped])){
				flog('E', "工作连接指向未知代理: $proxyName");
				$this->session->closeStream($sid);
				return;
			}
			$this->session->streams[$sid]['role'] = 'work';
			$this->session->streams[$sid]['proxyName'] = $stripped;
			$this->session->streams[$sid]['lastPing'] = microtime(true);
			flog('I', "工作连接就绪: 流#$sid → 代理 $stripped (本地 {$this->proxies[$stripped]['localIP']}:{$this->proxies[$stripped]['localPort']})");
		}elseif($type === T_UDP_PACKET){
			$proxyName = $this->session->streams[$sid]['proxyName'] ?? null;
			if($proxyName === null){
				return; // 尚未 StartWorkConn
			}
			$this->forwardToLocal($sid, $proxyName, $msg);
		}elseif($type === T_PING){
			// 工作连接保活, 无需回复
		}
	}

	/** 响应 ReqWorkConn: 新开 yamux 流, 发 NewWorkConn */
	private function openWorkConn(){
		$sid = $this->session->openStream();
		$ts = time();
		$this->session->sendMessage($sid, T_NEW_WORK_CONN, [
			'run_id'        => $this->runId,
			'privilege_key' => frp_auth_key($this->conf['token'], $ts),
			'timestamp'     => $ts,
		]);
		flog('I', "收到 ReqWorkConn, 已打开工作流 #$sid");
	}

	/**
	 * frps → RakNet: 把 UDP 数据包直接喂入跨线程队列 (真实地址直传, 无 PROXY 头, 不走本地 UDP socket)
	 */
	private function forwardToLocal($sid, $proxyName, array $msg){
		$content = base64_decode((string) ($msg['c'] ?? ""), true);
		if($content === false or $content === ""){
			return;
		}
		$r = $msg['r'] ?? null;
		if(!is_array($r)){
			flog('W', "UDPPacket 缺少远程地址, 丢弃");
			return;
		}
		$playerIp = (string) ($r['IP'] ?? "");
		$playerPort = (int) ($r['Port'] ?? 0);
		if($playerIp === "" or $playerPort <= 0){
			flog('W', "UDPPacket 远程地址非法: $playerIp:$playerPort, 丢弃");
			return;
		}
		$key = $playerIp . ':' . $playerPort;

		if($this->rakLib === null){
			flog('W', "RakLib 未就绪, 丢弃玩家 $key 数据包");
			return;
		}
		if(!isset($this->session->streams[$sid]['players'][$key])){
			// 记录玩家→工作流 映射, 供回包选择正确的 yamux 流
			$this->session->streams[$sid]['players'][$key] = [
				'ip'   => $playerIp,
				'port' => $playerPort,
				'last' => microtime(true),
			];
			flog('I', "玩家 $key 接入 (直接喂入 RakNet, 流#$sid, 代理 $proxyName)");
		}

		// 直接喂入: 真实 IP/端口 + 裸 RakNet 数据 (不需要 PROXY v2 头), 附带隧道名供回包路由
		$this->session->streams[$sid]['players'][$key]['last'] = microtime(true);
		$this->rakLib->pushFrpInbound($this->tunnelName, $playerIp, $playerPort, $content);
	}
}

// -------------------- 配置加载 --------------------
function load_config($tomlPath){
	if(!is_file($tomlPath)){
		flog('E', "找不到配置文件: $tomlPath");
		return null;
	}
	$conf = toml_parse(file_get_contents($tomlPath));
	if($conf === null or empty($conf['serverAddr']) or empty($conf['serverPort']) or empty($conf['proxies'])){
		flog('E', "frp.toml 缺少 serverAddr/serverPort/proxies");
		return null;
	}
	$token = "";
	if(!empty($conf['auth']) and is_array($conf['auth'])){
		$token = (string) ($conf['auth']['token'] ?? "");
	}elseif(isset($conf['token'])){
		$token = (string) $conf['token'];
	}
	if($token === ""){
		flog('E', "frp.toml 缺少 auth.token");
		return null;
	}
	$proxies = [];
	foreach($conf['proxies'] as $i => $p){
		if(!is_array($p) or empty($p['name']) or ($p['type'] ?? 'tcp') !== 'udp'){
			continue; // 仅支持 UDP 代理
		}
		$proxies[] = [
			'name'                 => (string) $p['name'],
			'localIP'              => (string) ($p['localIP'] ?? '127.0.0.1'),
			'localPort'            => (int) ($p['localPort'] ?? 0),
			'remotePort'           => (int) ($p['remotePort'] ?? 0),
			'proxyProtocolVersion' => (string) (($p['transport']['proxyProtocolVersion'] ?? '') ?: ''),
		];
	}
	if(empty($proxies)){
		flog('E', "frp.toml 中没有可用的 UDP 代理");
		return null;
	}
	return [
		'serverAddr'      => (string) $conf['serverAddr'],
		'serverPort'      => (int) $conf['serverPort'],
		'user'            => (string) ($conf['user'] ?? ""),
		'token'           => $token,
		'proxies'         => $proxies,
		'tryTlsOnFailure' => true,
	];
}
