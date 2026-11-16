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

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\TranslationContainer;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class TeleportCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"%pocketmine.command.tp.description",
			"/tp <player> | /tp <target> <destination> | /tp [target] <x> <y> <z> [yaw pitch] | /tp [target] world <world> [x y z [yaw pitch]]"
		);
		$this->setPermission("pocketmine.command.teleport");
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}

		try{
			$request = TeleportArguments::parse($sender, $args);
		}catch(\InvalidArgumentException $e){
			$sender->sendMessage(TextFormat::RED . $e->getMessage());
			$sender->sendMessage(new TranslationContainer("commands.generic.usage", [$this->usageMessage]));
			return true;
		}

		$target = $request["target"];
		$destination = $request["destination"];
		if($destination !== null){
			// Passing the player preserves the destination's level, yaw and pitch.
			if($target->teleport($destination) === false){
				$sender->sendMessage(TextFormat::RED . "Teleport failed for " . $target->getName() . ".");
			}else{
				Command::broadcastCommandMessage($sender, new TranslationContainer("commands.tp.success", [$target->getName(), $destination->getName()]));
			}
			return true;
		}

		$level = $target->getLevel();
		if($request["world"] !== null){
			$name = $request["world"];
			$server = $sender->getServer();
			$level = $server->getLevelByName($name);
			if($level === null){
				if(!$server->isLevelGenerated($name)){
					$sender->sendMessage(TextFormat::RED . ($request["fallback"] ? "Can't find online player or existing world " : "Can't find existing world ") . $name . ".");
					return true;
				}
				try{
					$loaded = $server->loadLevel($name);
				}catch(\Throwable $e){
					$loaded = false;
				}
				$level = $loaded ? $server->getLevelByName($name) : null;
				if($level === null){
					$sender->sendMessage(TextFormat::RED . "Could not load world " . $name . ".");
					return true;
				}
			}
		}
		if($level === null){
			$sender->sendMessage(TextFormat::RED . "Player " . $target->getName() . " has no world.");
			return true;
		}

		if($request["coordinates"] === null){
			$spawn = $level->getSafeSpawn();
			if(!($spawn instanceof Vector3) or !is_finite((float) $spawn->x) or !is_finite((float) $spawn->y) or !is_finite((float) $spawn->z)
				or abs($spawn->x) > self::MAX_COORD or abs($spawn->z) > self::MAX_COORD or $spawn->y < 0 or $spawn->y > 127){
				$sender->sendMessage(TextFormat::RED . "World " . $request["world"] . " has no valid safe spawn.");
				return true;
			}
			// getSafeSpawn may return a plain Vector3; always bind the destination level.
			$position = new Position($spawn->x, $spawn->y, $spawn->z, $level);
		}else{
			list($x, $y, $z) = $request["coordinates"];
			$position = new Position($x, $y, $z, $level);
		}
		// Player::teleport queues chunk delivery; do not wait or generate chunks here.
		if($target->teleport($position, $request["yaw"], $request["pitch"]) === false){
			$sender->sendMessage(TextFormat::RED . "Teleport failed for " . $target->getName() . ".");
			return true;
		}
		if($request["world"] !== null){
			Command::broadcastCommandMessage($sender, "Teleported " . $target->getName() . " to world " . $request["world"] . " at " . round($position->x, 2) . ", " . round($position->y, 2) . ", " . round($position->z, 2) . ".");
		}else{
			Command::broadcastCommandMessage($sender, new TranslationContainer("commands.tp.success.coordinates", [$target->getName(), round($position->x, 2), round($position->y, 2), round($position->z, 2)]));
		}
		return true;
	}
}
