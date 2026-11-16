<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;

/** Source mobs that have a registered pre-0.16 entity equivalent. */
final class StructureEntities{
	private const TYPES=[
		'villager'=>'Villager','zombie_villager'=>'ZombieVillager','zombie'=>'Zombie','skeleton'=>'Skeleton',
		'spider'=>'Spider','cave_spider'=>'CaveSpider','silverfish'=>'Silverfish','pig'=>'Pig','cow'=>'Cow',
		'sheep'=>'Sheep','iron_golem'=>'IronGolem','cat'=>'Ocelot','ocelot'=>'Ocelot'
	];

	public static function apply(Level $level,array $entities) : int{
		$spawned=0;
		foreach($entities as $entry){
			$type=str_replace('minecraft:','',(string)($entry['nbt']['id']??$entry['id']??''));
			$class=self::TYPES[$type]??null;
			if($class===null || $spawned>=32){continue;}
			[$x,$y,$z]=$entry['pos'];
			if($y<1 || $y>125){continue;}
			$chunk=$level->getChunk(((int)floor($x))>>4,((int)floor($z))>>4,false);
			if($chunk===null){continue;}
			$pos=new ListTag('Pos',[new DoubleTag('', $x),new DoubleTag('', $y),new DoubleTag('', $z)]);
			$pos->setTagType(NBT::TAG_Double);
			$motion=new ListTag('Motion',[new DoubleTag('',0),new DoubleTag('',0),new DoubleTag('',0)]);
			$motion->setTagType(NBT::TAG_Double);
			$rotation=new ListTag('Rotation',[new FloatTag('',(float)($entry['yaw']??0)),new FloatTag('',0)]);
			$rotation->setTagType(NBT::TAG_Float);
			$nbt=new CompoundTag('',[
				'id'=>new \pocketmine\nbt\tag\StringTag('id',$class),
				'Pos'=>$pos,'Motion'=>$motion,'Rotation'=>$rotation,
				'PersistenceRequired'=>new \pocketmine\nbt\tag\ByteTag('PersistenceRequired',1)
			]);
			if($type==='villager'){$nbt->Profession=new \pocketmine\nbt\tag\ByteTag('Profession',0);}
			if($type==='zombie_villager'){$nbt->IsVillager=new \pocketmine\nbt\tag\ByteTag('IsVillager',1);}
			if(($entity=Entity::createEntity($class,$chunk,$nbt)) instanceof Entity){$entity->spawnToAll();++$spawned;}
		}
		return $spawned;
	}
}
