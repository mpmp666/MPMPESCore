<?php

namespace pocketmine\entity\ai;

use pocketmine\entity\Creature;
use pocketmine\nbt\tag\CompoundTag;

/** Source EntityTypes dimensions and supported species' base health (26.2). */
final class MobAttributes26{
	/** [width,height,eyeHeight,maxHealth,fireImmune]. Dimensions precede Entity's AABB. */
	private const VALUES = [
		'Cow'=>[0.9,1.4,1.3,10,false], 'Mooshroom'=>[0.9,1.4,1.3,10,false],
		'Pig'=>[0.9,0.9,0.765,10,false], 'Sheep'=>[0.9,1.3,1.235,8,false],
		'Chicken'=>[0.4,0.7,0.644,4,false], 'Rabbit'=>[0.49,0.6,0.59,3,false],
		'Wolf'=>[0.6,0.85,0.68,8,false], 'Ocelot'=>[0.6,0.7,0.595,10,false],
		'Villager'=>[0.6,1.95,1.62,20,false], 'IronGolem'=>[1.4,2.7,2.295,100,false],
		'SnowGolem'=>[0.7,1.9,1.7,4,false], 'Zombie'=>[0.6,1.95,1.74,20,false],
		'ZombieVillager'=>[0.6,1.95,1.74,20,false], 'PigZombie'=>[0.6,1.95,1.79,20,true],
		'Skeleton'=>[0.6,1.99,1.74,20,false], 'Spider'=>[1.4,0.9,0.65,16,false],
		'CaveSpider'=>[0.7,0.5,0.45,12,false], 'Creeper'=>[0.6,1.7,1.445,20,false],
		'Slime'=>[0.52,0.52,0.325,1,false], 'LavaSlime'=>[0.52,0.52,0.325,1,true],
		'Silverfish'=>[0.4,0.3,0.13,8,false], 'Enderman'=>[0.6,2.9,2.55,40,false],
		'Blaze'=>[0.6,1.8,1.53,20,true], 'Ghast'=>[4.0,4.0,2.6,10,true],
		'Bat'=>[0.5,0.9,0.45,6,false], 'Squid'=>[0.8,0.8,0.4,10,false],
	];

	public static function values(Creature $mob, CompoundTag $nbt, ?bool $baby = null) : ?array{
		$name=MobBehavior26::name($mob);
		if(!isset(self::VALUES[$name])){return null;}
		$value=self::VALUES[$name];
		if($name==='Slime'||$name==='LavaSlime'){
			$size=max(1,min(127,(int)($nbt['Size']??0)+1));
			$value[0]*=$size;$value[1]*=$size;$value[2]*=$size;$value[3]=$size*$size;
		}elseif($name==='Wolf'&&((string)($nbt['OwnerName26']??'')!==''||(string)($nbt['OwnerName']??'')!==''||(string)($nbt['Owner']??'')!=='')){
			$value[3]=40;
		}
		if($baby===null){$baby=(int)($nbt['Age']??0)<0||(bool)($nbt['IsBaby']??false);}
		if($baby&&$name!=='Slime'&&$name!=='LavaSlime'){
			$special=['Cow'=>[0.45,0.7,0.665],'Mooshroom'=>[0.45,0.7,0.665],'Chicken'=>[0.3,0.4,0.2975],'Wolf'=>[0.3,0.425,0.34],'Rabbit'=>[0.24,0.4,0.39],'Squid'=>[0.5,0.5,0.37]];
			if(isset($special[$name])){[$value[0],$value[1],$value[2]]=$special[$name];}
			else{$value[0]*=0.5;$value[1]*=0.5;$value[2]*=0.5;}
		}
		return $value;
	}
}
