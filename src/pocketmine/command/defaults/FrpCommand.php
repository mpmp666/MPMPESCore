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
 *   /frp            — 查看所有隧道状态
 *   /frp status     — 查看所有隧道状态
 *   /frp restart    — 重启全部隧道
 *   /frp stop       — 停止全部隧道
 */
class FrpCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"管理内置 frp 隧道(仅管理员/控制台)",
			"/frp [status|restart|stop]"
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
		if($mgr === null or count($mgr->getTunnels()) === 0){
			$sender->sendMessage(TextFormat::YELLOW . "未启用任何 frp 隧道(未找到有效 frp*.toml)");
			return true;
		}

		$cmd = strtolower($args[0] ?? "status");
		switch($cmd){
			case "restart":
			case "r":
				$mgr->restartAll();
				$sender->sendMessage(TextFormat::GREEN . "已触发全部 frp 隧道重启");
				return true;
			case "stop":
			case "s":
				$mgr->shutdown();
				$sender->sendMessage(TextFormat::GREEN . "已停止全部 frp 隧道");
				return true;
			case "status":
			case "list":
			case "ls":
			default:
				$sender->sendMessage(TextFormat::GOLD . "=== frp 隧道状态 (服务端进程内) ===");
				foreach($mgr->getTunnels() as $name => $t){
					$pp = $t["proxyProtocolVersion"] !== "" ? "PROXY " . $t["proxyProtocolVersion"] : "无PROXY";
					$state = $t["ready"] ? "运行中" : ($t["state"] !== "stopped" ? "连接中(" . $t["state"] . ")" : "未启动");
					$runId = $t["runId"] !== "" ? " run_id=" . $t["runId"] : "";
					$sender->sendMessage(TextFormat::WHITE . "[" . $name . "] " . TextFormat::GRAY . $pp . " | " . $state . $runId);
				}
				$sender->sendMessage(TextFormat::GOLD . "用法: /frp status|restart|stop");
				return true;
		}
	}
}
