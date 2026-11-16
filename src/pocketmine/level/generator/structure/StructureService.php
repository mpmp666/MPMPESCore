<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\Level;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\hell\Nether;
use pocketmine\level\generator\normal\Normal;
use pocketmine\utils\Random;

final class StructureService{
	public static function families() : array{
		return array_values(array_unique(array_merge(array_keys(StructureRepository::manifest()['families']), LegacyStructures::families())));
	}

	public static function pieces(string $family, int $seed, string $variant = '') : array{
		$family=StructureAssembler::key(strtolower(trim($family)));
		$aliases=['mansion'=>'woodland_mansion','outpost'=>'pillager_outpost','bastion_remnant'=>'bastion','endcity'=>'end_city','ocean_ruin'=>'underwater_ruin','ocean_ruins'=>'underwater_ruin'];
		$family=$aliases[$family]??$family;
		if(in_array($family, LegacyStructures::families(), true)){ return [['template'=>$family,'x'=>0,'y'=>0,'z'=>0,'rotation'=>0]]; }
		if(StructureRepository::has($family)) { return [['template'=>$family,'x'=>0,'y'=>0,'z'=>0,'rotation'=>0]]; }
		return StructureAssembler::build($family,$seed,$variant);
	}

	public static function engine(Level $level) : NaturalStructures{
		$name = strtolower($level->getProvider()->getGenerator());
		$class = match($name){'ender'=>Ender::class,'nether','hell'=>Nether::class,default=>Normal::class};
		$options=['structure-settings'=>$level->getServer()->getAdvancedProperty('structures',[])];
		$generator=new $class($options);
		$generator->init($level,new Random($level->getSeed()));
		return $generator->structureEngine($level);
	}
}
