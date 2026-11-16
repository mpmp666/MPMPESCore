<?php
namespace aienchantsmoke;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\entity\Entity;
use pocketmine\entity\Creature;
use pocketmine\entity\ai\MobBehavior26;
use pocketmine\entity\ai\VanillaAI26;
use pocketmine\entity\ai\Navigation26;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\EnchantmentEffects26;
use pocketmine\inventory\AnvilInventory;
use pocketmine\inventory\EnchantInventory;
use pocketmine\level\format\mcregion\Chunk;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\tile\EnchantTable;

class Main extends PluginBase{
 public function onEnable(){ $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new Verify($this),40,1); }
}
class Verify extends Task{
 private $plugin; private $stage=0; private $started; private $mobs=[]; private $zombie; private $villager; private $neutral; private $cow; private $wallA; private $wallB; private $age; private $checks=0; private $covered=[];
 public function __construct($plugin){$this->plugin=$plugin;}
 private function check($ok,$message){++$this->checks;if(!$ok){throw new \RuntimeException($message);}}
 private function spawn($level,$name,$x,$y,$z){
  $chunk=$level->getChunk(((int)floor($x))>>4,((int)floor($z))>>4,false);
  $nbt=VanillaAI26::entityNBT(new Vector3($x,$y,$z),new Vector3());
  $e=Entity::createEntity($name,$chunk,$nbt);
  $this->check($e instanceof Creature,'No registered creature '.$name);$this->mobs[]=$e;return $e;
 }
 public function onRun($tick){
  $s=$this->plugin->getServer();$level=$s->getDefaultLevel();
  try{
   if($this->stage===0){
    $this->started=$tick;
    // Only an isolated fixture world is edited. The production maps are never used here.
    $level->setTime(18000);$level->stopTime();
    for($cx=-3;$cx<=3;++$cx){for($cz=-3;$cz<=3;++$cz){
     $chunk=Chunk::getEmptyChunk($cx,$cz,$level->getProvider());
     for($x=0;$x<16;++$x){for($z=0;$z<16;++$z){$chunk->setBlock($x,62,$z,7,0);$chunk->setBlock($x,63,$z,1,0);}}
     $chunk->setGenerated();$chunk->setPopulated();$chunk->recalculateHeightMap();$level->setChunk($cx,$cz,$chunk,false);
    }}
    foreach(array_keys(MobBehavior26::all()) as $i=>$name){
     $x=-38+($i%7)*10;$z=-36+intdiv($i,7)*10;$y=64;
     if($name==='Squid'){
      for($px=$x-2;$px<=$x+2;++$px){for($pz=$z-2;$pz<=$z+2;++$pz){for($py=64;$py<=68;++$py){$level->setBlockRaw($px,$py,$pz,9,0);}}}
     }elseif($name==='Ghast'||$name==='Bat'||$name==='Blaze'){$y=69;}
     $mob=$this->spawn($level,$name,$x+0.5,$y,$z+0.5);
     $this->check($mob->width>0&&$mob->height>0&&$mob->getHealth()>0,'Invalid source dimensions/health '.$name);
     $this->covered[]=$name;
     $mob->onUpdate($tick+1);
     $this->check(VanillaAI26::snapshot($mob->getId())!==[],'AI did not initialize '.$name);
    }
    foreach($this->mobs as $mob){$mob->close();}$this->mobs=[];
    $this->zombie=$this->spawn($level,'Zombie',0.5,64,0.5);
    $this->villager=$this->spawn($level,'Villager',10.5,64,0.5);
    $this->neutral=$this->spawn($level,'PigZombie',-16.5,64,10.5);
    $this->cow=$this->spawn($level,'Cow',20.5,64,12.5);
    $this->age=$this->zombie->ticksLived;
    for($y=64;$y<=68;++$y){$level->setBlockRaw(26,$y,20,1,0);}
    $this->wallA=$this->spawn($level,'Cow',24.5,64,20.5);$this->wallB=$this->spawn($level,'Cow',28.5,64,20.5);
    $this->check(!Navigation26::canSee($this->wallA,$this->wallB),'Mob sees through wall');
    $this->stage=1;return;
   }
   if($this->stage===1&&$tick-$this->started>=60){
    $state=VanillaAI26::snapshot($this->zombie->getId());
    $this->check(($state['target']??0)===$this->villager->getId(),'Zombie did not acquire villager');
    $this->check($this->zombie->x>1.0,'Zombie motor did not move');
    $this->check($this->zombie->ticksLived-$this->age<=65,'Living base tick advanced twice');
    $this->check((VanillaAI26::snapshot($this->neutral->getId())['target']??0)===0,'Neutral pig zombie attacked without provocation');
    $before=$this->neutral->getHealth();$cancel=new EntityDamageByEntityEvent($this->cow,$this->neutral,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1);$cancel->setCancelled();$this->neutral->attack(1,$cancel);
    $this->check($this->neutral->getHealth()===$before,'Cancelled damage changed health');
    $this->check((VanillaAI26::snapshot($this->neutral->getId())['anger']??0)===0,'Cancelled damage caused neutral anger');
    $hit=new EntityDamageByEntityEvent($this->cow,$this->neutral,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1);$this->neutral->attack(1,$hit);
    $this->check((VanillaAI26::snapshot($this->neutral->getId())['angerTarget']??0)===$this->cow->getId(),'Successful hit did not start neutral retaliation');
    $panic=new EntityDamageByEntityEvent($this->neutral,$this->cow,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1);$this->cow->attack(1,$panic);
    $this->check((VanillaAI26::snapshot($this->cow->getId())['panic']??0)>0,'Passive hit did not start panic');
    $this->check(abs(EnchantmentEffects26::sharpnessBonus(5)-3.0)<0.00001,'Sharpness V bonus wrong');
    $chest=Item::get(Item::DIAMOND_CHESTPLATE);$chest->addEnchantment(Enchantment::getEnchantment(0)->setLevel(4));
    $boots=Item::get(Item::DIAMOND_BOOTS);$boots->addEnchantment(Enchantment::getEnchantment(0)->setLevel(4));
    $this->check(EnchantmentEffects26::protectionPoints([$chest,$boots],EntityDamageEvent::CAUSE_ENTITY_ATTACK)===8,'Armor EPF did not sum');
    $this->check(!Enchantment::canEnchantItem(Item::get(Item::DIAMOND_AXE),Enchantment::TYPE_WEAPON_LOOTING),'Looting accepted axe');
    $book=Item::get(Item::ENCHANTED_BOOK);$book->addEnchantment(Enchantment::getEnchantment(9)->setLevel(3));
    $sword=Item::get(Item::DIAMOND_SWORD);$sword->addEnchantment(Enchantment::getEnchantment(9)->setLevel(3));
    $anvil=AnvilInventory::calculateResult($sword,$book);
    $this->check($anvil!==null&&$anvil['item']->getEnchantmentLevel(9)===4,'Anvil failed equal-level merge');
    $incompatible=Item::get(Item::ENCHANTED_BOOK);$incompatible->addEnchantment(Enchantment::getEnchantment(10)->setLevel(4));
    $this->check(AnvilInventory::calculateResult($sword,$incompatible)===null,'Anvil accepted incompatible book');
    $nbt=new CompoundTag('',[]);$nbt->x=new \pocketmine\nbt\tag\IntTag('x',0);$nbt->y=new \pocketmine\nbt\tag\IntTag('y',64);$nbt->z=new \pocketmine\nbt\tag\IntTag('z',0);
    $tile=new EnchantTable($level->getChunk(0,0,false),$nbt);
    $table=new EnchantInventory($tile);$this->check($table->setItem(0,Item::get(Item::DIAMOND_PICKAXE)),'Table input failed');
    $offers=$table->getEnchantmentEntries();$this->check(count($offers)===3,'Table did not generate three offers');$tile->close();
    // Create saved owner/baby/anger state and verify provider serialization,
    // not just in-memory helper fields. Keep all reload cells away from spawn.
    $saved=$this->spawn($level,'Wolf',36.5,64,30.5);
    $saved->namedtag->OwnerName26=new \pocketmine\nbt\tag\StringTag('OwnerName26','fixture');
    $saved->namedtag->Sitting=new \pocketmine\nbt\tag\ByteTag('Sitting',1);
    $saved->namedtag->Age=new \pocketmine\nbt\tag\IntTag('Age',-12000);
    VanillaAI26::forget($saved);$saved->setMaxHealth(40);$saved->refreshDimensions26(true);$saved->onUpdate($tick+1);$saved->saveNBT();
    $this->check(abs($saved->width-0.3)<0.001&&abs($saved->height-0.425)<0.001,'Baby wolf collision dimensions wrong');
    $this->check($saved->getMaxHealth()===40,'Saved tamed wolf source health wrong');
    foreach($this->mobs as $mob){if(!$mob->closed){$mob->saveNBT();}}
    $chunks=[];foreach($this->mobs as $mob){if(!$mob->closed){$chunks[($mob->x>>4).':'.($mob->z>>4)]=[$mob->x>>4,$mob->z>>4];}}
    $reloadX=$saved->x>>4;$reloadZ=$saved->z>>4;
    $level->getProvider()->saveChunk($reloadX,$reloadZ);
    $this->check($level->unloadChunk($reloadX,$reloadZ,false,true),'Fixture chunk did not unload');
    $chunk=$level->getChunk($reloadX,$reloadZ,false);$restored=null;
    foreach($chunk->getEntities() as $candidate){if($candidate instanceof \pocketmine\entity\Wolf){$restored=$candidate;break;}}
    $this->check($restored!==null,'Saved wolf did not reload');
    $restored->onUpdate($tick+1);$snapshot=VanillaAI26::snapshot($restored->getId());
    $this->check(($snapshot['owner']??'')==='fixture'&&($snapshot['age']??0)<0&&$restored->isSitting(),'Owner/baby/sitting state lost across chunk reload');
    foreach($chunks as [$cx,$cz]){$level->getProvider()->saveChunk($cx,$cz);}
    $receipt=['ok'=>true,'coveredMobs'=>$this->covered,'mobCount'=>count($this->covered),'checks'=>$this->checks,'zombieState'=>$state,'savedChunks'=>count($chunks),'reloadState'=>['owner'=>$snapshot['owner'],'age'=>$snapshot['age'],'sitting'=>$restored->isSitting()],'php'=>PHP_VERSION];
    file_put_contents($s->getDataPath().'ai-enchant-test-result.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    $s->getLogger()->info('[AI-ENCHANT26] PASS '.json_encode(['mobs'=>count($this->covered),'checks'=>$this->checks]));
    $s->getScheduler()->cancelTask($this->getTaskId());$s->shutdown();
   }
  }catch(\Throwable $e){file_put_contents($s->getDataPath().'ai-enchant-test-result.json',json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_PRETTY_PRINT));$s->getLogger()->critical('[AI-ENCHANT26] FAIL '.$e->getMessage());$s->getScheduler()->cancelTask($this->getTaskId());$s->shutdown();}
 }
}
