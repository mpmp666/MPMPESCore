<?php
/** Real generator integration: source base masks, source city placement, clipping, chunk order. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH',dirname(__DIR__).'/');
define('pocketmine\\DEBUG',0);
define('ENDIANNESS',1);
define('INT32_MASK',0xffffffff);
spl_autoload_register(static function(string $c):void{$p=\pocketmine\PATH.'src/'.str_replace('\\','/',$c).'.php';if(is_file($p)){require_once$p;}});
use pocketmine\block\Block;
use pocketmine\level\SimpleChunkManager;
use pocketmine\level\format\mcregion\Chunk;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\ender\EndCityGenerator;
use pocketmine\level\generator\ender\EndFeatures;
use pocketmine\level\generator\structure\StructureLocator;
use pocketmine\level\generator\structure\StructurePlacer;
use pocketmine\level\generator\structure\StructureRepository;
use pocketmine\utils\Random;
function endCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
Block::init();Biome::init();$started=microtime(true);$seed=1794737014;
$manager=new SimpleChunkManager($seed);$end=new Ender();$end->init($manager,new Random($seed));
$noise=$end->terrainNoise();$features=new EndFeatures($seed,$noise);
$engine=$end->structureEngine($manager);$locator=new StructureLocator($engine,$seed,'end');
$located=$locator->locate('end_city',1200,1200);
endCheck($located['complete']&&$located['plan']!==null,'No proven source city');$city=$located['plan'];
$coordinates=[[0,0],[-1,-1],[6,0],[6,-1],[($city['x']>>4),($city['z']>>4)]];
foreach($features->getSpikes() as $spike){$coordinates[]=[($spike['centerX']>>4),($spike['centerZ']>>4)];}
$coordinates=array_values(array_unique(array_map(static fn($v)=>implode(':',$v),$coordinates)));
$baseDigests=[];$populatedDigests=[];$allClipped=0;
foreach($coordinates as $coordinate){
 [$cx,$cz]=array_map('intval',explode(':',$coordinate));$chunk=Chunk::getEmptyChunk($cx,$cz);$manager->setChunk($cx,$cz,$chunk);
 $end->generateChunk($cx,$cz);$grid=$noise->chunkGrid($cx,$cz);
 for($x=0;$x<16;++$x){for($z=0;$z<16;++$z){for($y=0;$y<128;++$y){
  endCheck($chunk->getBlockId($x,$y,$z)===($grid->solid($x,$y,$z)?Block::END_STONE:0),'Base mask mismatch '.$coordinate.':'.$x.':'.$y.':'.$z);
 }}}
 $baseDigests[$coordinate]=hash('sha256',$chunk->getBlockIdArray());
 $end->populateChunk($cx,$cz);$metadata=$end->populateStructures($manager,$cx,$cz);
 $populatedDigests[$coordinate]=hash('sha256',$chunk->getBlockIdArray().$chunk->getBlockDataArray());
 $copy=Chunk::fromFastBinary($chunk->toFastBinary());
 endCheck($copy!==null&&$copy->getBlockIdArray()===$chunk->getBlockIdArray(),'End chunk serialization mismatch');
 $manager->cleanChunks();
}
// Each chunk is self-contained: cache and feature reconstruction may not depend on visit order.
$reverseManager=new SimpleChunkManager($seed);$reverse=new Ender();$reverse->init($reverseManager,new Random($seed));
foreach(array_reverse($coordinates) as $coordinate){
 [$cx,$cz]=array_map('intval',explode(':',$coordinate));$chunk=Chunk::getEmptyChunk($cx,$cz);$reverseManager->setChunk($cx,$cz,$chunk);
 $reverse->generateChunk($cx,$cz);$reverse->populateChunk($cx,$cz);$reverse->populateStructures($reverseManager,$cx,$cz);
 endCheck(hash('sha256',$chunk->getBlockIdArray().$chunk->getBlockDataArray())===$populatedDigests[$coordinate],'Chunk order changed End '.$coordinate);
 $reverseManager->cleanChunks();
}
// Every piece at the real source city is discoverable even beyond the former 112-block cap.
$b=$city['bounds'];$cityChunks=0;
for($cx=($city['x']+$b[0])>>4;$cx<=($city['x']+$b[3])>>4;++$cx){for($cz=($city['z']+$b[2])>>4;$cz<=($city['z']+$b[5])>>4;++$cz){
 $plans=$engine->forChunk($cx,$cz);$found=false;
 foreach($plans as $plan){if($plan['x']===$city['x']&&$plan['z']===$city['z']){$found=true;break;}}
 endCheck($found,'Missing crossing-city chunk '.$cx.':'.$cz);++$cityChunks;
}}
// A known source-tall layout is never squashed or translated; raw clipping is measured.
$tall=(new \pocketmine\level\generator\structure\EndCityLayout(244))->build();$tallBounds=StructureRepository::bounds($tall);
for($cx=$tallBounds[0]>>4;$cx<=$tallBounds[3]>>4;++$cx){for($cz=$tallBounds[2]>>4;$cz<=$tallBounds[5]>>4;++$cz){
 $r=StructurePlacer::chunk(Chunk::getEmptyChunk($cx,$cz),$tall,0,60,0,false);$allClipped+=$r['clipped'];
}}
endCheck($allClipped>0,'Tall source layout did not disclose clipping');
$receipt=['ok'=>true,'seed'=>$seed,'sourceVersion'=>'26.2','baseChunks'=>count($coordinates),'reverseOrder'=>true,
 'city'=>['anchor'=>[$city['x'],$city['y'],$city['z']],'pieces'=>count($city['pieces']),'chunks'=>$cityChunks,'sourceBounds'=>$city['bounds'],'heightClipped'=>$city['height_clipped']],
 'tallLayout'=>['seed'=>244,'height'=>$tallBounds[4]-$tallBounds[1]+1,'outOfRangeWrites'=>$allClipped],
 'seconds'=>round(microtime(true)-$started,3),'peakMiB'=>round(memory_get_peak_usage(true)/1048576,1)];
echo json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
