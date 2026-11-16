<?php

/** Behavioral tests for Navigation26 against legacy block and AABB classes. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH', dirname(__DIR__) . '/');
spl_autoload_register(function($class){
	$path = pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php';
	if(is_file($path)){ require_once $path; }
});

use pocketmine\block\Block;
use pocketmine\entity\Creature;
use pocketmine\entity\Entity;
use pocketmine\entity\ai\Navigation26;
use pocketmine\level\Level;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

final class Navigation26TestServer{
	public $tick = 0;
	public function getTick(){ return $this->tick; }
}

final class Navigation26TestChunk{
	private $blocks = [];
	private $entities = [];
	public function setBlock(int $x, int $y, int $z, int $full){ $this->blocks[($x << 14) | ($z << 7) | $y] = $full; }
	public function getFullBlock(int $x, int $y, int $z){ return $this->blocks[($x << 14) | ($z << 7) | $y] ?? 0; }
	public function getEntities(){ return $this->entities; }
}

final class Navigation26TestLevel extends Level{
	private $testChunks = [];
	private $testServer;
	public function __construct(){ $this->testServer = new Navigation26TestServer(); }
	public function getServer(){ return $this->testServer; }
	public function getChunks(){ return $this->testChunks; }
	public function isChunkLoaded($cx, $cz){ return isset($this->testChunks[Level::chunkHash($cx, $cz)]); }
	public function getChunk(int $x, int $z, bool $create = false){ throw new RuntimeException('Navigation must not call getChunk(), even with create=false'); }
	public function getBlockIdAt(int $x, int $y, int $z) : int{ throw new RuntimeException('Navigation must not call implicitly-generating getBlockIdAt()'); }
	public function getBlockDataAt(int $x, int $y, int $z) : int{ throw new RuntimeException('Navigation must not call implicitly-generating getBlockDataAt()'); }
	public function getFullStateAt(int $x, int $y, int $z) : int{
		$chunk = $this->chunk($x, $z);
		return $chunk !== null && $y >= 0 && $y < 128 ? $chunk->getFullBlock($x & 15, $y, $z & 15) : 0;
	}
	public function getCollisionCubes(Entity $entity, AxisAlignedBB $bb, $entities = true){
		$result = [];
		for($x = (int) floor($bb->minX); $x <= (int) ceil($bb->maxX); ++$x){
			for($z = (int) floor($bb->minZ); $z <= (int) ceil($bb->maxZ); ++$z){
				for($y = max(0, (int) floor($bb->minY) - 1); $y <= min(127, (int) ceil($bb->maxY)); ++$y){
					$block = $this->getBlock(new Vector3($x, $y, $z));
					if(!$block->canPassThrough() && $block->collidesWithBB($bb)){ $result[] = $block->getBoundingBox(); }
				}
			}
		}
		return $result;
	}
	public function addChunk(int $cx, int $cz){ $this->testChunks[Level::chunkHash($cx, $cz)] = new Navigation26TestChunk(); }
	public function chunk(int $x, int $z){ return $this->testChunks[Level::chunkHash($x >> 4, $z >> 4)] ?? null; }
	public function set(int $x, int $y, int $z, int $id, int $meta = 0){
		$chunk = $this->chunk($x, $z);
		if($chunk !== null){ $chunk->setBlock($x & 15, $y, $z & 15, ($id << 4) | ($meta & 15)); }
	}
	public function getBlock(Vector3 $pos, $cached = true){
		$x = (int) floor($pos->x); $y = (int) floor($pos->y); $z = (int) floor($pos->z);
		$chunk = $this->chunk($x, $z);
		$full = $chunk === null || $y < 0 || $y >= 128 ? 0 : $chunk->getFullBlock($x & 15, $y, $z & 15);
		return Block::get($full >> 4, $full & 15, new Position($x, $y, $z, $this));
	}
	public function fillFloor(int $minX, int $maxX, int $minZ, int $maxZ){
		for($x = $minX; $x <= $maxX; ++$x){ for($z = $minZ; $z <= $maxZ; ++$z){ $this->set($x, 0, $z, Block::STONE); } }
	}
}

final class Navigation26TestTimer{
	public function startTiming(){}
	public function stopTiming(){}
}

final class Navigation26TestMob extends Creature{
	private $testId;
	private $testAlive = true;
	public $width = 0.6;
	public $height = 1.8;
	public $eyeHeight = 1.6;
	public $motionX = 0.0;
	public $motionY = 0.0;
	public $motionZ = 0.0;
	public $yaw = 0.0;
	public $pitch = 0.0;
	public $onGround = true;
	public $closed = false;
	public $boundingBox;
	public function __construct(int $id, Navigation26TestLevel $level, float $x, float $y, float $z, float $width = 0.6, float $height = 1.8){
		$this->testId = $id; $this->level = $level; $this->x = $x; $this->y = $y; $this->z = $z;
		$this->width = $width; $this->height = $height; $this->eyeHeight = $height * 0.85;
		$r = $width / 2; $this->boundingBox = new AxisAlignedBB($x - $r, $y, $z - $r, $x + $r, $y + $height, $z + $r);
		$this->lastUpdate = 0;
		$this->timings = new Navigation26TestTimer();
	}
	public function getId(){ return $this->testId; }
	public function isAlive(){ return $this->testAlive; }
	public function hasEffect($effectId){ return false; }
	public function setMotion(Vector3 $motion){ $this->motionX = $motion->x; $this->motionY = $motion->y; $this->motionZ = $motion->z; return true; }
	public function getName(){ return 'Navigation test mob'; }
	public function getLevel(){ return $this->level; }
	public function close(){}
	public function entityBaseTick($tickDiff = 1){ return true; }
	protected function checkChunks(){}
	protected function updateFallState($distanceThisTick, $onGround){}
	protected function updateMovement(){}
}

$checks = 0;
function navCheck(bool $ok, string $message) : void{
	global $checks;
	++$checks;
	if(!$ok){ throw new RuntimeException($message); }
}
function navWorld(int $minX = -12, int $maxX = 12, int $minZ = -12, int $maxZ = 12) : Navigation26TestLevel{
	$level = new Navigation26TestLevel();
	for($cx = $minX >> 4; $cx <= $maxX >> 4; ++$cx){ for($cz = $minZ >> 4; $cz <= $maxZ >> 4; ++$cz){ $level->addChunk($cx, $cz); } }
	$level->fillFloor($minX, $maxX, $minZ, $maxZ);
	return $level;
}

Block::init();
\pocketmine\event\Timings::$entityMoveTimer = new Navigation26TestTimer();

// A wall blocks the straight sweep; the bounded search must go around its ends.
$level = navWorld();
for($y = 1; $y <= 4; ++$y){ for($z = -2; $z <= 2; ++$z){ $level->set(3, $y, $z, Block::STONE); } }
$mob = new Navigation26TestMob(1, $level, 0.5, 1, 0.5);
navCheck(Navigation26::steer($mob, new Vector3(6.5, 1, 0.5), 0.18), 'A* did not route around a one-block wall');
$diag = Navigation26::diagnostics(1);
navCheck(in_array($diag['status'], ['path', 'direct'], true) && count($diag['path']) > 1, 'Wall route did not retain a multi-node path');
navCheck(abs($mob->motionX) + abs($mob->motionZ) > 0.001, 'Wall route produced no steering motion');
navCheck($diag['reached'] && max(array_map(function($p){ return abs($p[2]); }, $diag['path'])) >= 2.5, 'Tall-wall route did not go around an end');
navCheck($diag['nodes'] <= 256 && $diag['expanded'] <= 256, 'A* exceeded its fixed search budget');
$level->getServer()->tick = 9;
Navigation26::steer($mob, new Vector3(6.5, 1, -0.5), 0.18);
navCheck(Navigation26::diagnostics(1)['repaths'] === 1, 'Moving target caused a search before the 10-tick cadence');
$level->getServer()->tick = 10;
Navigation26::steer($mob, new Vector3(6.5, 1, -0.5), 0.18);
navCheck(Navigation26::diagnostics(1)['repaths'] === 2, 'Navigator did not refresh a moving target after 10 ticks');

// Closed doors stay solid despite legacy Door::canPassThrough(); navigation does not open them.
$level = navWorld();
$level->set(3, 1, 0, Block::WOOD_DOOR_BLOCK, 0);
$level->set(3, 2, 0, Block::WOOD_DOOR_BLOCK, 8);
navCheck(!Navigation26::canStand($level, 3.5, 1, 0.5, 0.6, 1.8), 'Closed door was treated as standable space');
$mob = new Navigation26TestMob(2, $level, 0.5, 1, 0.5);
navCheck(Navigation26::steer($mob, new Vector3(6.5, 1, 0.5), 0.15), 'Path around a closed door was not found');
navCheck(Navigation26::diagnostics(2)['blockedDoor'] !== null, 'Closed door coordinate was not diagnosed');
navCheck($level->getBlock(new Vector3(3, 1, 0))->getDamage() === 0, 'Navigation opened a door without a species goal');
$level->set(3, 1, 0, Block::WOOD_DOOR_BLOCK, 4);
navCheck(Navigation26::canStand($level, 3.5, 1, 0.5, 0.6, 1.8), 'Explicitly-open door did not become passable');
$level->set(4, 1, 0, Block::FENCE);
navCheck(!Navigation26::canStand($level, 4.5, 1, 0.5, 0.6, 1.8), 'Fence collision was ignored');

// A full-height unsupported trench is not crossed, and an ordinary 0.5-high stair is.
$level = navWorld(-8, 12, -20, 20);
for($z = -18; $z <= 18; ++$z){ $level->set(3, 0, $z, Block::AIR); }
$mob = new Navigation26TestMob(3, $level, 0.5, 1, 0.5);
Navigation26::steer($mob, new Vector3(6.5, 1, 0.5), 0.18);
navCheck(!Navigation26::canStand($level, 3.5, 1, 0.5, 0.6, 1.8), 'Full-height cliff trench was considered supported');
foreach(Navigation26::diagnostics(3)['path'] as $node){
	navCheck($node[0] < 2.95 || abs($node[2]) > 18, 'A* routed directly into the unsupported trench');
}
$level = navWorld(-4, 8, -4, 4);
$level->set(2, 0, 0, Block::WOOD_STAIRS, 0);
navCheck(Navigation26::canStand($level, 2.5, 0.5, 0.5, 0.6, 1.8), 'Legacy half-height stair top was not a valid support surface');
$level->set(3, 0, 0, Block::SLAB, 8);
navCheck(Navigation26::canStand($level, 3.5, 1, 0.5, 0.6, 1.8), 'Upper slab metadata did not supply a full-height floor');
$level = navWorld(-4, 8, -2, 2);
for($x = -1; $x <= 1; ++$x){ for($z = -1; $z <= 1; ++$z){ $level->set($x, 3, $z, Block::STONE); } }
$dropper = new Navigation26TestMob(30, $level, 1.5, 4, 0.5);
Navigation26::steer($dropper, new Vector3(5.5, 1, 0.5), 0.15);
navCheck(Navigation26::diagnostics(30)['reached'], 'Safe three-block dropdown did not produce a route');
for($x = -1; $x <= 1; ++$x){ for($z = -1; $z <= 1; ++$z){ $level->set($x, 3, $z, 0); $level->set($x, 4, $z, Block::STONE); } }
$dropper = new Navigation26TestMob(31, $level, 1.5, 5, 0.5);
Navigation26::steer($dropper, new Vector3(5.5, 1, 0.5), 0.15);
navCheck(!Navigation26::diagnostics(31)['reached'], 'Unsafe four-block dropdown was accepted');

// Water supports/floats a ground mob; lava and cactus remain excluded.
$level = navWorld();
$level->set(2, 0, 0, Block::STILL_WATER);
navCheck(Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Water was not accepted as floating support');
$level->set(2, 0, 0, Block::STILL_LAVA);
navCheck(!Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Lava was accepted as ground support');
$level->set(2, 0, 0, Block::CACTUS);
navCheck(!Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Cactus was accepted as ground support');
$level->set(2, 0, 0, Block::STONE);
$level->set(2, 1, 0, Block::FIRE);
navCheck(!Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Fire was accepted as a walk target');
$level->set(0, 1, 0, Block::STILL_WATER);
$floater = new Navigation26TestMob(32, $level, 0.5, 1, 0.5);
$floater->motionY = -0.3;
Navigation26::steer($floater, new Vector3(-3.5, 1, 0.5), 0.1);
navCheck($floater->motionY >= 0.12, 'Ground mob did not apply water buoyancy');

// Whole-body footprint rejects narrow openings and low head clearance.
$level = navWorld();
for($x = 0; $x <= 4; ++$x){ $level->set($x, 2, -1, Block::STONE); $level->set($x, 2, 1, Block::STONE); }
navCheck(Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Normal mob did not fit a 1-block corridor');
navCheck(!Navigation26::canStand($level, 2.5, 1, 0.5, 1.2, 1.8), 'Wide mob fit a corridor narrower than its AABB');
$level->set(2, 2, 0, Block::STONE);
navCheck(!Navigation26::canStand($level, 2.5, 1, 0.5, 0.6, 1.8), 'Tall mob fit below a low ceiling');

// Swimming is 3D and water-bound; air motion is stopped by the first collider.
$level = navWorld(-4, 8, -4, 4);
for($x = 0; $x <= 5; ++$x){ for($y = 1; $y <= 4; ++$y){ $level->set($x, $y, 0, Block::STILL_WATER); } }
$swimmer = new Navigation26TestMob(4, $level, 0.5, 1, 0.5);
navCheck(Navigation26::steer($swimmer, new Vector3(4.5, 3, 0.5), 0.12, 'swimming'), 'Water-bound 3D route was not found');
navCheck($swimmer->motionY > 0, 'Swimming controller did not steer vertically');
navCheck(!Navigation26::canOccupy($swimmer, new Vector3(4.5, 3, 1.5), 'swimming'), 'Swimmer accepted an air destination');
navCheck(!Navigation26::steer($swimmer, new Vector3(4.5, 3, 1.5), 0.12, 'swimming'), 'Swim steering accepted a dry target');
$level = navWorld(-4, 8, -5, 5);
for($y = 1; $y <= 5; ++$y){ for($z = -1; $z <= 1; ++$z){ $level->set(3, $y, $z, Block::STONE); } }
$flyer = new Navigation26TestMob(33, $level, 0.5, 2, 0.5, 0.6, 0.6);
navCheck(Navigation26::steer($flyer, new Vector3(6.5, 2, 0.5), 0.2, 'flying'), 'Flying mode did not find a 3D wall detour');
$flight = Navigation26::diagnostics(33);
navCheck($flight['reached'] && $flight['status'] === 'path', 'Flying mode treated a blocked straight sweep as clear');
navCheck(!Navigation26::canOccupy($flyer, new Vector3(3.5, 2, 0.5), 'flying'), 'Flying occupancy ignored wall collider');

$level = navWorld(-2, 15, -3, 3);
$level->set(3, 2, 0, Block::GLASS);
$a = new Navigation26TestMob(5, $level, 0.5, 1, 0.5);
$b = new Navigation26TestMob(6, $level, 6.5, 1, 0.5);
$c = new Navigation26TestMob(7, navWorld(), 2.5, 1, 0.5);
navCheck(!Navigation26::canSee($a, $b), 'Glass did not obstruct collider line of sight');
navCheck(!Navigation26::canSee($a, $c), 'Cross-world entities were visible');
$level->set(3, 2, 0, Block::AIR);
navCheck(Navigation26::canSee($a, $b), 'Clear loaded corridor was not visible');
navCheck(!Navigation26::canSee($a, $b, 4), 'Line of sight ignored its supplied range');
$level->set(3, 2, 0, Block::SLAB, 0);
navCheck(Navigation26::canSee($a, $b), 'Low slab blocked an eye ray above its collision box');
$level->set(3, 2, 0, Block::SLAB, 8);
navCheck(!Navigation26::canSee($a, $b), 'Upper slab failed to intercept the eye ray');
$edgeLevel = navWorld(-2, 15, -3, 3);
$edgeA = new Navigation26TestMob(8, $edgeLevel, 15.5, 1, 0.5);
$edgeB = new Navigation26TestMob(9, $edgeLevel, 16.5, 1, 0.5);
navCheck(!Navigation26::canSee($edgeA, $edgeB), 'Line of sight read an unloaded destination chunk');
$edgeMob = new Navigation26TestMob(10, $edgeLevel, 15.5, 1, 0.5);
navCheck(!Navigation26::steer($edgeMob, new Vector3(16.5, 1, 0.5), 0.1), 'Navigation entered an unloaded chunk');

// A jump may replace only downward velocity with its bounded takeoff impulse.
$level = navWorld();
$jumper = new Navigation26TestMob(11, $level, 0.5, 1, 0.5);
$jumper->motionY = -0.12;
navCheck(Navigation26::steer($jumper, new Vector3(5.5, 1, 0.5), 0.1), 'Flat ground steering failed');
navCheck(abs($jumper->motionY + 0.12) < 0.000001, 'Ground steering overwrote ordinary vertical motion');
// Exercise the real Creature::onUpdate + Entity::move path across a one-block
// platform. The fixture only removes unrelated networking/lifecycle work.
$level = navWorld(-4, 8, -2, 2);
for($x = 2; $x <= 6; ++$x){ for($z = -1; $z <= 1; ++$z){ $level->set($x, 1, $z, Block::STONE); } }
$jumper = new Navigation26TestMob(12, $level, 1.4, 1, 0.5);
$maxY = 1.0;
for($tick = 1; $tick <= 70; ++$tick){
	$level->getServer()->tick = $tick;
	Navigation26::steer($jumper, new Vector3(5.5, 2, 0.5), 0.18);
	$jumper->onUpdate($tick);
	$maxY = max($maxY, $jumper->y);
}
navCheck($maxY >= 2.0, 'Real Creature motor did not attain a one-block jump: maxY=' . $maxY);
navCheck($jumper->x > 3.0 && $jumper->y >= 1.99 && $jumper->y < 2.1, 'Real motor did not land on the platform: ' . json_encode([$jumper->x, $jumper->y, $jumper->z, Navigation26::diagnostics(12)]));

Navigation26::forget(1);
navCheck(Navigation26::diagnostics(1) === [], 'forget() retained per-mob diagnostics');
$level = navWorld(-2, 2, -2, 2);
for($i = 0; $i <= Navigation26::MAX_MOBS; ++$i){
	$id = 1000 + $i;
	$cached = new Navigation26TestMob($id, $level, 0.5, 1, 0.5);
	Navigation26::steer($cached, new Vector3(0.5, 1, 0.5), 0);
}
navCheck(Navigation26::diagnostics(1000) === [], 'Navigator cache failed to evict its oldest mob');
navCheck(Navigation26::diagnostics(1000 + Navigation26::MAX_MOBS) !== [], 'Navigator cache evicted its most recent mob');
navCheck(!Navigation26::canStand($level, NAN, 1, 0.5, 0.6, 1.8), 'Non-finite stand query was accepted');

echo 'Navigation26: PASS (' . $checks . ' behavior checks)' . PHP_EOL;
