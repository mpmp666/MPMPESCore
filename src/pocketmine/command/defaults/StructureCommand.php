<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\level\generator\structure\LegacyStructures;
use pocketmine\level\generator\structure\NaturalStructures;
use pocketmine\level\generator\structure\StructureAssembler;
use pocketmine\level\generator\structure\StructureLocateTask;
use pocketmine\level\generator\structure\StructurePlacementTask;
use pocketmine\level\generator\structure\StructureRepository;
use pocketmine\level\generator\structure\StructureService;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;

final class StructureCommand extends VanillaCommand{
	public function __construct(string $name){
		parent::__construct($name,'Place, list, or locate imported vanilla buildings','/structure <list|place|locate> [family] [x y z] [variant]');
		$this->setPermission('pocketmine.command.structure');
	}

	public function execute(CommandSender $sender,$currentAlias,array $args){
		if(!$this->testPermission($sender)){return true;}
		$action=strtolower($args[0]??'list');
		if($action==='list'){
			$families=StructureService::families(); sort($families,SORT_STRING);
			$sender->sendMessage('建筑系列（'.count($families).'）：'.implode(', ',$families));
			$sender->sendMessage('村庄子型：plains, desert, savanna, snowy, taiga。手动放置示例：/structure place village 100 70 100 snowy');
			return true;
		}
		if($action==='locate'){
			if(!isset($args[1])){$sender->sendMessage(TextFormat::RED.'/structure locate <family> [x z]');return false;}
			if($sender instanceof Player){$x=$sender->getFloorX();$z=$sender->getFloorZ();}elseif(isset($args[2],$args[3])&&is_numeric($args[2])&&is_numeric($args[3])){$x=(int)$args[2];$z=(int)$args[3];}else{$sender->sendMessage(TextFormat::RED.'控制台用法：/structure locate <family> <x> <z>');return false;}
			$sender->sendMessage('正在后台搜索附近的 '.$args[1].'，可能需要几秒。');
			$sender->getServer()->getScheduler()->scheduleAsyncTask(new StructureLocateTask(($sender instanceof Player?$sender->getLevel():$sender->getServer()->getDefaultLevel())->getId(),$args[1],$x,$z,$sender instanceof Player?$sender->getName():''));
			return true;
		}
		if($action!=='place'||!isset($args[1])){$sender->sendMessage(TextFormat::RED.'/structure <list|place|locate> [family] [x y z] [variant]');return false;}
		$family=str_replace('minecraft:','',strtolower($args[1]));
		if(!StructureRepository::has($family)&&!in_array($family,LegacyStructures::families(),true)&&!isset(StructureRepository::manifest()['families'][$family])&&!in_array($family,['mansion','outpost','bastion_remnant','endcity','ocean_ruin','ocean_ruins'],true)){$sender->sendMessage(TextFormat::RED.'未知建筑系列。使用 /structure list 查看支持列表。');return false;}
		$level=$sender instanceof Player?$sender->getLevel():$sender->getServer()->getDefaultLevel();
		if(isset($args[2],$args[3],$args[4])&&is_numeric($args[2])&&is_numeric($args[3])&&is_numeric($args[4])){[$x,$y,$z]=[(int)$args[2],(int)$args[3],(int)$args[4]];}
		elseif($sender instanceof Player){[$x,$y,$z]=[$sender->getFloorX(),max(2,$sender->getFloorY()),$sender->getFloorZ()];}
		else{$sender->sendMessage(TextFormat::RED.'控制台用法：/structure place <family> <x> <y> <z> [variant]');return false;}
		if($y<2||$y>126){$sender->sendMessage(TextFormat::RED.'建筑基准高度需在 2 到 126 之间。');return false;}
		try{
			$variant=(string)($args[5]??'');
			$pieces=StructureService::pieces($family,($level->getSeed() ^ ($x<<16) ^ $z),$variant);
			$bounds=StructureRepository::bounds($pieces);
			$minX=$x+$bounds[0];$maxX=$x+$bounds[3];$minZ=$z+$bounds[2];$maxZ=$z+$bounds[5];
			$minY=$y+$bounds[1];$maxY=$y+$bounds[4];
			if($maxX-$minX>255||$maxZ-$minZ>255||$maxY>126||$minY<1){throw new \RuntimeException('结构在指定坐标超出旧版世界高度/范围；请换一个 y 坐标或更小模板。');}
			$chunks=[];
			for($cx=$minX>>4;$cx<=$maxX>>4;++$cx){
				for($cz=$minZ>>4;$cz<=$maxZ>>4;++$cz){$chunks[]=[$cx,$cz];}
			}
			$sender->getServer()->getScheduler()->scheduleRepeatingTask(new StructurePlacementTask($level->getId(),$pieces,$x,$y,$z,$chunks,$sender instanceof Player?$sender->getName():''),1);
			$sender->sendMessage('已排入 '.$family.' 放置任务：'.count($pieces).' 个片段、'.count($chunks).' 个区块，正在逐步生成。');
			return true;
		}catch(\Throwable $e){$sender->sendMessage(TextFormat::RED.'建筑放置失败：'.$e->getMessage());return false;}
	}
}
