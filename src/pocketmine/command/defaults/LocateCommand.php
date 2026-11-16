<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\level\generator\structure\StructureLocateTask;
use pocketmine\level\generator\structure\StructureLocator;
use pocketmine\utils\TextFormat;

/** Standalone /locate, including modern /locate structure <name> syntax. */
final class LocateCommand extends VanillaCommand{
	public function __construct(string $name){
		parent::__construct($name, 'Locate the nearest natural structure', '/locate [structure] <name> [x z]');
		$this->setPermission('pocketmine.command.locate');
	}

	private static function coordinate(string $input) : ?int{
		if(!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $input)){ return null; }
		$value = (float) $input;
		if(!is_finite($value) || $value < self::MIN_COORD || $value > self::MAX_COORD){ return null; }
		return (int) floor($value);
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		if(!$this->testPermission($sender)){ return true; }
		if(isset($args[0]) && strtolower($args[0]) === 'biome'){
			$sender->sendMessage(TextFormat::RED.'此核心不支持生物群系定位；用法：'.$this->getUsage());
			return false;
		}
		$offset = isset($args[0]) && strtolower($args[0]) === 'structure' ? 1 : 0;
		$count = count($args) - $offset;
		if($count !== 1 && $count !== 3){
			$sender->sendMessage(TextFormat::RED.'用法：'.$this->getUsage().'；可选坐标必须同时提供 x 和 z。');
			return false;
		}
		$level = $sender instanceof Player ? $sender->getLevel() : $sender->getServer()->getDefaultLevel();
		if($level === null){
			$sender->sendMessage(TextFormat::RED.'没有可用的默认世界，无法定位建筑。');
			return false;
		}
		if($count === 3){
			$x = self::coordinate($args[$offset + 1]);
			$z = self::coordinate($args[$offset + 2]);
			if($x === null || $z === null){
				$sender->sendMessage(TextFormat::RED.'无效坐标：x 和 z 必须是 -30000000 到 30000000 之间的有限数字。');
				return false;
			}
		}elseif($sender instanceof Player){
			$x = $sender->getFloorX(); $z = $sender->getFloorZ();
		}else{
			$spawn = $level->getSpawnLocation();
			$x = $spawn->getFloorX(); $z = $spawn->getFloorZ();
		}
		$settings = $sender->getServer()->getAdvancedProperty('structures', []);
		$settings = is_array($settings) ? $settings : [];
		$dimension = StructureLocator::dimension($level->getProvider()->getGenerator());
		$error = StructureLocator::requestError($args[$offset], $dimension, $settings);
		if($error !== null){
			$sender->sendMessage(TextFormat::RED.$error);
			return false;
		}
		$request = StructureLocator::normalize($args[$offset]);
		$name = $request['family'].($request['variant'] !== '' ? '_'.$request['variant'] : '');
		$sender->sendMessage('正在从 '.$x.' '.$z.' 后台搜索最近的 '.$name.'，请稍候。');
		$sender->getServer()->getScheduler()->scheduleAsyncTask(new StructureLocateTask(
			$level->getId(), $name, $x, $z, $sender instanceof Player ? $sender->getName() : '', true
		));
		return true;
	}
}
