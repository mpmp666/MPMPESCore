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

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

/**
 * /frp — 内置 frp 隧道管理命令(仅管理员/控制台可用)
 *
 * 用法:
 *   /frp                      — 查看所有隧道状态
 *   /frp status [名字]        — 查看状态(指定名字看单条详情)
 *   /frp restart [名字]       — 重启全部/单条隧道
 *   /frp stop [名字]          — 停止全部/单条隧道
 *   /frp start [名字]         — 启动全部已停止/单条隧道
 *   /frp reload               — 重扫 frp*.toml(新增自动启动, 变更自动重启, 删除自动停止)
 */
class FrpCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"管理内置 frp 隧道(仅管理员/控制台)",
			"/frp [status|start|restart|stop|reload] [隧道名]"
		);
		//不设权限节点: 直接按 isOp() 判定, 保证管理员/控制台一定可用
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		//仅管理员或控制台可用
		if(!$this->testPermission($sender)){
			return true;
		}
		if(!$sender->isOp()){
			$sender->sendMessage(TextFormat::RED . "该命令仅限管理员/控制台使用");
			return true;
		}

		$mgr = \pocketmine\mpapi\MPApi::getFrpManager();
		if($mgr === null){
			$sender->sendMessage(TextFormat::YELLOW . "frp 隧道管理器未初始化");
			return true;
		}

		$cmd = strtolower($args[0] ?? "status");
		$name = isset($args[1]) ? trim((string) $args[1]) : null;

		//reload 允许在零隧道状态下使用(可发现新增配置)
		if($cmd === "reload" or $cmd === "reloadall"){
			$mgr->reload();
			$sender->sendMessage(TextFormat::GREEN . "已重扫 frp*.toml, 当前隧道数: " . count($mgr->getTunnels()));
			return true;
		}

		if(count($mgr->getTunnels()) === 0){
			$sender->sendMessage(TextFormat::YELLOW . "未启用任何 frp 隧道(未找到有效 frp*.toml, 可用 /frp reload 重扫)");
			return true;
		}

		switch($cmd){
			case "restart":
			case "r":
				if($name !== null){
					if($mgr->restartTunnel($name)){
						$sender->sendMessage(TextFormat::GREEN . "已重启 frp 隧道 $name");
					}else{
						$sender->sendMessage(TextFormat::RED . "隧道 $name 不存在或配置无效");
					}
				}else{
					$mgr->restartAll();
					$sender->sendMessage(TextFormat::GREEN . "已触发全部 frp 隧道重启");
				}
				return true;
			case "stop":
			case "s":
				if($name !== null){
					if($mgr->stopTunnel($name)){
						$sender->sendMessage(TextFormat::GREEN . "已停止 frp 隧道 $name");
					}else{
						$sender->sendMessage(TextFormat::RED . "隧道 $name 不存在");
					}
				}else{
					$mgr->shutdown();
					$sender->sendMessage(TextFormat::GREEN . "已停止全部 frp 隧道");
				}
				return true;
			case "start":
				if($name !== null){
					if($mgr->startTunnel($name)){
						$sender->sendMessage(TextFormat::GREEN . "已启动 frp 隧道 $name");
					}else{
						$sender->sendMessage(TextFormat::RED . "隧道 $name 不存在或配置无效");
					}
				}else{
					$started = 0;
					foreach($mgr->getTunnels() as $tName => $t){
						if(!$t["ready"] and $mgr->startTunnel($tName)){
							$started++;
						}
					}
					$sender->sendMessage(TextFormat::GREEN . "已启动 $started 条已停止的隧道");
				}
				return true;
			case "status":
			case "list":
			case "ls":
			default:
				if($name !== null){
					$this->sendTunnelDetail($sender, $mgr, $name);
					return true;
				}
				$sender->sendMessage(TextFormat::GOLD . "=== frp 隧道状态 (服务端进程内) ===");
				foreach($mgr->getTunnels() as $tName => $t){
					$pp = $t["proxyProtocolVersion"] !== "" ? "PROXY " . $t["proxyProtocolVersion"] : "无PROXY";
					$state = $t["ready"] ? "运行中" : ($t["state"] !== "stopped" ? "连接中(" . $t["state"] . ")" : "未启动");
					$remote = $t["remotePorts"] !== [] ? " 远程端口=" . implode(",", $t["remotePorts"]) : "";
					$runId = $t["runId"] !== "" ? " run_id=" . $t["runId"] : "";
					$sender->sendMessage(TextFormat::WHITE . "[" . $tName . "] " . TextFormat::GRAY . $pp . " | " . $state . $remote . $runId);
				}
				$sender->sendMessage(TextFormat::GOLD . "用法: /frp status|start|restart|stop|reload [隧道名]");
				return true;
		}
	}

	/**
	 * 单条隧道详情
	 */
	private function sendTunnelDetail(CommandSender $sender, $mgr, string $name){
		$tunnels = $mgr->getTunnels();
		if(!isset($tunnels[$name])){
			$sender->sendMessage(TextFormat::RED . "隧道 $name 不存在(现有: " . implode(", ", array_keys($tunnels)) . ")");
			return;
		}
		$t = $tunnels[$name];
		$sender->sendMessage(TextFormat::GOLD . "=== frp 隧道 $name ===");
		$sender->sendMessage(TextFormat::WHITE . "frps 地址: " . TextFormat::GRAY . ($t["serverAddr"] !== "" ? $t["serverAddr"] . ":" . $t["serverPort"] : "(配置未加载)"));
		$sender->sendMessage(TextFormat::WHITE . "远程端口: " . TextFormat::GRAY . ($t["remotePorts"] !== [] ? implode(", ", $t["remotePorts"]) : "(无)"));
		$sender->sendMessage(TextFormat::WHITE . "状态: " . TextFormat::GRAY . ($t["ready"] ? "运行中(ready)" : $t["state"]) . TextFormat::WHITE . " | TLS: " . ($t["tls"] ? "开" : "关") . " | PROXY: " . ($t["proxyProtocolVersion"] !== "" ? $t["proxyProtocolVersion"] : "无"));
		if($t["runId"] !== ""){
			$sender->sendMessage(TextFormat::WHITE . "run_id: " . TextFormat::GRAY . $t["runId"]);
		}
	}
}
