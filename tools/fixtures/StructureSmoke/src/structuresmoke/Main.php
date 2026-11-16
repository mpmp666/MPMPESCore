<?php
namespace structuresmoke;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\level\generator\structure\StructureRepository as Repository;
use pocketmine\level\generator\structure\StructureService as Service;
use pocketmine\level\generator\structure\StructurePlacer as Placer;
use pocketmine\level\generator\structure\StructureTiles as Tiles;
use pocketmine\level\generator\structure\StructureEntities as Entities;
use pocketmine\math\Vector3;

class Main extends PluginBase{
 public function onEnable(){ $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new Smoke($this),20,1); }
}
class Smoke extends Task{
 private $plugin; private $stage=0; private $started=0; private $plan; private $queue=[]; private $all=[]; private $tileCount=0; private $chests=0; private $items=0; private $entities=0;
 public function __construct($plugin){$this->plugin=$plugin;}
 private function check($yes,$message){if(!$yes)throw new \RuntimeException($message);}
 public function onRun($tick){
  $s=$this->plugin->getServer();$l=$s->getDefaultLevel();
  try{
   if($this->stage===0){
    $this->started=$tick;
    $s->dispatchCommand(new ConsoleCommandSender(),'structure list');
    $this->plan=Service::engine($l)->locate('village',0,0,3);
    $this->check($this->plan!==null,'No natural village');
    $s->getLogger()->info('[STRUCTURE-TEST] natural plan '.json_encode([$this->plan['x'],$this->plan['y'],$this->plan['z']]));
    $this->stage=1; return;
   }
   if($this->stage===1){
    $cx=$this->plan['x']>>4;$cz=$this->plan['z']>>4;
    if(!$l->populateChunk($cx,$cz,true)){if($tick-$this->started>2000)throw new \RuntimeException('Natural population timed out');return;}
    $chunk=$l->getChunk($cx,$cz);
    $result=Placer::chunk(clone $chunk,$this->plan['pieces'],$this->plan['x'],$this->plan['y'],$this->plan['z'],true);
    $this->check($result['blocks']>0,'Natural root has no records');
    $s->getLogger()->info('[STRUCTURE-TEST] natural async population complete');
    $families=['village','pillager_outpost','end_city','woodland_mansion','ancient_city','trial_chambers','bastion','shipwreck','underwater_ruin','igloo','jungle_temple','desert_pyramid','stronghold','nether_fortress'];
    foreach($families as $index=>$f){
     $pieces=Service::pieces($f,42);$b=Repository::bounds($pieces);$x=16000+$index*256;$z=16000;$y=3-$b[1];
     for($cx=($x+$b[0])>>4;$cx<=($x+$b[3])>>4;++$cx){for($cz=($z+$b[2])>>4;$cz<=($z+$b[5])>>4;++$cz){$this->queue[]=[$f,$pieces,$x,$y,$z,$cx,$cz];}}
    }
    $this->stage=2;return;
   }
   if($this->stage===2){
    if($this->queue!==[]){
     [$f,$pieces,$x,$y,$z,$cx,$cz]=array_shift($this->queue);
     $chunk=$l->getChunk($cx,$cz,true);$chunk->setGenerated();$chunk->setPopulated();
     $r=Placer::chunk($chunk,$pieces,$x,$y,$z);
     $l->setChunk($cx,$cz,$chunk,false);
     $this->tileCount+=Tiles::apply($l,$r['tiles']);
     $this->entities+=Entities::apply($l,$r['entities']);
     foreach($chunk->getTiles() as $tile){
      $this->check($tile->chunk===$chunk,'Tile wrong chunk');
      if($tile instanceof \pocketmine\tile\Chest){++$this->chests;$this->items+=count($tile->getInventory()->getContents());}
     }
     $chunk->recalculateHeightMap();$l->getProvider()->saveChunk($cx,$cz);
     $this->all[]=[$cx,$cz];
     if(count($this->queue)%25===0){$s->getLogger()->info('[STRUCTURE-TEST] remaining '.count($this->queue).', tiles '.$this->tileCount);}
     return;
    }
    $this->check($this->tileCount>20,'Too few tile entities');$this->check($this->chests>10,'Too few chests');$this->check($this->items>5,'Loot missing');
    foreach($this->all as [$cx,$cz]){$l->unloadChunk($cx,$cz,false,true);}
    $s->getLogger()->info('[STRUCTURE-TEST] saved all structure chunks; reloading');
    $this->stage=3;return;
   }
   if($this->stage===3){
    $tiles=0;$items=0;
    foreach($this->all as [$cx,$cz]){
     $chunk=$l->getChunk($cx,$cz,false);$this->check($chunk!==null,'Missing saved chunk');
     foreach($chunk->getTiles() as $tile){++$tiles;if($tile instanceof \pocketmine\tile\Chest){$items+=count($tile->getInventory()->getContents());}}
    }
    $this->check($tiles>20&&$items>5,'Tiles/loot lost on reload');
    $receipt=['ok'=>true,'chunks'=>count($this->all),'tiles'=>$tiles,'chests'=>$this->chests,'loot_stacks'=>$items,'entities_spawned'=>$this->entities,'natural'=>[$this->plan['x'],$this->plan['y'],$this->plan['z']]];
    file_put_contents($s->getDataPath().'structure-test-result.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $s->getLogger()->info('[STRUCTURE-TEST] PASS '.json_encode($receipt));
    $s->getScheduler()->cancelTask($this->getTaskId());$s->shutdown();
   }
  }catch(\Throwable $e){file_put_contents($s->getDataPath().'structure-test-result.json',json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_PRETTY_PRINT));$s->getLogger()->critical('[STRUCTURE-TEST] FAIL '.$e->getMessage());$s->getScheduler()->cancelTask($this->getTaskId());$s->shutdown();}
 }
}
