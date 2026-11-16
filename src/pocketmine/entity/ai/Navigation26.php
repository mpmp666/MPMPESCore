<?php

namespace pocketmine\entity\ai;

use pocketmine\block\Block;
use pocketmine\entity\Creature;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

/**
 * Shared bounded navigation and collider-based sight for the legacy entity motor.
 *
 * Inspired by 26.2 PathNavigation, WalkNodeEvaluator and MoveControl: cached paths,
 * whole-body clearance, floor heights, bounded falls, waypoint following and jumps.
 * This is an adaptation to Genisys' single-box block shapes, not Java physics.
 *
 * steer() takes an ACTUAL horizontal speed in blocks per game tick (typically
 * 0.10 walking, 0.20 chasing), not a movement-attribute multiplier. Creature owns
 * movement, gravity and friction. Ground steering preserves motionY except for a
 * 0.42 jump or water buoyancy; air/swim steering controls all three motion axes.
 * Closed doors are blocked even though legacy Door::canPassThrough() returns true.
 * A species' OpenDoor goal must open the door first; this class never edits blocks.
 */
final class Navigation26{
	const MAX_NODES = 256;
	const PATH_RADIUS = 16;
	const REPATH_TICKS = 10;
	const MAX_MOBS = 512;
	const MAX_QUERY_BLOCKS = 16384;
	const EPSILON = 0.0001;

	/** Routes/diagnostics only; no entity, level or chunk references survive a call. */
	private static $states = [];

	public static function forget(int $eid) : void{
		unset(self::$states[$eid]);
	}

	/** A bounded copy suitable for an AI debug command. Positions are [x,y,z]. */
	public static function diagnostics(int $eid) : array{
		if(!isset(self::$states[$eid])){
			return [];
		}
		$s = self::$states[$eid];
		return [
			'mode' => $s['mode'], 'status' => $s['status'], 'lastRepath' => $s['lastRepath'],
			'repaths' => $s['repaths'], 'expanded' => $s['expanded'], 'nodes' => $s['nodes'],
			'reached' => $s['reached'], 'waypoint' => $s['index'], 'path' => $s['path'],
			'blockedDoor' => $s['door'], 'stuckTicks' => $s['stuck'],
		];
	}

	public static function getDiagnostics(int $eid) : array{
		return self::diagnostics($eid);
	}

	public static function steer(Creature $mob, Vector3 $target, float $speed, string $mode = 'ground') : bool{
		$eid = $mob->getId();
		$level = $mob->getLevel();
		if($mob->closed || !$mob->isAlive() || !$level instanceof Level){
			self::forget($eid);
			return false;
		}
		if(!self::finitePosition($mob) || !self::finitePosition($target) || !is_finite($speed)
			|| ($target instanceof Position && $target->getLevel() !== null && $target->getLevel() !== $level)){
			return false;
		}
		$mode = self::mode($mode);
		if($mode !== 'ground' && $mode !== 'air' && $mode !== 'swim'){
			return false;
		}
		$size = self::size($mob);
		if($size === null){
			return false;
		}
		list($width, $height) = $size;
		$ctx = self::context($level, $width, $height, self::fireImmune($mob), $mode);
		$tick = (int) $level->getServer()->getTick();
		$signature = spl_object_hash($level) . ':' . $width . ':' . $height . ':' . (int) $ctx['fire'];
		$state = self::$states[$eid] ?? null;
		if($state === null || $state['world'] !== $signature || $state['mode'] !== $mode){
			$state = [
				'world' => $signature, 'mode' => $mode, 'status' => 'new', 'lastRepath' => $tick - self::REPATH_TICKS,
				'repaths' => 0, 'expanded' => 0, 'nodes' => 0, 'reached' => false, 'index' => 0, 'path' => [],
				'target' => [$target->x, $target->y, $target->z], 'door' => null, 'stuck' => 0,
				'lastTick' => $tick, 'lastPos' => [$mob->x, $mob->y, $mob->z],
			];
		}
		$dt = max(0, min(20, $tick - $state['lastTick']));
		$travel = ($mob->x - $state['lastPos'][0]) ** 2 + ($mob->y - $state['lastPos'][1]) ** 2 + ($mob->z - $state['lastPos'][2]) ** 2;
		$state['stuck'] = $travel < 0.0004 && $speed > 0 ? min(200, $state['stuck'] + $dt) : 0;
		$state['lastTick'] = $tick;
		$state['lastPos'] = [$mob->x, $mob->y, $mob->z];
		$speed = max(0.0, min(1.0, $speed));
		$dx = $target->x - $mob->x;
		$dy = $target->y - $mob->y;
		$dz = $target->z - $mob->z;
		if($speed <= 0.00001 || ($dx * $dx + $dz * $dz < 0.0225 && abs($dy) < ($mode === 'ground' ? 0.55 : 0.15))){
			$state['status'] = 'arrived';
			self::remember($eid, $state);
			return self::motion($mob, $ctx, 0, 0, 0, $mode, false);
		}

		// Movement checks use only chunks already in Level::getChunks(). In particular
		// isChunkLoaded() also checks the provider; getChunk(false) can load from disk.
		if(!self::bodyClear($ctx, $mob->x, $mob->y, $mob->z, $mode === 'swim')){
			$state['status'] = 'invalid-start';
			self::remember($eid, $state);
			self::motion($mob, $ctx, 0, 0, 0, $mode, false);
			return false;
		}
		if($mode === 'swim' && !self::waterBody($ctx, $target->x, $target->y, $target->z)){
			$state['status'] = 'target-out-of-water';
			self::remember($eid, $state);
			self::motion($mob, $ctx, 0, 0, 0, $mode, false);
			return false;
		}
		$distance = sqrt($dx * $dx + $dz * $dz + ($mode === 'ground' ? 0 : $dy * $dy));
		$scale = $distance > self::PATH_RADIUS ? self::PATH_RADIUS / $distance : 1.0;
		$goal = [$mob->x + $dx * $scale, $mode === 'ground' ? $target->y : $mob->y + $dy * $scale, $mob->z + $dz * $scale];
		$from = [$mob->x, $mob->y, $mob->z];

		// Long straight walks need no graph search, but every portion still needs
		// support, hazard checks and the mob's full swept volume, including its head.
		$direct = $mode === 'ground'
			? abs($goal[1] - $mob->y) < 0.55 && self::groundLine($ctx, $from, [$goal[0], $mob->y, $goal[2]])
			: self::sweep($ctx, $from, $goal, $mode === 'swim');
		if($direct){
			$state['status'] = 'direct';
			$state['path'] = [$mode === 'ground' ? [$goal[0], $mob->y, $goal[2]] : $goal];
			$state['index'] = 0;
			$state['reached'] = $scale === 1.0;
		}else{
			$due = $tick < $state['lastRepath'] || $tick - $state['lastRepath'] >= self::REPATH_TICKS;
			if($due){
				$search = self::findPath($ctx, $from, $goal);
				$state['path'] = $search['path'];
				$state['index'] = 0;
				$state['expanded'] = $search['expanded'];
				$state['nodes'] = $search['nodes'];
				$state['reached'] = $search['reached'];
				$state['lastRepath'] = $tick;
				$state['target'] = [$target->x, $target->y, $target->z];
				$state['repaths']++;
				$state['door'] = $ctx['door'];
			}
			$state['status'] = count($state['path']) ? 'path' : 'blocked';
		}

		$waypoint = null;
		$reach = min(0.30, max(0.12, $speed * 1.25));
		for($i = $state['index'], $n = count($state['path']); $i < $n; ++$i){
			$p = $state['path'][$i];
			$horizontal = ($p[0] - $mob->x) ** 2 + ($p[2] - $mob->z) ** 2;
			if($horizontal <= $reach * $reach && abs($p[1] - $mob->y) < ($mode === 'ground' ? 0.55 : $reach)){
				$state['index'] = $i + 1;
				continue;
			}
			$waypoint = $p;
			break;
		}
		if($waypoint === null){
			$state['status'] = $state['reached'] ? 'arrived' : 'blocked';
			self::remember($eid, $state);
			self::motion($mob, $ctx, 0, 0, 0, $mode, false);
			return false;
		}
		$jump = false;
		if($mode === 'ground'){
			if(!self::groundTransition($ctx, $from, $waypoint)){
				$state['status'] = 'obstructed';
				self::remember($eid, $state);
				self::motion($mob, $ctx, 0, 0, 0, $mode, false);
				return false;
			}
			$jump = $waypoint[1] > $mob->y + 0.05 && $mob->onGround;
			$dy = 0.0;
		}else{
			if(!self::sweep($ctx, $from, $waypoint, $mode === 'swim')){
				$state['status'] = 'obstructed';
				self::remember($eid, $state);
				self::motion($mob, $ctx, 0, 0, 0, $mode, false);
				return false;
			}
			$dy = $waypoint[1] - $mob->y;
		}
		$dx = $waypoint[0] - $mob->x;
		$dz = $waypoint[2] - $mob->z;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		$factor = $length > 0.00001 ? min($speed, $length) / $length : 0;
		$mx = $dx * $factor;
		$my = $dy * $factor;
		$mz = $dz * $factor;
		// Check the immediate physical move too; jumps first rise vertically before
		// entering the obstacle, rather than pushing the mob into its lower face.
		if($jump && !self::bodyClear($ctx, $mob->x + $mx, $mob->y, $mob->z + $mz, false)){
			$mx = $mz = 0.0;
		}
		self::remember($eid, $state);
		return self::motion($mob, $ctx, $mx, $my, $mz, $mode, $jump);
	}

	/** A full collider ray: glass obstructs sight, fluids and plants do not. */
	public static function canSee(Entity $from, Entity $to, float $range = 64.0) : bool{
		$level = $from->getLevel();
		if(!$level instanceof Level || $level !== $to->getLevel() || $from->closed || $to->closed
			|| !$from->isAlive() || !$to->isAlive() || !is_finite($range) || $range <= 0
			|| !self::finitePosition($from) || !self::finitePosition($to)){
			return false;
		}
		$range = min(128.0, $range);
		if($from->distanceSquared($to) > $range * $range){
			return false;
		}
		$a = [$from->x, $from->y + $from->getEyeHeight(), $from->z];
		$b = [$to->x, $to->y + $to->getEyeHeight(), $to->z];
		if(!is_finite($a[1]) || !is_finite($b[1])){
			return false;
		}
		$ctx = self::context($level, 0.1, 0.1, false, 'air');
		$x = (int) floor($a[0]); $y = (int) floor($a[1]); $z = (int) floor($a[2]);
		$end = [(int) floor($b[0]), (int) floor($b[1]), (int) floor($b[2])];
		$d = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
		$step = []; $next = []; $delta = [];
		foreach($d as $axis => $value){
			$step[$axis] = $value > 0 ? 1 : ($value < 0 ? -1 : 0);
			$delta[$axis] = $value == 0 ? INF : abs(1 / $value);
			$cell = (int) floor($a[$axis]);
			$next[$axis] = $value == 0 ? INF : (($value > 0 ? $cell + 1 : $cell) - $a[$axis]) / $value;
		}
		// The bound exceeds the maximum cells of a 128-block diagonal ray.
		for($i = 0; $i < 512; ++$i){
			if(self::raw($ctx, $x, $y, $z) === null){ return false; }
			foreach(self::shapes($ctx, $x, $y, $z, true) as $bb){
				if(self::rayBox($a, $b, $bb)){ return false; }
			}
			// Fence/wall collision boxes extend a half block into the cell above.
			if($y > 0){
				foreach(self::shapes($ctx, $x, $y - 1, $z, true) as $bb){
					if($bb->maxY > $y && self::rayBox($a, $b, $bb)){ return false; }
				}
			}
			if($x === $end[0] && $y === $end[1] && $z === $end[2]){ return true; }
			$t = min($next);
			if($t > 1 || !is_finite($t)){ return true; }
			// Visit tied axes individually so an exact voxel edge cannot skip a wall.
			if($next[0] <= $next[1] && $next[0] <= $next[2]){ $x += $step[0]; $next[0] += $delta[0]; }
			elseif($next[1] <= $next[2]){ $y += $step[1]; $next[1] += $delta[1]; }
			else{ $z += $step[2]; $next[2] += $delta[2]; }
		}
		return false;
	}

	/** Feet position with whole-body clearance and support (water can support floating). */
	public static function canStand(Level $level, float $x, float $y, float $z, float $width, float $height) : bool{
		if(!is_finite($x) || !is_finite($y) || !is_finite($z) || !self::validSize($width, $height)){
			return false;
		}
		$ctx = self::context($level, $width, $height, false, 'ground');
		return self::stand($ctx, $x, $y, $z);
	}

	/** Loaded-chunk occupancy query for goals choosing 3D destinations. */
	public static function canOccupy(Creature $mob, Vector3 $target, string $mode = 'ground') : bool{
		$level = $mob->getLevel();
		$mode = self::mode($mode);
		$size = self::size($mob);
		if(!$level instanceof Level || $size === null || !self::finitePosition($target)
			|| ($target instanceof Position && $target->getLevel() !== null && $target->getLevel() !== $level)){
			return false;
		}
		$ctx = self::context($level, $size[0], $size[1], self::fireImmune($mob), $mode);
		return $mode === 'ground' ? self::stand($ctx, $target->x, $target->y, $target->z)
			: (($mode === 'air' || $mode === 'swim') && self::bodyClear($ctx, $target->x, $target->y, $target->z, $mode === 'swim'));
	}

	private static function mode(string $mode) : string{
		if($mode === 'flying'){ return 'air'; }
		if($mode === 'swimming'){ return 'swim'; }
		return $mode === 'climbing' || $mode === 'hopping' ? 'ground' : $mode;
	}

	private static function context(Level $level, float $width, float $height, bool $fire, string $mode) : array{
		return ['level' => $level, 'chunks' => $level->getChunks(), 'width' => $width, 'height' => $height,
			'fire' => $fire, 'mode' => $mode, 'raw' => [], 'shapes' => [], 'door' => null];
	}

	private static function remember(int $eid, array $state) : void{
		unset(self::$states[$eid]);
		self::$states[$eid] = $state;
		if(count(self::$states) > self::MAX_MOBS){
			reset(self::$states);
			unset(self::$states[key(self::$states)]);
		}
	}

	private static function finitePosition(Vector3 $v) : bool{
		return is_finite((float) $v->x) && is_finite((float) $v->y) && is_finite((float) $v->z)
			&& abs($v->x) <= 30000000 && abs($v->z) <= 30000000 && $v->y >= 0 && $v->y < 128;
	}

	private static function validSize(float $width, float $height) : bool{
		return is_finite($width) && is_finite($height) && $width > 0 && $width <= 16 && $height > 0 && $height <= 32;
	}

	private static function size(Entity $mob){
		$bb = $mob->getBoundingBox();
		$width = $bb instanceof AxisAlignedBB ? max($bb->maxX - $bb->minX, $bb->maxZ - $bb->minZ) : (float) $mob->width;
		$height = $bb instanceof AxisAlignedBB ? $bb->maxY - $bb->minY : (float) $mob->height;
		return self::validSize($width, $height) ? [$width, $height] : null;
	}

	private static function fireImmune(Creature $mob) : bool{
		return (method_exists($mob, 'isFireProof') && $mob->isFireProof()) || $mob->hasEffect(Effect::FIRE_RESISTANCE)
			|| $mob instanceof \pocketmine\entity\Blaze || $mob instanceof \pocketmine\entity\Ghast
			|| $mob instanceof \pocketmine\entity\LavaSlime || $mob instanceof \pocketmine\entity\PigZombie;
	}

	/** null means unknown/unloaded/outside the legacy world's 0..127 range. */
	private static function raw(array &$ctx, int $x, int $y, int $z){
		if($y < 0 || $y >= 128 || !isset($ctx['chunks'][Level::chunkHash($x >> 4, $z >> 4)])){
			return null;
		}
		$key = $x . ':' . $y . ':' . $z;
		if(!isset($ctx['raw'][$key])){
			if(count($ctx['raw']) >= self::MAX_QUERY_BLOCKS){ return null; }
			$ctx['raw'][$key] = $ctx['chunks'][Level::chunkHash($x >> 4, $z >> 4)]->getFullBlock($x & 15, $y, $z & 15);
		}
		return $ctx['raw'][$key];
	}

	private static function isDoor(int $id) : bool{
		return $id === 64 || $id === 71 || ($id >= 193 && $id <= 197);
	}

	private static function water(int $id) : bool{ return $id === 8 || $id === 9; }

	private static function dangerous(int $id, bool $fire) : bool{
		return $id === 81 || (!$fire && ($id === 10 || $id === 11 || $id === 51 || $id === 213));
	}

	/**
	 * Respect actual Genisys shapes (including half-height legacy stairs), with
	 * explicit door policy. Never call a position-dependent shape without a level.
	 */
	private static function shapes(array &$ctx, int $x, int $y, int $z, bool $sight = false) : array{
		$key = $x . ':' . $y . ':' . $z . ':' . (int) $sight;
		if(isset($ctx['shapes'][$key])){ return $ctx['shapes'][$key]; }
		$full = self::raw($ctx, $x, $y, $z);
		if($full === null){ return [new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1)]; }
		$id = $full >> 4;
		if($id === 0 || self::water($id) || $id === 10 || $id === 11 || $id === 51){ return []; }
		$block = Block::get($id, $full & 15, new Position($x, $y, $z, $ctx['level']));
		if(self::isDoor($id)){
			$lower = ($full & 8) ? self::raw($ctx, $x, $y - 1, $z) : $full;
			$open = $lower !== null && ($lower >> 4) === $id && ($lower & 4) !== 0;
			if(!$sight && !$open){
				$ctx['door'] = [$x, ($full & 8) ? $y - 1 : $y, $z];
				return $ctx['shapes'][$key] = [new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1)];
			}
		}else{
			if($block->canPassThrough()){ return []; }
		}
		$bb = $block->getBoundingBox();
		return $ctx['shapes'][$key] = $bb === null ? [] : [$bb];
	}

	private static function box(array &$ctx, float $x, float $y, float $z) : AxisAlignedBB{
		$r = $ctx['width'] / 2 - self::EPSILON;
		return new AxisAlignedBB($x - $r, $y + self::EPSILON, $z - $r, $x + $r, $y + $ctx['height'] - self::EPSILON, $z + $r);
	}

	private static function bodyClear(array &$ctx, float $x, float $y, float $z, bool $waterOnly) : bool{
		$bb = self::box($ctx, $x, $y, $z);
		if($bb->minY < 0 || $bb->maxY >= 128){ return false; }
		for($xx = (int) floor($bb->minX); $xx <= (int) floor($bb->maxX); ++$xx){
			for($zz = (int) floor($bb->minZ); $zz <= (int) floor($bb->maxZ); ++$zz){
				for($yy = max(0, (int) floor($bb->minY) - 1); $yy <= (int) floor($bb->maxY); ++$yy){
					$full = self::raw($ctx, $xx, $yy, $zz);
					if($full === null){ return false; }
					$id = $full >> 4;
					if($yy + 1 > $bb->minY && (self::dangerous($id, $ctx['fire']) || ($waterOnly && !self::water($id)))){ return false; }
					foreach(self::shapes($ctx, $xx, $yy, $zz) as $shape){
						if($shape->intersectsWith($bb)){ return false; }
					}
				}
			}
		}
		return true;
	}

	private static function waterBody(array &$ctx, float $x, float $y, float $z) : bool{
		return self::bodyClear($ctx, $x, $y, $z, true);
	}

	private static function stand(array &$ctx, float $x, float $y, float $z) : bool{
		if(!self::bodyClear($ctx, $x, $y, $z, false)){ return false; }
		// All footprint corners must have support: wide mobs cannot balance a tiny
		// center block across a cliff, and diagonals cannot cut unsupported corners.
		$r = max(0, $ctx['width'] / 2 - 0.05);
		foreach([[-$r, -$r], [-$r, $r], [$r, -$r], [$r, $r], [0, 0]] as $offset){
			$px = $x + $offset[0]; $pz = $z + $offset[1];
			$xx = (int) floor($px); $zz = (int) floor($pz);
			$supported = false;
			for($yy = max(0, (int) floor($y) - 2); $yy <= (int) floor($y); ++$yy){
				$full = self::raw($ctx, $xx, $yy, $zz);
				if($full === null){ return false; }
				$id = $full >> 4;
				if(self::dangerous($id, $ctx['fire']) && $yy + 1 >= $y - 0.1){ return false; }
				if((self::water($id) || ($ctx['fire'] && ($id === 10 || $id === 11))) && $yy <= $y && $yy + 1 >= $y - 0.05){
					$supported = true;
				}
				foreach(self::shapes($ctx, $xx, $yy, $zz) as $bb){
					if(abs($bb->maxY - $y) <= 0.06 && $px >= $bb->minX && $px <= $bb->maxX && $pz >= $bb->minZ && $pz <= $bb->maxZ){ $supported = true; }
				}
			}
			if(!$supported){ return false; }
		}
		return true;
	}

	/** Fixed spacing <= 0.20 prevents tunneling through thin legacy colliders. */
	private static function sweep(array &$ctx, array $a, array $b, bool $waterOnly) : bool{
		$distance = sqrt(self::distanceSquared($a, $b));
		$steps = max(1, (int) ceil($distance / 0.20));
		if($steps > 256){ return false; }
		for($i = 1; $i <= $steps; ++$i){
			$t = $i / $steps;
			if(!self::bodyClear($ctx, $a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t, $waterOnly)){ return false; }
		}
		return true;
	}

	private static function groundLine(array &$ctx, array $a, array $b) : bool{
		$steps = max(1, (int) ceil(sqrt(self::distanceSquared($a, $b)) / 0.20));
		if($steps > 128){ return false; }
		for($i = 1; $i <= $steps; ++$i){
			$t = $i / $steps;
			if(!self::stand($ctx, $a[0] + ($b[0] - $a[0]) * $t, $a[1], $a[2] + ($b[2] - $a[2]) * $t)){ return false; }
		}
		return true;
	}

	private static function groundTransition(array &$ctx, array $a, array $b) : bool{
		$dy = $b[1] - $a[1];
		if($dy > 1.001 || $dy < -3.001 || !self::stand($ctx, $b[0], $b[1], $b[2])){ return false; }
		if(abs($dy) <= 0.06){ return self::groundLine($ctx, $a, $b); }
		// Raise at the source before stepping up; walk off the edge before dropping.
		if($dy > 0){
			$raised = [$a[0], $b[1], $a[2]];
			return self::sweep($ctx, $a, $raised, false) && self::sweep($ctx, $raised, $b, false);
		}
		$edge = [$b[0], $a[1], $b[2]];
		return self::sweep($ctx, $a, $edge, false) && self::sweep($ctx, $edge, $b, false);
	}

	private static function floorAt(array &$ctx, float $x, float $z, float $reference){
		$heights = [];
		$r = $ctx['width'] / 2 - self::EPSILON;
		for($xx = (int) floor($x - $r); $xx <= (int) floor($x + $r); ++$xx){
			for($zz = (int) floor($z - $r); $zz <= (int) floor($z + $r); ++$zz){
				for($yy = max(0, (int) floor($reference - 4)); $yy <= min(127, (int) floor($reference + 1)); ++$yy){
					$full = self::raw($ctx, $xx, $yy, $zz);
					if($full === null){ continue; }
					$id = $full >> 4;
					if(self::water($id) || ($ctx['fire'] && ($id === 10 || $id === 11))){
						$h = min($reference, $yy + 1.0);
						$heights[(string) $h] = $h;
					}
					foreach(self::shapes($ctx, $xx, $yy, $zz) as $bb){ $heights[(string) $bb->maxY] = $bb->maxY; }
				}
			}
		}
		usort($heights, function($a, $b) use ($reference){ return abs($a - $reference) <=> abs($b - $reference); });
		foreach($heights as $y){
			if($y <= $reference + 1.001 && $y >= $reference - 3.001 && self::stand($ctx, $x, $y, $z)){ return (float) $y; }
		}
		return null;
	}

	private static function findPath(array &$ctx, array $from, array $goal) : array{
		$start = [(int) floor($from[0]) + 0.5, $from[1], (int) floor($from[2]) + 0.5];
		if($ctx['mode'] === 'ground'){
			$y = self::floorAt($ctx, $start[0], $start[2], $from[1]);
			if($y === null){ return ['path' => [], 'expanded' => 0, 'nodes' => 0, 'reached' => false]; }
			$start[1] = $y;
		}else{
			$start[1] = floor($from[1] * 2) / 2;
		}
		if($ctx['mode'] === 'ground' ? !self::groundTransition($ctx, $from, $start) : !self::sweep($ctx, $from, $start, $ctx['mode'] === 'swim')){
			return ['path' => [], 'expanded' => 0, 'nodes' => 0, 'reached' => false];
		}
		$startKey = self::nodeKey($start);
		$h = self::heuristic($start, $goal);
		$nodes = [$startKey => ['p' => $start, 'g' => 0.0, 'h' => $h, 'parent' => null, 'closed' => false]];
		$open = new \SplPriorityQueue();
		$open->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
		$serial = 0;
		$open->insert($startKey, [-$h, -$serial]);
		$best = $startKey; $expanded = 0; $reached = false;
		$directions = [[1,0,0], [0,0,1], [-1,0,0], [0,0,-1]];
		if($ctx['mode'] !== 'ground'){ $directions[] = [0,1,0]; $directions[] = [0,-1,0]; }
		while(!$open->isEmpty() && $expanded < self::MAX_NODES){
			$key = $open->extract();
			if($nodes[$key]['closed']){ continue; }
			$nodes[$key]['closed'] = true;
			++$expanded;
			$p = $nodes[$key]['p'];
			if($nodes[$key]['h'] < $nodes[$best]['h']){ $best = $key; }
			if(abs($p[0] - $goal[0]) <= 0.55 && abs($p[2] - $goal[2]) <= 0.55 && abs($p[1] - $goal[1]) <= 0.55){
				$best = $key; $reached = true; break;
			}
			foreach($directions as $d){
				$q = [$p[0] + $d[0], $p[1] + $d[1], $p[2] + $d[2]];
				if(($q[0] - $from[0]) ** 2 + ($q[2] - $from[2]) ** 2 > self::PATH_RADIUS ** 2 || abs($q[1] - $from[1]) > self::PATH_RADIUS){ continue; }
				if($ctx['mode'] === 'ground'){
					$y = self::floorAt($ctx, $q[0], $q[2], $p[1]);
					if($y === null){ continue; }
					$q[1] = $y;
					if(!self::groundTransition($ctx, $p, $q)){ continue; }
				}else{
					if(!self::sweep($ctx, $p, $q, $ctx['mode'] === 'swim')){ continue; }
				}
				$qk = self::nodeKey($q);
				if(isset($nodes[$qk]) && $nodes[$qk]['closed']){ continue; }
				$g = $nodes[$key]['g'] + 1.0 + abs($q[1] - $p[1]) * 0.4;
				$full = self::raw($ctx, (int) floor($q[0]), (int) floor($q[1]), (int) floor($q[2]));
				if($ctx['mode'] === 'ground' && $full !== null && self::water($full >> 4)){ $g += 1.5; }
				if(!isset($nodes[$qk])){
					if(count($nodes) >= self::MAX_NODES){ continue; }
					$nodes[$qk] = ['p' => $q, 'g' => INF, 'h' => self::heuristic($q, $goal), 'parent' => null, 'closed' => false];
				}
				if($g >= $nodes[$qk]['g']){ continue; }
				$nodes[$qk]['g'] = $g;
				$nodes[$qk]['parent'] = $key;
				$open->insert($qk, [-($g + $nodes[$qk]['h'] * 1.05), -(++$serial)]);
			}
		}
		$path = [];
		for($key = $best, $i = 0; $key !== null && $i < self::MAX_NODES; $key = $nodes[$key]['parent'], ++$i){ $path[] = $nodes[$key]['p']; }
		$path = array_reverse($path);
		if(count($path) === 1 && self::distanceSquared($path[0], $from) < 0.05){ $path = []; }
		return ['path' => $path, 'expanded' => $expanded, 'nodes' => count($nodes), 'reached' => $reached];
	}

	private static function nodeKey(array $p) : string{ return $p[0] . ':' . $p[1] . ':' . $p[2]; }
	private static function heuristic(array $a, array $b) : float{ return abs($a[0] - $b[0]) + abs($a[2] - $b[2]) + abs($a[1] - $b[1]) * 0.6; }
	private static function distanceSquared(array $a, array $b) : float{ return ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2; }

	private static function motion(Creature $mob, array &$ctx, float $x, float $y, float $z, string $mode, bool $jump) : bool{
		if($mode === 'ground'){
			$y = $mob->motionY;
			$feet = self::raw($ctx, (int) floor($mob->x), (int) floor($mob->y + 0.2), (int) floor($mob->z));
			if($feet !== null && self::water($feet >> 4) && self::bodyClear($ctx, $mob->x, $mob->y + 0.15, $mob->z, false)){
				$y = max($y, 0.12);
			}elseif($jump){ $y = max($y, 0.42); }
		}
		if(abs($x) + abs($z) > 0.00001){
			$wantedYaw = rad2deg(atan2(-$x, $z));
			$turn = fmod($wantedYaw - $mob->yaw + 540, 360) - 180;
			$mob->yaw = fmod($mob->yaw + max(-90, min(90, $turn)) + 360, 360);
		}
		if($mode !== 'ground' && abs($x) + abs($y) + abs($z) > 0.00001){ $mob->pitch = -rad2deg(atan2($y, sqrt($x * $x + $z * $z))); }
		return $mob->setMotion(new Vector3($x, $y, $z)) !== false;
	}

	private static function rayBox(array $a, array $b, AxisAlignedBB $bb) : bool{
		$min = [$bb->minX, $bb->minY, $bb->minZ]; $max = [$bb->maxX, $bb->maxY, $bb->maxZ];
		$enter = 0.0; $leave = 1.0;
		for($i = 0; $i < 3; ++$i){
			$d = $b[$i] - $a[$i];
			if(abs($d) < 1.0e-10){ if($a[$i] < $min[$i] || $a[$i] > $max[$i]){ return false; } continue; }
			$t1 = ($min[$i] - $a[$i]) / $d; $t2 = ($max[$i] - $a[$i]) / $d;
			if($t1 > $t2){ $tmp = $t1; $t1 = $t2; $t2 = $tmp; }
			$enter = max($enter, $t1); $leave = min($leave, $t2);
			if($enter > $leave){ return false; }
		}
		return $leave >= 0.00001 && $enter <= 0.99999;
	}
}
