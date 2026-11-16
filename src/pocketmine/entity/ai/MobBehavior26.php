<?php

namespace pocketmine\entity\ai;

use pocketmine\entity\Creature;
use pocketmine\entity\Human;

/**
 * Explicit old-protocol profiles, informed by the supplied Minecraft 26.2 goals.
 * Speeds are per-tick motor inputs, not Java attribute values. Names absent from
 * this table (including plugin NPCs, lightning and humans) are deliberately idle.
 */
final class MobBehavior26{
	const MAX_RANGE = 64;
	const SCAN_INTERVAL = 10;
	const GOAL_PRIORITIES = ['float' => 0, 'panic' => 1, 'combat' => 2, 'breed' => 3, 'tempt' => 4, 'follow_parent' => 5, 'avoid' => 6, 'wander' => 7, 'look' => 8];

	private static $profiles;

	public static function all() : array{
		if(self::$profiles !== null){
			return self::$profiles;
		}
		$base = ['mode' => 'ground', 'speed' => 0.16, 'range' => 16, 'attack' => 'none', 'damage' => 0, 'cooldown' => 20,
			'reach' => 1.8, 'target' => 'none', 'monster' => false, 'panic' => false, 'breed' => false, 'tempt' => [], 'food' => [], 'sunlight' => false];
		$animal = ['panic' => true, 'breed' => true];
		$hostile = ['monster' => true, 'target' => 'player', 'attack' => 'melee', 'damage' => 3];
		$meat = [319, 320, 363, 364, 365, 366, 367, 411, 412, 423, 424];
		$entries = [
			'Cow' => $animal + ['food' => [296], 'tempt' => [296]],
			'Mooshroom' => $animal + ['food' => [296], 'tempt' => [296]],
			'Pig' => $animal + ['food' => [391, 392, 457], 'tempt' => [391, 392, 457, 398]],
			'Sheep' => $animal + ['food' => [296], 'tempt' => [296]],
			'Chicken' => $animal + ['food' => [295, 361, 362, 458], 'tempt' => [295, 361, 362, 458], 'speed' => 0.15],
			'Rabbit' => $animal + ['food' => [391, 396, 37], 'tempt' => [391, 396, 37], 'speed' => 0.22],
			'Wolf' => ['breed' => true, 'food' => $meat, 'tempt' => array_merge([352], $meat), 'target' => 'retaliate', 'attack' => 'melee', 'damage' => 4, 'speed' => 0.22],
			'Ocelot' => $animal + ['food' => [349, 460], 'tempt' => [349, 460], 'target' => 'chicken', 'attack' => 'melee', 'damage' => 3, 'speed' => 0.23],
			'Villager' => ['panic' => true, 'speed' => 0.17],
			'IronGolem' => ['target' => 'golem', 'attack' => 'melee', 'damage' => 10, 'reach' => 2.7, 'range' => 32, 'speed' => 0.20],
			'SnowGolem' => ['target' => 'snow_golem', 'attack' => 'snowball', 'range' => 16, 'reach' => 10.0, 'speed' => 0.16],
			'Zombie' => ['target' => 'zombie', 'sunlight' => true, 'speed' => 0.19, 'range' => 35] + $hostile,
			'ZombieVillager' => ['target' => 'zombie', 'sunlight' => true, 'speed' => 0.19, 'range' => 35] + $hostile,
			'PigZombie' => ['target' => 'retaliate', 'speed' => 0.23, 'damage' => 5] + $hostile,
			'Skeleton' => ['attack' => 'arrow', 'sunlight' => true, 'reach' => 15.0, 'cooldown' => 40, 'speed' => 0.20] + $hostile,
			'Spider' => ['target' => 'spider', 'speed' => 0.23, 'damage' => 2, 'reach' => 2.0] + $hostile,
			'CaveSpider' => ['target' => 'spider', 'speed' => 0.23, 'damage' => 2, 'reach' => 1.5] + $hostile,
			'Creeper' => ['attack' => 'swell', 'reach' => 3.0, 'speed' => 0.19] + $hostile,
			'Slime' => ['speed' => 0.18, 'damage' => 2] + $hostile,
			'LavaSlime' => ['speed' => 0.20, 'damage' => 3] + $hostile,
			'Silverfish' => ['speed' => 0.20, 'damage' => 1, 'reach' => 1.3] + $hostile,
			'Enderman' => ['target' => 'stare', 'damage' => 7, 'speed' => 0.27, 'range' => 64, 'reach' => 2.5] + $hostile,
			'Blaze' => ['mode' => 'flying', 'attack' => 'blaze', 'range' => 48, 'reach' => 48.0, 'speed' => 0.18, 'damage' => 6] + $hostile,
			'Ghast' => ['mode' => 'flying', 'attack' => 'ghast', 'range' => 64, 'reach' => 64.0, 'speed' => 0.17, 'cooldown' => 60] + $hostile,
			'Bat' => ['mode' => 'flying', 'speed' => 0.18, 'range' => 8, 'panic' => true],
			'Squid' => ['mode' => 'swimming', 'speed' => 0.10, 'range' => 12, 'panic' => true],
		];
		self::$profiles = [];
		foreach($entries as $name => $profile){
			self::$profiles[$name] = $profile + $base;
		}
		return self::$profiles;
	}

	public static function name(Creature $mob) : string{
		if($mob instanceof Human){
			return '';
		}
		$profiles = self::all();
		$class = get_class($mob);
		do{
			$offset = strrpos($class, '\\');
			$name = $offset === false ? $class : substr($class, $offset + 1);
			if(isset($profiles[$name]) && $class === 'pocketmine\\entity\\' . $name){
				return $name;
			}
		}while($class = get_parent_class($class));
		return '';
	}

	public static function profile(Creature $mob){
		$profiles = self::all();
		$name = self::name($mob);
		return isset($profiles[$name]) ? $profiles[$name] : null;
	}

	public static function isMonster(Creature $mob) : bool{
		$profile = self::profile($mob);
		return $profile !== null && $profile['monster'];
	}

	public static function acceptsFood(string $name, int $id) : bool{
		$profiles = self::all();
		return isset($profiles[$name]) && in_array($id, $profiles[$name]['food'], true);
	}
}
