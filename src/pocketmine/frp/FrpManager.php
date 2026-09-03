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

use pocketmine\Server;

/**
 * 内置 frp 隧道管理器 (支持多隧道, 服务端同一进程/线程内运行)
 *
 * 服务器根目录下每个有效配置算一条独立隧道:
 *   - frp.toml        → 默认隧道
 *   - frp_<名字>.toml → 附加隧道
 *
 * 每个隧道:
 *   1. 解析 TOML(serverAddr/serverPort/user/[auth]/[[proxies]]/[proxies.transport])
 *   2. 自动把 proxies.localPort 修正为服务器实际端口(隧道必须指向 MC 端口才能通)
 *   3. 以内置纯 PHP frp 客户端(frpc.php)在服务端同一进程/线程内非阻塞运行
 *      (不再 proc_open 单独 PID, 也不单独写 frpc.log; 日志走服务端 logger)
 *   4. transport.proxyProtocolVersion = "v2"/"v1" 时, RakLib 解析 PROXY 头还原真实玩家 IP
 */
class FrpManager{

	/** @var Server */
	private $server;
	/** @var string */
	private $frpcPhpPath;
	/** @var bool 是否已 require frpc.php */
	private $loaded = false;
	/** @var \raklib\server\RakLibServer|null 直接喂包目标 (Server 创建 RakLibInterface 后注入) */
	private $rakLib = null;
	/** @var array<string, array{name:string,tomlPath:string,proxyProtocolVersion:string,client:Frpc|null}> */
	private $tunnels = [];

	public function __construct(Server $server){
		$this->server = $server;
		$this->frpcPhpPath = __DIR__ . DIRECTORY_SEPARATOR . "frpc.php";
		register_shutdown_function(function(){
			$this->shutdown();
		});
	}

	/**
	 * 加载 frpc.php 并注入日志回调(走服务端日志, 不单独写文件)
	 */
	private function ensureLoaded(){
		if($this->loaded){
			return;
		}
		$this->loaded = true;
		require_once $this->frpcPhpPath;
		$logger = $this->server->getLogger();
		\pocketmine\frp\frpc_set_log_callback(function($level, $msg) use ($logger){
			if($level === 'E'){
				$logger->error("[frp] $msg");
			}elseif($level === 'W'){
				$logger->warning("[frp] $msg");
			}else{
				$logger->info("[frp] $msg");
			}
		});
	}

	/**
	 * 注入底层 RakLibServer 引用 (Server 创建 RakLibInterface 后调用), 供直接喂包。
	 *
	 * @param \raklib\server\RakLibServer|null $rakLib
	 */
	public function attachRakLib($rakLib){
		$this->rakLib = $rakLib;
		foreach($this->tunnels as $name => &$t){
			if($rakLib !== null){
				$rakLib->ensureFrpOutQueue($name);
			}
			if($t["client"] !== null){
				$t["client"]->setRakLib($rakLib);
				$t["client"]->setTunnelName($name);
			}
		}
		unset($t);
	}

	/**
	 * 扫描 frp*.toml 并解析/校验/修正 localPort。在端口绑定前调用。
	 */
	public function prepare(){
		$logger = $this->server->getLogger();
		$dataPath = $this->server->getDataPath();
		$files = glob($dataPath . "frp*.toml");
		if(empty($files)){
			$logger->info("未检测到 frp*.toml, frp 隧道未启用");
			return;
		}
		sort($files);
		$count = 0;
		foreach($files as $file){
			if($this->prepareTunnel($file)){
				$count++;
			}
		}
		if($count === 0){
			$logger->info("frp*.toml 均无效, frp 隧道未启用");
		}
	}

	/**
	 * 解析并准备单条隧道
	 *
	 * @param string $tomlPath
	 *
	 * @return bool 是否有效
	 */
	private function prepareTunnel(string $tomlPath) : bool{
		$logger = $this->server->getLogger();
		$base = basename($tomlPath);
		$name = substr($base, 0, -5); //去掉 .toml, 得 frp / frp_xxx

		$conf = self::parseTOML(file_get_contents($tomlPath));
		if($conf === null){
			$logger->error("$base 解析失败(格式不正确), 跳过该隧道");
			return false;
		}
		if(empty($conf["serverAddr"]) or empty($conf["serverPort"]) or empty($conf["proxies"]) or !is_array($conf["proxies"])){
			$logger->error("$base 缺少 serverAddr/serverPort/proxies, 跳过该隧道");
			return false;
		}
		$proxy = $conf["proxies"][0];
		if(empty($proxy["localPort"]) or empty($proxy["remotePort"])){
			$logger->error("$base 的 proxies[0] 缺少 localPort/remotePort, 跳过该隧道");
			return false;
		}

		//隧道必须指向服务器实际监听端口, 不一致则自动修正
		$serverPort = $this->server->getPort();
		if((int) $proxy["localPort"] !== $serverPort){
			$text = file_get_contents($tomlPath);
			$new = preg_replace('/^(localPort\s*=\s*)\d+/m', '${1}' . $serverPort, $text, 1, $cnt);
			if($cnt > 0){
				file_put_contents($tomlPath, $new);
				$logger->info("$base 的 localPort 已自动修正为服务器端口 $serverPort");
			}
		}

		//真实 IP 还原: 由 transport.proxyProtocolVersion 决定
		$pp = $conf["proxies"][0]["transport"]["proxyProtocolVersion"] ?? "";
		if($pp === "v2" or $pp === "v1"){
			$logger->info("$base 隧道已启用 PROXY $pp 真实 IP 还原");
		}

		$this->tunnels[$name] = [
			"name"                 => $name,
			"tomlPath"             => $tomlPath,
			"proxyProtocolVersion" => $pp,
			"client"               => null,
		];
		return true;
	}

	/**
	 * 启动所有 frpc 客户端(服务端进程内, 非阻塞 tick 驱动)
	 */
	public function start(){
		if(empty($this->tunnels)){
			return;
		}
		$this->ensureLoaded();
		$logger = $this->server->getLogger();
		foreach($this->tunnels as $name => &$t){
			$cfg = \pocketmine\frp\load_config($t["tomlPath"]);
			if($cfg === null){
				$logger->error("隧道 $name 配置无效, 跳过");
				continue;
			}
			$client = new \pocketmine\frp\Frpc($cfg);
			if($this->rakLib !== null){
				$this->rakLib->ensureFrpOutQueue($name);
			}
			$client->setRakLib($this->rakLib);
			$client->setTunnelName($name);
			$client->start();
			$t["client"] = $client;
			$logger->info("内置 PHP frpc 已启动 (隧道 $name, 服务端进程内运行)");
		}
		unset($t);
	}

	/**
	 * 周期性驱动所有 frpc 客户端(由 Server tick 调用)
	 *
	 * 每个 Frpc 实例各自只 drain 属于自己的出站队列 (frpOutQueues[tunnelName]),
	 * 彻底消除多隧道共用一个队列 + 按玩家 key 路由时的歧义。
	 */
	public function tick(){
		foreach($this->tunnels as $name => &$t){
			$client = $t["client"];
			if($client === null){
				continue;
			}
			try{
				$client->tick();
			}catch(\Throwable $e){
				$this->server->getLogger()->error("frp 隧道 $name 异常: " . $e->getMessage());
				$client->stop();
				$client->start();
			}
		}
		unset($t);
	}

	/**
	 * 手动重启全部隧道
	 */
	public function restartAll(){
		$this->ensureLoaded();
		foreach($this->tunnels as $name => &$t){
			if($t["client"] !== null){
				$t["client"]->stop();
			}
			$cfg = \pocketmine\frp\load_config($t["tomlPath"]);
			if($cfg === null){
				$this->server->getLogger()->error("隧道 $name 配置无效, 跳过");
				continue;
			}
			$client = new \pocketmine\frp\Frpc($cfg);
			if($this->rakLib !== null){
				$this->rakLib->ensureFrpOutQueue($name);
			}
			$client->setRakLib($this->rakLib);
			$client->setTunnelName($name);
			$client->start();
			$t["client"] = $client;
			$this->server->getLogger()->info("已重启 frp 隧道 $name");
		}
		unset($t);
	}

	/**
	 * 停止全部 frpc 客户端
	 */
	public function shutdown(){
		foreach($this->tunnels as &$t){
			if($t["client"] !== null){
				$t["client"]->stop();
				$t["client"] = null;
			}
		}
		unset($t);
	}

	/**
	 * 是否已启用 PROXY 协议真实 IP 还原(任一隧道启用即视为启用)
	 *
	 * @return bool
	 */
	public function isProxyProtocolEnabled() : bool{
		foreach($this->tunnels as $t){
			if($t["proxyProtocolVersion"] !== ""){
				return true;
			}
		}
		return false;
	}

	/**
	 * 是否启用 frp 直接喂包 (只要有已启动的隧道就启用: 包经跨线程队列直接喂给 RakLib, 不走本地 UDP socket)
	 *
	 * @return bool
	 */
	public function isFrpFeedEnabled() : bool{
		return !empty($this->tunnels);
	}

	/**
	 * 获取所有隧道状态信息(进程内运行, 无独立 PID)
	 *
	 * @return array<string, array{name:string,state:string,ready:bool,runId:string,proxyProtocolVersion:string}>
	 */
	public function getTunnels() : array{
		$out = [];
		foreach($this->tunnels as $name => $t){
			$state = "stopped";
			$ready = false;
			$runId = "";
			if($t["client"] !== null){
				$state = $t["client"]->getStateName();
				$ready = $t["client"]->isReady();
				$runId = $t["client"]->getRunId();
			}
			$out[$name] = [
				"name"                 => $name,
				"state"                => $state,
				"ready"                => $ready,
				"runId"                => $runId,
				"proxyProtocolVersion" => $t["proxyProtocolVersion"],
			];
		}
		return $out;
	}

	/**
	 * 极简 TOML 子集解析器: 支持 [table] / [[array-of-table]] / key = 'str'| "str"| 数字| bool
	 * 足够解析 frp*.toml; 不支持多行字符串/数组值/内联表
	 *
	 * @param string $text
	 *
	 * @return array|null
	 */
	public static function parseTOML($text){
		if(!is_string($text) or trim($text) === ""){
			return null;
		}
		$result = [];
		$ref = &$result;  //当前表的引用, 跨行保持(切勿中途 unset)
		foreach(preg_split('/\r?\n/', $text) as $line){
			$line = trim($line);
			if($line === "" or $line[0] === "#" or $line[0] === ";"){
				continue;
			}
			if(preg_match('/^\[\[([A-Za-z0-9_\-\.]+)\]\]$/', $line, $m) > 0){
				//[[array-of-table]]: 进入最后一个数组元素
				$keys = explode(".", $m[1]);
				$ref = &$result;
				foreach($keys as $k){
					if(!isset($ref[$k]) or !is_array($ref[$k]) or !self::isList($ref[$k])){
						$ref[$k] = [];
					}
					$ref = &$ref[$k];
				}
				$ref[] = [];
				$ref = &$ref[count($ref) - 1];
				continue;
			}
			if(preg_match('/^\[([A-Za-z0-9_\-\.]+)\]$/', $line, $m) > 0){
				//子表: 若父级是 [[array-of-table]], 定位到最后一个元素(如 [proxies.transport])
				$keys = explode(".", $m[1]);
				$ref = &$result;
				foreach($keys as $i => $k){
					if($i === 0 and isset($ref[$k]) and is_array($ref[$k]) and self::isList($ref[$k]) and count($ref[$k]) > 0){
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
				$v = preg_replace('/\s+#.*$/', "", $v); //行尾注释
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

	private static function isList(array $a) : bool{
		$i = 0;
		foreach($a as $k => $v){
			if($k !== $i++){
				return false;
			}
		}
		return true;
	}
}
