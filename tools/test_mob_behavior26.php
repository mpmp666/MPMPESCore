<?php
/** Executes the real 26.2 controller, profiles, navigation, events and block shapes
 * against in-memory entity/level doubles. No server, sockets or chunk loading. */
namespace {
	error_reporting(E_ALL & ~E_DEPRECATED);
	define('pocketmine\\PATH', dirname(__DIR__) . '/');
	spl_autoload_register(function($class){
		$path = pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php';
		if(is_file($path)){ require_once $path; }
	});
}
namespace pocketmine\level\format { interface FullChunk{} }
namespace pocketmine\entity {
	use pocketmine\math\Vector3;
	use pocketmine\math\AxisAlignedBB;
	use pocketmine\nbt\tag\CompoundTag;
	use pocketmine\event\entity\EntityDamageEvent;
	class Entity extends Vector3{
		const DATA_NO_AI=15, DATA_FLAGS=0, DATA_TYPE_BYTE=0, DATA_TYPE_INT=2, DATA_FLAG_ONFIRE=0;
		public $closed=false,$namedtag,$level,$chunk,$width=0.6,$height=1.8,$eyeHeight=1.6,$boundingBox;
		public $motionX=0.0,$motionY=0.0,$motionZ=0.0,$yaw=0.0,$pitch=0.0,$onGround=true,$fireTicks=0,$ticksLived=0;
		public $isCollidedHorizontally=false,$health=1000,$maxHealth=1000,$water=false,$id,$data=[],$attacks=[],$cancelDamage=false,$shootingEntity;
		protected $age=0;
		private static $next=1;
		public static $born=[];
		public function __construct($chunk=null, $nbt=null, $shooter=null){
			$this->id=self::$next++;$this->namedtag=$nbt?:new CompoundTag();$this->chunk=$chunk;$this->shootingEntity=$shooter;
			$this->level=$chunk?$chunk->level:null;
			$this->x=$nbt&&isset($nbt->Pos)?$nbt['Pos'][0]:0.5;$this->y=$nbt&&isset($nbt->Pos)?$nbt['Pos'][1]:1;$this->z=$nbt&&isset($nbt->Pos)?$nbt['Pos'][2]:0.5;
			if($nbt&&isset($nbt->Motion)){$this->motionX=$nbt['Motion'][0];$this->motionY=$nbt['Motion'][1];$this->motionZ=$nbt['Motion'][2];}
			$this->box();if($this->level){$this->level->add($this);}
		}
		public function box(){$r=$this->width/2;$this->boundingBox=new AxisAlignedBB($this->x-$r,$this->y,$this->z-$r,$this->x+$r,$this->y+$this->height,$this->z+$r);}
		public function getId(){return $this->id;}public function getLevel(){return $this->level;}public function isAlive(){return $this->health>0;}
		public function getHealth(){return $this->health;}public function getMaxHealth(){return $this->maxHealth;}public function setHealth($h){$this->health=$h;}public function setMaxHealth($h){$this->maxHealth=$h;}
		public function getEyeHeight(){return $this->eyeHeight;}public function getBoundingBox(){return $this->boundingBox;}
		public function hasEffect($id){return false;}public function addEffect($effect){}public function isFireProof(){return false;}
		public function getDataProperty($id){return $this->data[$id]??0;}public function setDataProperty($id,$type,$value){$this->data[$id]=$value;}
		public function getDataFlag($p,$id){return (($this->data[$p]??0)&(1<<$id))!==0;}public function setDataFlag($p,$id,$v=true){$flags=$this->data[$p]??0;$this->data[$p]=$v?($flags|(1<<$id)):($flags&~(1<<$id));}
		public function setMotion(Vector3 $v){$this->motionX=$v->x;$this->motionY=$v->y;$this->motionZ=$v->z;return true;}
		public function setRotation($yaw,$pitch){$this->yaw=$yaw;$this->pitch=$pitch;}public function isInsideOfWater(){return $this->water;}
		public function setOnFire($seconds){$this->fireTicks=$seconds*20;}public function resetFallDistance(){}
		public function getDirectionVector(){$p=deg2rad($this->pitch);$y=deg2rad($this->yaw);return new Vector3(-sin($y)*cos($p),-sin($p),cos($y)*cos($p));}
		public function attack($damage, EntityDamageEvent $source){$this->attacks[]=$source;$this->level->server->events->callEvent($source);if($this->cancelDamage){$source->setCancelled();}if(!$source->isCancelled()){$this->health-=$source->getFinalDamage();}return !$source->isCancelled();}
		public function heal($amount,$event){$this->health=min($this->maxHealth,$this->health+$amount);}
		public function close(){$this->closed=true;}public function kill(){$this->health=0;}public function spawnToAll(){}public function spawnTo(\pocketmine\Player $player){}public function saveNBT(){}
		public function setPositionAndRotation(Vector3 $p,$yaw,$pitch){$this->x=$p->x;$this->y=$p->y;$this->z=$p->z;$this->box();return true;}
		public static function createEntity($name,$chunk,$nbt){$class=__NAMESPACE__.'\\'.$name;$e=new $class($chunk,$nbt);self::$born[]=$e;return $e;}
	}
	class Living extends Entity{}
	class Creature extends Living{}
	class Human extends Creature{}
	class Animal extends Creature implements Ageable{
		public $inLove=0,$sitting=false,$eatTimer=0,$eating=false;
		public function isBaby(){return $this->getDataFlag(14,0);}public function isSitting(){return $this->sitting;}public function setSitting(bool $v=true){$this->sitting=$v;}public function setInLove(int $t=600){$this->inLove=$t;}
	}
	class Cow extends Animal{}class Mooshroom extends Animal{}class Pig extends Animal{}class Chicken extends Animal{}class Rabbit extends Animal{}class Wolf extends Animal{}class Ocelot extends Animal{}
	class Sheep extends Animal{const DATA_COLOR_INFO=16;public $sheared=true;public function getColor(){return 3;}}
	class Villager extends Creature{}class IronGolem extends Animal{}class SnowGolem extends Animal{}
	class Zombie extends Creature{}class ZombieVillager extends Zombie{}class PigZombie extends Creature{}class Skeleton extends Creature{}class Spider extends Creature{}class CaveSpider extends Creature{}
	class Creeper extends Creature{const DATA_SWELL_DIRECTION=16,DATA_SWELL=17,DATA_SWELL_OLD=18;public function isPowered(){return false;}}
	class Slime extends Creature{}class LavaSlime extends Creature{}class Silverfish extends Creature{}class Enderman extends Creature{}class Blaze extends Creature{}class Ghast extends Creature{}class Bat extends Creature{}class Squid extends Creature{}
	class Projectile extends Entity{
		public $hadCollision=false;
		public function onUpdate($tick){return true;}
	}
}
namespace pocketmine {
	class Player extends \pocketmine\entity\Human{
		public $creative=false,$spectator=false,$online=true,$playerName='alex',$held=0,$helmet=0;
		public function isSurvival():bool{return !$this->creative&&!$this->spectator;}public function isOnline():bool{return $this->online;}public function isCreative():bool{return $this->creative;}public function isSpectator():bool{return $this->spectator;}public function getName(){return $this->playerName;}
		public function getInventory(){return new \BehaviorInventory26($this);}public function dataPacket($packet){}
	}
}
namespace pocketmine\level {
	class Level{
		public $server,$chunks=[],$entities=[],$time=14000,$drops=[],$packets=[];
		public function __construct(){$this->server=new \BehaviorServer26();for($x=-3;$x<=3;++$x){for($z=-3;$z<=3;++$z){$this->chunks[self::chunkHash($x,$z)]=new \BehaviorChunk26($this,$x,$z);}}}
		public static function chunkHash($x,$z){return $x.':'.$z;}
		public function getChunks(){return $this->chunks;}public function getChunk(int $x,int $z,bool $create=false){throw new \RuntimeException('Controller/navigation attempted chunk load');}
		public function isChunkLoaded($x,$z){throw new \RuntimeException('Use the in-memory chunk map, not provider isChunkLoaded');}
		public function getServer(){return $this->server;}public function getTime(){return $this->time;}public function getDimension(){return 0;}
		public function getEntity($id){return $this->entities[$id]??null;}public function getPlayers(){return array_filter($this->entities,function($e){return $e instanceof \pocketmine\Player;});}
		public function add($e){$this->entities[$e->id]=$e;$c=$this->chunks[self::chunkHash(((int)floor($e->x))>>4,((int)floor($e->z))>>4)]??null;if($c){$c->entities[$e->id]=$e;}}
		public function getBlock(\pocketmine\math\Vector3 $p,$cached=true){$x=(int)floor($p->x);$y=(int)floor($p->y);$z=(int)floor($p->z);$c=$this->chunks[self::chunkHash($x>>4,$z>>4)]??null;if(!$c){throw new \RuntimeException('Unloaded block read');}$f=$c->getFullBlock($x&15,$y,$z&15);return \pocketmine\block\Block::get($f>>4,$f&15,new Position($x,$y,$z,$this));}
		public function set($x,$y,$z,$id){$c=$this->chunks[self::chunkHash($x>>4,$z>>4)];$c->blocks[($x&15).':'.$y.':'.($z&15)]=$id;}
		public function getFullStateAt(int $x,int $y,int $z):int{return $this->chunks[self::chunkHash($x>>4,$z>>4)]->getFullBlock($x&15,$y,$z&15);}
		public function dropItem($p,$item,$motion=null,$delay=10){$this->drops[]=$item;}
		public function getNearbyEntities($bb,$except=null){return array_filter($this->entities,function($e)use($except,$bb){return $e!==$except&&$e->boundingBox->intersectsWith($bb);});}
		public function addChunkPacket($x,$z,$packet){$this->packets[]=$packet;}
	}
}
namespace {
	use pocketmine\entity\ai\VanillaAI26 as AI;
	use pocketmine\entity\ai\MobBehavior26;
	use pocketmine\entity\ai\Environment26;
	use pocketmine\entity\Entity;
	use pocketmine\event\entity\EntityDamageByEntityEvent;
	use pocketmine\event\entity\EntityDamageEvent;
	use pocketmine\event\entity\EntityExplodeEvent;
	use pocketmine\nbt\tag\IntTag;
	use pocketmine\nbt\tag\StringTag;
	use pocketmine\item\Item;
	use pocketmine\math\Vector3;
	class BehaviorChunk26 implements \pocketmine\level\format\FullChunk{
		public $level,$cx,$cz,$entities=[],$blocks=[];
		public function __construct($l,$x,$z){$this->level=$l;$this->cx=$x;$this->cz=$z;}
		public function getBlockId($x,$y,$z){return $this->blocks[$x.':'.$y.':'.$z]??($y===0?1:0);}public function getFullBlock($x,$y,$z){return $this->getBlockId($x,$y,$z)<<4;}public function getEntities(){return $this->entities;}
	}
	class BehaviorEvents26{
		public $records=[],$cancelExplosion=true;
		public function callEvent($e){$this->records[]=$e;if($e instanceof EntityExplodeEvent&&$this->cancelExplosion){$e->setCancelled();}}
	}
	class BehaviorServer26{public $tick=0,$aiEnabled=true,$aiConfig=['creeperexplode'=>false],$difficulty=2,$events;public function __construct(){$this->events=new BehaviorEvents26();}public function getTick(){return $this->tick;}public function getDifficulty(){return $this->difficulty;}public function getPluginManager(){return $this->events;}}
	class BehaviorInventory26{private $p;public function __construct($p){$this->p=$p;}public function getItemInHand(){return Item::get($this->p->held);}public function getArmorContents(){return [];}public function getHelmet(){return Item::get($this->p->helmet);}}
	$checks=0;
	function check26($ok,$message){global $checks;++$checks;if(!$ok){throw new RuntimeException($message);}}
	function world26(){return new \pocketmine\level\Level();}
	function mob26($name,$world,$x=0.5,$z=0.5){$class=$name==='Player'?'pocketmine\\Player':'pocketmine\\entity\\'.$name;$e=new $class();$e->level=$world;$e->x=$x;$e->z=$z;$e->box();$e->chunk=$world->chunks[\pocketmine\level\Level::chunkHash(((int)floor($x))>>4,((int)floor($z))>>4)];$world->add($e);return $e;}
	function tick26($mob,$ticks=1,$delta=1){for($i=0;$i<$ticks;++$i){$mob->level->server->tick+=$delta;AI::tick($mob,$delta);}}
	function snap26($mob){return AI::snapshot($mob->id);}
	\pocketmine\block\Block::init(); Item::$list=new \SplFixedArray(65536);
	check26(count(MobBehavior26::all())===26,'Every existing concrete mob needs an explicit profile');
	$w=world26();$human=mob26('Human',$w);check26(!AI::supports($human),'Humans must not enter mob AI');
	// Real nearest-target policy, mode exclusions, actual block-shape LOS and cooldown.
	$w=world26();$z=mob26('Zombie',$w);$creative=mob26('Player',$w,1.0);$creative->creative=true;$spectator=mob26('Player',$w,1.1);$spectator->spectator=true;$p=mob26('Player',$w,1.5);
	tick26($z);check26(snap26($z)['target']===$p->id,'Zombie did not exclude creative/spectator targets');check26(count($p->attacks)===1,'Melee did not use the damage event');
	AI::tick($z);check26(count($p->attacks)===1,'Repeated server tick advanced attacks twice');tick26($z,19);check26(count($p->attacks)===1,'Melee ignored 20-tick cooldown');tick26($z);check26(count($p->attacks)===2,'Melee did not resume after cooldown');
	$p->cancelDamage=true;tick26($z,20);check26(snap26($z)['lastAttackCancelled']===true,'Cancelled damage was not honored');
	$w=world26();$z=mob26('Zombie',$w);$p=mob26('Player',$w,4.5);$w->set(2,2,0,1);tick26($z);check26(snap26($z)['target']===0,'Target acquired through a solid wall');
	$w=world26();$z=mob26('Zombie',$w);$p=mob26('Player',$w,1.5);$w->server->difficulty=0;tick26($z);check26(count($p->attacks)===0&&snap26($z)['target']===0,'Peaceful monster attacked');
	// Neutral retaliation and pack anger are distinct from default hostile acquisition.
	foreach(['PigZombie','Wolf','Enderman'] as $name){$w=world26();$m=mob26($name,$w);$p=mob26('Player',$w,2);tick26($m);check26(snap26($m)['target']===0,$name.' became hostile by proximity');$ev=new EntityDamageByEntityEvent($p,$m,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1);AI::onDamage($m,$ev);check26(snap26($m)['anger']>=400&&snap26($m)['target']===$p->id,$name.' did not retaliate');}
	$w=world26();$pig=mob26('PigZombie',$w);$friend=mob26('PigZombie',$w,3);$p=mob26('Player',$w,2);AI::onDamage($pig,new EntityDamageByEntityEvent($p,$pig,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1));check26(snap26($friend)['target']===$p->id,'PigZombie pack did not share attacker');AI::forget($pig);tick26($pig);check26(snap26($pig)['target']===$p->id,'Persistent anger did not restore the player target');
	$w=world26();$wolf=mob26('Wolf',$w);$wolf->namedtag->OwnerName26=new StringTag('OwnerName26','alex');$p=mob26('Player',$w,2);tick26($wolf);AI::onDamage($wolf,new EntityDamageByEntityEvent($p,$wolf,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1));check26(snap26($wolf)['target']===0,'Tamed wolf attacked its owner');
	$w=world26();$g=mob26('IronGolem',$w);$c=mob26('Creeper',$w,1.5);$z=mob26('Zombie',$w,3);tick26($g);check26(snap26($g)['target']===$z->id,'Iron golem did not exclude creeper');
	// Actual source fuse: <3 start, retain inside7, abort on LOS loss or >7.
	$w=world26();$c=mob26('Creeper',$w);$p=mob26('Player',$w,3.5);tick26($c);check26(snap26($c)['fuse']===0,'Creeper started outside strict3 radius');$p->x=2.5;tick26($c);check26(snap26($c)['fuse']===1,'Creeper fuse did not start inside3');$p->x=6.5;tick26($c);check26(snap26($c)['fuse']===2,'Creeper did not retain source7 radius');$w->set(3,2,0,1);tick26($c);check26(snap26($c)['fuse']===1,'Creeper did not defuse on LOS loss');$w->set(3,2,0,0);$p->x=8;tick26($c);check26(snap26($c)['fuse']===0,'Creeper did not defuse beyond7');
	$w=world26();$c=mob26('Creeper',$w);$p=mob26('Player',$w,2.5);tick26($c,29);check26(count(array_filter($w->server->events->records,function($e){return $e instanceof EntityExplodeEvent;}))===0,'Creeper exploded before30');tick26($c);$expl=array_values(array_filter($w->server->events->records,function($e){return $e instanceof EntityExplodeEvent;}));check26(count($expl)===1&&$expl[0]->getEntity()===$c,'Creeper explosion lost entity protection event');check26(!$c->closed&&snap26($c)['fuse']===0,'Cancelled creeper explosion was applied');
	// Feeding uses real legacy items, love/cooldown gates and creates real baby state.
	$w=world26();$a=mob26('Cow',$w);$b=mob26('Cow',$w,2);$p=mob26('Player',$w,1);tick26($a,65);check26(count(Entity::$born)===0&&snap26($a)['love']===0,'Proximity generated free breeding');check26(!AI::feed($a,$p,Item::get(295)),'Cow accepted chicken seed');check26(AI::feed($a,$p,Item::get(296))&&AI::feed($b,$p,Item::get(296)),'Cow wheat did not enter love');tick26($a,60);check26(count(Entity::$born)===1,'Fed pair did not produce a child after60 courtship');check26(Entity::$born[0]->namedtag['Age']===-24000&&snap26($a)['age']===6000,'Baby age or parent cooldown incorrect');check26(!AI::feed($a,$p,Item::get(296)),'Breeding cooldown accepted another food');
	$w=world26();$cow=mob26('Cow',$w);$p=mob26('Player',$w,4);$p->held=296;tick26($cow);check26(snap26($cow)['goal']==='tempt','Cow ignored wheat temptation');AI::onDamage($cow,new EntityDamageByEntityEvent($p,$cow,EntityDamageEvent::CAUSE_ENTITY_ATTACK,1));tick26($cow,9);check26(snap26($cow)['goal']==='panic','Panic did not preempt temptation');
	$w=world26();$z=mob26('Zombie',$w);$w->time=1000;$w->set(0,4,0,20);tick26($z);check26($z->fireTicks===0,'Zombie burned through a real roof');$w->set(0,4,0,0);tick26($z,20);check26($z->fireTicks>0,'Exposed zombie did not burn');
	$w=world26();$z=mob26('Skeleton',$w);$z->namedtag->Helmet26=new IntTag('Helmet26',298);$w->time=1000;tick26($z);check26($z->fireTicks===0,'Helmet did not protect undead from sun');
	$w=world26();$cow=mob26('Cow',$w);$cow->water=true;tick26($cow);check26(snap26($cow)['goal']==='float'&&$cow->motionY>=0.12,'Float goal failed to apply buoyancy');
	$w=world26();$ch=mob26('Chicken',$w);$ch->namedtag->EggLayTime=new IntTag('EggLayTime',1);tick26($ch);check26(count($w->drops)===1&&$w->drops[0]->getId()===344&&snap26($ch)['egg']>=6000&&snap26($ch)['egg']<=12000,'Egg laying timer incorrect');
	$w=world26();$m=mob26('Cow',$w,63.5);tick26($m);check26(snap26($m)['goal']==='unloaded_boundary','Unloaded boundary was not rejected');AI::forget($m);check26(AI::snapshot($m->id)===[],'forget retained mob state');
	// Fireball representation and controller shooting cooldown use actual projectile classes.
	check26(\pocketmine\entity\Fireball26::NETWORK_ID===-1,'Fireball must not overwrite Snowball numeric mapping');
	$w=world26();$s=mob26('Blaze',$w);$p=mob26('Player',$w,10);tick26($s);check26(snap26($s)['shotStep']===1&&snap26($s)['cooldown']===60,'Blaze did not charge60');tick26($s,59);check26(snap26($s)['attackSerial']===0,'Blaze fired before charge complete');tick26($s);check26(snap26($s)['attackSerial']===1,'Blaze did not create its fireball');
	$w=world26();$g=mob26('Ghast',$w);$p=mob26('Player',$w,10);tick26($g,19);check26(snap26($g)['attackSerial']===0,'Ghast fired before20 charge');tick26($g);check26(snap26($g)['attackSerial']===1&&snap26($g)['cooldown']===40,'Ghast projectile cooldown incorrect');
	tick26($g,39);check26(snap26($g)['attackSerial']===1,'Ghast cooldown did not hold');tick26($g,20);check26(snap26($g)['attackSerial']===2,'Ghast did not recharge after cooldown');
	$w=world26();$wolf=mob26('Wolf',$w);$wolf->namedtag->OwnerName26=new StringTag('OwnerName26','alex');$p=mob26('Player',$w,1.5);check26(AI::interactOwned($wolf,$p,Item::get(0))&&$wolf->isSitting(),'Owner empty hand did not order sit');check26(AI::interactOwned($wolf,$p,Item::get(0))&&!$wolf->isSitting(),'Owner could not release sitting wolf');$p->playerName='other';check26(!AI::interactOwned($wolf,$p,Item::get(0)),'Nonowner changed wolf order');AI::save($wolf);check26($wolf->namedtag['OwnerName26']==='alex','Save omitted owner state');
	echo 'MobBehavior26: PASS ('.$checks.' checks; real controller/navigation/events, in-memory world doubles)'.PHP_EOL;
}
