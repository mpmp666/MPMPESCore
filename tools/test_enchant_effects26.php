<?php
/** Source-derived numbers plus actual Living::attack / damage event integration. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH', dirname(__DIR__) . '/');
define('pocketmine\\DEBUG', 0);
define('ENDIANNESS', 1);
define('INT32_MASK', 0xffffffff);
spl_autoload_register(function($class){ $p = pocketmine\PATH.'src/'.str_replace('\\','/',$class).'.php'; if(is_file($p)){ require_once $p; } });
use pocketmine\item\Item;
use pocketmine\item\Bow;
use pocketmine\item\EnchantmentEffects26 as E;
use pocketmine\item\enchantment\Enchantment as N;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Arrow;
use pocketmine\event\entity\EntityDamageEvent as D;
use pocketmine\event\entity\EntityDamageByEntityEvent as Hit;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
$checks = 0;
function ok($value, $message){ global $checks; ++$checks; if(!$value){ throw new RuntimeException($message); } }
function near($actual, $expected, $message){ ok(abs($actual-$expected)<0.000001, $message.' got '.$actual.' expected '.$expected); }
function ench(Item $item, $id, $level){ $item->addEnchantment(N::getEnchantment($id)->setLevel($level)); return $item; }
class EffectsPM{
 public $cancel = false; public $override = null; public $events = [];
 public function callEvent($event){ $this->events[]=$event; if($event instanceof D){ if($this->cancel){$event->setCancelled();} if($this->override!==null){$event->setDamage($this->override,D::MODIFIER_PROTECTION);} } }
}
class EffectsServer{
 public $pm; public function __construct(){ $this->pm=new EffectsPM(); }
 public function getPluginManager(){ return $this->pm; }
 public function getDifficulty(){ return 2; }
}
class EffectsLevel extends \pocketmine\level\Level{
 private $testServer; public function __construct($server){$this->testServer=$server;}
 public function getServer(){ return $this->testServer; }
}
class EffectsInventory{
 public $hand; public $armor=[];
 public function __construct(){ $this->hand=Item::get(Item::AIR); }
 public function getItemInHand(){return $this->hand;}
 public function getArmorContents(){return $this->armor;}
 public function getHelmet(){return $this->armor[0]??Item::get(Item::AIR);}
 public function getBoots(){return $this->armor[3]??Item::get(Item::AIR);}
 public function setArmorItem($slot,Item $item){$this->armor[$slot]=$item;}
}
class EffectsPlayer extends \pocketmine\Player{
 public $testInventory; public $creative=false; public $wet=false; public $ground=true;
 public function __construct($server,$level){$this->server=$server;$this->level=$level;$this->setHealth(100);$this->testInventory=new EffectsInventory();$this->inventory=$this->testInventory;\pocketmine\entity\Attribute::init();$this->attributeMap=new \pocketmine\entity\AttributeMap();$this->attributeMap->addAttribute(\pocketmine\entity\Attribute::getAttribute(\pocketmine\entity\Attribute::MOVEMENT_SPEED));$this->hasSpawned=[];$this->id=12;$this->spawned=false;}
 public function getInventory(){return $this->testInventory;}
 public function setHealth($amount){$p=new ReflectionProperty(Entity::class,'health');$p->setValue($this,$amount);}
 public function getHealth(){ $p=new ReflectionProperty(Entity::class,'health');return $p->getValue($this); }
 public function isCreative():bool{return $this->creative;}
 public function isSurvival():bool{return !$this->creative;}
 public function hasEffect($id){return false;}
 public function isInsideOfWater(){return $this->wet;}
 public function isOnGround(){return $this->ground;}
 public function __destruct(){}
}
class EffectsVictim extends Living{
 public $knocks=0; public $lastKnock=0;
 public function __construct($server,$level){$this->server=$server;$this->level=$level;$this->setHealth(100);$this->hasSpawned=[];$this->id=13;$this->x=1;}
 public function getName(){return 'test';}
 public function hasEffect($id){return false;}
 public function setHealth($amount){$p=new ReflectionProperty(Entity::class,'health');$p->setValue($this,$amount);}
 public function getHealth(){ $p=new ReflectionProperty(Entity::class,'health');return $p->getValue($this); }
 public function knockBack(Entity $a,$damage,$x,$z,$base=0.4){++$this->knocks;$this->lastKnock=$base;}
 public function fireTicks(){return $this->fireTicks;}
 public function resetHit(){ $this->attackTime=0;$this->lastDamageCause=null;$this->fireTicks=0;$this->knocks=0; }
 public function __destruct(){}
}
class EffectsArrow extends Arrow{
 public function __construct($critical=false){$this->isCritical=$critical;}
 public function fireTicks(){return $this->fireTicks;}
 public function __destruct(){}
}
Block::init(); Item::init(); N::init(); \pocketmine\entity\Effect::init();
near(E::sharpnessBonus(0),0,'Sharpness absent');near(E::sharpnessBonus(1),1,'Sharpness I');near(E::sharpnessBonus(5),3,'Sharpness V');
$weapon=ench(Item::get(Item::DIAMOND_SWORD),N::TYPE_WEAPON_SMITE,3);
near(E::meleeBonus($weapon,'undead'),7.5,'Smite III undead');near(E::meleeBonus($weapon,'other'),0,'Smite does not affect ordinary mobs');
$weapon=ench(Item::get(Item::DIAMOND_SWORD),N::TYPE_WEAPON_ARTHROPODS,4);
near(E::meleeBonus($weapon,'arthropod'),10,'Bane IV arthropods');near(E::meleeBonus($weapon,'undead'),0,'Bane excludes undead');
foreach([0=>1,1=>0.5,2=>1/3,3=>0.25] as $l=>$chance){near(E::durabilityDamageChance($l),$chance,'Tool Unbreaking '.$l);near(E::durabilityDamageChance($l,true),0.6+0.4*$chance,'Armor Unbreaking '.$l);}
ok(E::shouldDamageDurability(3,true,0.69999)&&!E::shouldDamageDurability(3,true,0.7),'Armor exact 70% boundary');
ok(E::consumeAir(3,0.2499)&&!E::consumeAir(3,0.25),'Respiration III 1/4 air consumption');
near(E::efficiencySpeed(8,5),34,'Efficiency V adds26 to diamond8');near(E::efficiencySpeed(1,5),1,'Wrong tool excludes efficiency');
near(E::miningPenalty(true,true,false),5,'Underwater penalty');near(E::miningPenalty(true,true,true),1,'Aqua Affinity');near(E::miningPenalty(true,false,true),5,'Floating penalty remains');
$pick=ench(Item::get(Item::DIAMOND_PICKAXE),N::TYPE_MINING_EFFICIENCY,5);
near(Block::get(Block::STONE)->getBreakTime($pick),2.25/34,'Actual block break time');
$wrong=ench(Item::get(Item::DIAMOND_SHOVEL),N::TYPE_MINING_EFFICIENCY,5);near(Block::get(Block::STONE)->getBreakTime($wrong),7.5,'Wrong tool break time');
$armor=[];foreach([Item::DIAMOND_HELMET,Item::DIAMOND_CHESTPLATE,Item::DIAMOND_LEGGINGS,Item::DIAMOND_BOOTS] as $id){$armor[]=ench(Item::get($id),N::TYPE_ARMOR_PROTECTION,4);}
ok(E::protectionPoints($armor,D::CAUSE_ENTITY_ATTACK)===16,'Protection sums all four armor pieces');near(E::protectionMultiplier(16),0.36,'16EPF64%');
$projectile=ench(Item::get(Item::DIAMOND_HELMET),N::TYPE_ARMOR_PROJECTILE_PROTECTION,4);ok(E::protectionPoints([$projectile],D::CAUSE_PROJECTILE)===8,'Projectile protection2perlevel');near(E::protectionMultiplier(80),0.2,'EPF clamp20');
$server=new EffectsServer();$level=new EffectsLevel($server);$player=new EffectsPlayer($server,$level);$victim=new EffectsVictim($server,$level);
$player->testInventory->armor=$armor;$ev=new D($player,D::CAUSE_ENTITY_ATTACK,10);near($ev->getDamage(D::MODIFIER_PROTECTION),0.36,'Actual damage event protection');near($ev->getFinalDamage(),0.72,'Armor and protection each once');
$player->testInventory->armor=[];$player->testInventory->hand=ench(ench(ench(Item::get(Item::DIAMOND_SWORD),N::TYPE_WEAPON_SHARPNESS,5),N::TYPE_WEAPON_FIRE_ASPECT,1),N::TYPE_WEAPON_KNOCKBACK,2);
$server->pm->cancel=true;$event=new Hit($player,$victim,D::CAUSE_ENTITY_ATTACK,4);ok($victim->attack(4,$event)===false,'Cancelled actual Living attack return');near($victim->getHealth(),100,'Cancelled hit no damage');ok($victim->fireTicks()===0&&$victim->knocks===0,'Cancelled hit no fire/knockback');
$server->pm->cancel=false;$event=new Hit($player,$victim,D::CAUSE_ENTITY_ATTACK,4);ok($victim->attack(4,$event)===true,'Accepted actual Living attack return');near($event->getDamage(),7,'Actual hook sharpness once');near($victim->getHealth(),93,'Actual health damage');ok($victim->fireTicks()===80,'Fire Aspect I exactly4seconds80ticks');near($victim->lastKnock,1.4,'Knockback II applied on hit');
$event=new Hit($player,$victim,D::CAUSE_ENTITY_ATTACK,4);ok($victim->attack(4,$event)===false&&$event->isCancelled(),'Hurt cooldown rejects equal damage');near($victim->getHealth(),93,'Cooldown no second damage');
$victim->resetHit();$server->pm->override=0.5;$event=new Hit($player,$victim,D::CAUSE_ENTITY_ATTACK,4);$victim->attack(4,$event);near($event->getFinalDamage(),3.5,'Plugin protection override honored');$server->pm->override=null;
$helmet=ench(Item::get(Item::DIAMOND_HELMET),N::TYPE_WATER_BREATHING,3);$player->testInventory->armor=[0=>$helmet];ok(E::helmetLevel($player,N::TYPE_WATER_BREATHING)===3,'Respiration helmet read');$player->testInventory->armor=[0=>ench(Item::get(Item::DIAMOND_BOOTS),N::TYPE_WATER_BREATHING,3)];ok(E::helmetLevel($player,N::TYPE_WATER_BREATHING)===0,'Respiration boots ignored');
$bow=ench(ench(ench(ench(Item::get(Item::BOW),N::TYPE_BOW_POWER,5),N::TYPE_BOW_KNOCKBACK,2),N::TYPE_BOW_FLAME,1),N::TYPE_BOW_INFINITY,1);$arrow=new EffectsArrow(false);$bow->applyEnchantments($arrow);near($arrow->getBaseDamage(),5,'Power V arrow base5');ok($arrow->hitDamage(2,4)===10,'Noncritical no critical bonus');$critical=new EffectsArrow(true);$bow->applyEnchantments($critical);ok($critical->hitDamage(2,4)===14,'Critical independent bonus');ok($arrow->getPunch()===2&&$arrow->fireTicks()===2000,'Punch and Flame100seconds');ok(!$bow->consumesArrow(false)&&!$bow->consumesArrow(true),'Infinity/creative consume no arrows');ok(!(new Bow())->consumesArrow(true)&&(new Bow())->consumesArrow(false),'Ordinary survival bow consumes arrow');near(Bow::drawPower(20),1,'Full bow draw');near(Bow::drawPower(10),5/12,'Half draw');
$custom=Item::get(Item::DIAMOND_SWORD);$custom->setNamedTag(new CompoundTag('',[new StringTag('custom','kept'),new ListTag('ench',[new CompoundTag('',[new IntTag('id',9),new IntTag('lvl',2),new StringTag('extension','kept')])])]));ench($custom,9,4);ok($custom->getEnchantmentLevel(9)===4&&count($custom->getEnchantments())===1,'Numeric NBT IDs replace without duplicate');ok($custom->getNamedTag()['custom']==='kept'&&$custom->getNamedTag()->ench[0]['extension']==='kept','Enchantment write preserves custom NBT');
$bad=Item::get(Item::DIAMOND_SWORD);$bad->setNamedTag(new CompoundTag('',[new ListTag('ench',[new CompoundTag('',[new StringTag('id','9'),new ShortTag('lvl',5)])])]));ok($bad->getEnchantmentLevel(9)===0,'String ID cannot spoof numeric enchant');
foreach([0=>1,1=>1,2=>2,3=>3,4=>4] as $roll=>$count){ok(E::oreCount(1,3,$roll)===$count,'Fortune III ore outcome '.$roll);}
$valuable=ench(Item::get(Item::DIAMOND_PICKAXE),N::TYPE_MINING_FORTUNE,3);$diamondBlock=E::harvestDrops(Block::get(Block::DIAMOND_BLOCK),$valuable);ok(count($diamondBlock)===1&&$diamondBlock[0]->getCount()===1&&$diamondBlock[0]->getId()===Block::DIAMOND_BLOCK,'Fortune cannot duplicate valuable block');
$silk=ench($valuable,N::TYPE_MINING_SILK_TOUCH,1);$ore=E::harvestDrops(Block::get(Block::DIAMOND_ORE),$silk);ok(count($ore)===1&&$ore[0]->getId()===Block::DIAMOND_ORE&&$ore[0]->getCount()===1,'Silk takes precedence over Fortune');
near(E::lootingBonus(3,0.5),2,'Looting rounded continuous sample');$equipment=ench(Item::get(Item::DIAMOND_SWORD),9,2);$humanDrops=E::lootingDrops($player,[$equipment],3);ok($humanDrops[0]->getCount()===1,'Looting excludes Human inventory/equipment');
near(E::fishingWait(500,3),200,'Lure III5secondsperlevel');ok(E::fishingWeights(3,true)===[4,11,82]&&E::fishingWeights(3,false)===[4,0,82],'Luck source category weights and open water');
$rod=Item::get(Item::FISHING_ROD,17);ok($rod->getDamage()===17&&$rod->getMaxDurability()===65&&$rod->isTool(),'Fishing rod preserves damage and durability');$rod->useOn($victim);ok($rod->getDamage()===18,'Actual rod loses durability without Unbreaking');
near(E::waterMovementScale(3,true),2,'Depth Strider III ground adaptation');near(E::waterMovementScale(3,false),1.5,'Depth Strider III airborne half');near(E::waterMovementScale(100,true),2,'Depth Strider cap3');
$player->testInventory->armor=[3=>ench(Item::get(Item::DIAMOND_BOOTS),N::TYPE_WATER_SPEED,3)];$player->wet=true;$player->updateWaterMovementEnchantments();$speed=$player->getAttributeMap()->getAttribute(\pocketmine\entity\Attribute::MOVEMENT_SPEED);near($speed->getValue(),0.2,'Actual Player water movement attribute');$player->updateWaterMovementEnchantments();near($speed->getValue(),0.2,'Water speed does not compound');$player->wet=false;$player->updateWaterMovementEnchantments();near($speed->getValue(),0.1,'Water exit restores speed');

echo json_encode(['ok'=>true,'checks'=>$checks,'sourceVersion'=>'26.2','actualLivingAttack'=>true,'cancelledHit'=>true,'pluginOverrides'=>true,'criticalArrow'=>true],JSON_PRETTY_PRINT)."\n";
