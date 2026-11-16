<?php

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\Player;

/** Parses /tp without loading worlds or moving players. */
final class TeleportArguments{

	/** @throws \InvalidArgumentException */
	public static function parse(CommandSender $sender, array $args){
		$count = count($args);
		if($count < 1 or $count > 8){
			throw new \InvalidArgumentException("Invalid teleport arguments.");
		}
		$server = $sender->getServer();
		$world = null;
		$destination = null;
		$coordinates = [];
		$fallback = false;

		// The explicit keyword also permits worlds named after an online player.
		if($count >= 2 and $args[0] === "world"){
			$target = self::selfTarget($sender);
			$world = $args[1];
			$coordinates = array_slice($args, 2);
		}elseif($count >= 3 and $args[1] === "world" and !self::isCoordinate($args[0])){
			$target = self::player($sender, $args[0]);
			$world = $args[2];
			$coordinates = array_slice($args, 3);
		}elseif($count === 1 or $count === 2){
			$target = $count === 1 ? self::selfTarget($sender) : self::player($sender, $args[0]);
			$name = $args[$count - 1];
			$destination = $server->getPlayer($name);
			if($destination === null){
				$world = $name;
				$fallback = true;
			}
		}elseif($count === 3 or ($count === 5 and self::isCoordinate($args[0]))){
			// Five arguments mean self x y z yaw pitch, never target x y z yaw.
			$target = self::selfTarget($sender);
			$coordinates = $args;
		}elseif($count === 4 or $count === 6){
			$target = $server->getPlayer($args[0]);
			if($target !== null){
				// Keep the original target x y z [yaw pitch] forms and name matching.
				$coordinates = array_slice($args, 1);
			}elseif($count === 4 and self::isCoordinate($args[0]) and !self::isCoordinate($args[3])){
				$target = self::selfTarget($sender);
				$coordinates = array_slice($args, 0, 3);
				$world = $args[3];
			}else{
				if(self::isCoordinate($args[0])){
					throw new \InvalidArgumentException("Can't find player " . $args[0] . "; use /tp world <world> for numeric world names.");
				}
				$target = self::selfTarget($sender);
				$world = $args[0];
				$coordinates = array_slice($args, 1);
				$fallback = true;
			}
		}elseif($count === 5 or $count === 7){
			$target = self::player($sender, $args[0]);
			if($count === 5 and self::isCoordinate($args[1])){
				$coordinates = array_slice($args, 1, 3);
				$world = $args[4];
			}else{
				$world = $args[1];
				$coordinates = array_slice($args, 2);
			}
		}else{
			throw new \InvalidArgumentException("Invalid teleport arguments.");
		}

		$size = count($coordinates);
		if(($world === null and $destination === null and $size === 0) or ($size !== 0 and $size !== 3 and $size !== 5)){
			throw new \InvalidArgumentException("Provide all three coordinates and, optionally, both yaw and pitch.");
		}
		$position = null;
		$yaw = $target->getYaw();
		$pitch = $target->getPitch();
		if($size !== 0){
			$position = [
				self::number($coordinates[0], $target->x, "x", -30000000, 30000000),
				self::number($coordinates[1], $target->y, "y", 0, 127),
				self::number($coordinates[2], $target->z, "z", -30000000, 30000000)
			];
			if($size === 5){
				$yaw = self::number($coordinates[3], $yaw, "yaw");
				$pitch = self::number($coordinates[4], $pitch, "pitch");
			}
		}
		return ["target" => $target, "destination" => $destination, "world" => $world,
			"coordinates" => $position, "yaw" => $yaw, "pitch" => $pitch, "fallback" => $fallback];
	}

	private static function selfTarget(CommandSender $sender){
		if(!($sender instanceof Player)){
			throw new \InvalidArgumentException("Please provide a player! Console usage: /tp <player> world <world> [x y z [yaw pitch]].");
		}
		return $sender;
	}

	private static function player(CommandSender $sender, $name){
		$player = $sender->getServer()->getPlayer($name);
		if($player === null){
			throw new \InvalidArgumentException("Can't find player " . $name);
		}
		return $player;
	}

	private static function isCoordinate($input){
		return is_string($input) and ($input === "~" or preg_match('/\A~?[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/', $input) === 1);
	}

	/** Strict finite decimal parsing, including relative coordinates and angles. */
	private static function number($input, $original, $name, $min = null, $max = null){
		if(!self::isCoordinate($input)){
			throw new \InvalidArgumentException("Invalid " . $name . " value: " . $input);
		}
		$relative = $input[0] === "~";
		$value = (float) ($relative ? substr($input, 1) : $input);
		if($relative){
			$value += $original;
		}
		if(!is_finite($value)){
			throw new \InvalidArgumentException("Invalid " . $name . " value: must be finite.");
		}
		if(($min !== null and $value < $min) or ($max !== null and $value > $max)){
			throw new \InvalidArgumentException($name . " must be between " . $min . " and " . $max . ".");
		}
		return $value;
	}
}
