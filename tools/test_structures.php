<?php
/** CLI integration regression: real legacy chunks and generators, without a server. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH', dirname(__DIR__) . '/');
define('pocketmine\\DEBUG', 0);
define('ENDIANNESS', 1);
define('INT32_MASK', 0xffffffff);
spl_autoload_register(static function(string $class):void{
	$path=\pocketmine\PATH.'src/'.str_replace('\\','/',$class).'.php';
	if(is_file($path)){require_once $path;}
});
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\SimpleChunkManager;
use pocketmine\level\format\mcregion\Chunk;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\normal\Normal;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\structure\StructureRepository as Repository;
use pocketmine\level\generator\structure\StructureService as Service;
use pocketmine\level\generator\structure\StructurePlacer as Placer;
use pocketmine\level\generator\structure\StructureTiles as Tiles;
use pocketmine\utils\Random;
function check(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
Block::init(); Biome::init(); Item::init();
$start=microtime(true);
foreach(['village','pillager_outpost','end_city','woodland_mansion','ancient_city','trial_chambers','trail_ruins','bastion','shipwreck','underwater_ruin','igloo','stronghold','mineshaft','desert_pyramid','nether_fortress','ocean_monument','jungle_temple','swamp_hut','buried_treasure','end_exit_portal','end_gateway','desert_well','bonus_dungeon','fossil','nether_fossils','ruined_portal','spring'] as $family){
	$pieces=Service::pieces($family,42);
	check($pieces!==[],"Empty $family");
	check($pieces===Service::pieces($family,42),"Nondeterministic $family");
	$bounds=Repository::bounds($pieces);
	$y=2-$bounds[1];
	check($y+$bounds[4]<127,"Height overflow $family");
	$blocks=0;
	for($cx=(-19+$bounds[0])>>4;$cx<=(-19+$bounds[3])>>4;++$cx){
		for($cz=(-21+$bounds[2])>>4;$cz<=(-21+$bounds[5])>>4;++$cz){
			$chunk=Chunk::getEmptyChunk($cx,$cz);
			$result=Placer::chunk($chunk,$pieces,-19,$y,-21);
			$blocks+=$result['blocks'];
			foreach($result['tiles'] as $tile){check(($tile['pos'][0]>>4)===$cx&&($tile['pos'][2]>>4)===$cz,"Tile escaped $family");}
		}
	}
	check($blocks>20,"Missing geometry $family");
	echo "$family: ".count($pieces)." pieces, $blocks records, height ".($bounds[4]-$bounds[1]+1)."\n";
}
// Every chest loot entry must survive item lookup without throwing on unknown Java IDs.
$loot=json_decode(file_get_contents(Repository::directory().'loot_tables.json'),true,512,JSON_THROW_ON_ERROR);
$lootMethod=new ReflectionMethod(Tiles::class,'loot');
foreach($loot as $name=>$table){
	if(!str_starts_with($name,'minecraft:chests/')){continue;}
	foreach($table['pools']??[] as $pool){foreach($pool['entries']??[] as $entry){if(($entry['type']??'')==='minecraft:item'){Tiles::item($entry['name']);}}}
	$items=$lootMethod->invoke(null,$name,42);
	check(count($items)<=27,"Loot overflow $name");
}
$manager=new SimpleChunkManager(123456);
$generator=new Normal();$generator->init($manager,new Random(123456));
$engine=$generator->structureEngine($manager);
$plan=$engine->locate('village',0,0,2);
check($plan!==null,'Could not locate a village for reference seed');
$again=$engine->locate('village',0,0,2);
check($plan===$again,'Locate is not deterministic');
$chunkX=$plan['x']>>4;$chunkZ=$plan['z']>>4;
$manager->setChunk($chunkX,$chunkZ,Chunk::getEmptyChunk($chunkX,$chunkZ));
$generator->generateChunk($chunkX,$chunkZ);
$before=$manager->getChunk($chunkX,$chunkZ)->getBlockIdArray();
$metadata=$generator->populateStructures($manager,$chunkX,$chunkZ);
check($before!==$manager->getChunk($chunkX,$chunkZ)->getBlockIdArray(),'Natural village did not place blocks');
echo 'Natural village: '.json_encode([$plan['x'],$plan['y'],$plan['z'],count($plan['pieces'])])."\n";
$endManager=new SimpleChunkManager(123456);$end=new Ender();$end->init($endManager,new Random(123456));
$city=$end->structureEngine($endManager)->locate('end_city',700,700,2);
check($city!==null,'Could not locate End City');
check($city['y']>=60,'End City did not use source terrain threshold');
check($city['height_clipped']===($city['y']+$city['bounds'][4]>127||$city['y']+$city['bounds'][1]<0),'End City clipping declaration inconsistent');
echo 'End City: '.json_encode([$city['x'],$city['y'],$city['z'],count($city['pieces'])])."\n";
echo 'PASS '.round(microtime(true)-$start,3).'s, peak '.round(memory_get_peak_usage(true)/1048576,1)."MiB\n";
