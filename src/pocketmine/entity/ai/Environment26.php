<?php

namespace pocketmine\entity\ai;

use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;

/** Loaded-only environment queries. Level::getChunk(false) can still load disk chunks. */
final class Environment26{
	public static function chunk($level, int $x, int $z){
		$chunks = $level->getChunks();
		return $chunks[\pocketmine\level\Level::chunkHash($x, $z)] ?? null;
	}

	/** Null means unknown, never air. */
	public static function block($level, int $x, int $y, int $z){
		if($y < 0 || $y > 127){
			return null;
		}
		$chunk = self::chunk($level, $x >> 4, $z >> 4);
		return $chunk === null ? null : $chunk->getBlockId($x & 15, $y, $z & 15);
	}

	public static function loadedBox($level, float $minX, float $minZ, float $maxX, float $maxZ) : bool{
		for($x = ((int) floor($minX)) >> 4, $endX = ((int) floor($maxX)) >> 4; $x <= $endX; ++$x){
			for($z = ((int) floor($minZ)) >> 4, $endZ = ((int) floor($maxZ)) >> 4; $z <= $endZ; ++$z){
				if(self::chunk($level, $x, $z) === null){
					return false;
				}
			}
		}
		return true;
	}

	/** Legacy Level entity queries use an inclusive ceil on their upper chunk. */
	public static function loadedEntityQuery($level, float $minX, float $minZ, float $maxX, float $maxZ) : bool{
		for($x = (int) floor(($minX - 2) / 16), $endX = (int) ceil(($maxX + 2) / 16); $x <= $endX; ++$x){
			for($z = (int) floor(($minZ - 2) / 16), $endZ = (int) ceil(($maxZ + 2) / 16); $z <= $endZ; ++$z){
				if(self::chunk($level, $x, $z) === null){ return false; }
			}
		}
		return true;
	}

	public static function explosionLoaded(Entity $source, float $size) : bool{
		$radius = $size * 3 + 3;
		return self::loadedBox($source->getLevel(), $source->x - $radius, $source->z - $radius, $source->x + $radius, $source->z + $radius)
			&& self::loadedEntityQuery($source->getLevel(), floor($source->x - $size * 2 - 1), floor($source->z - $size * 2 - 1), ceil($source->x + $size * 2 + 1), ceil($source->z + $size * 2 + 1));
	}

	/** At most 512 inspected entities and 256 results, at most 64 blocks away. */
	public static function nearby(Entity $from, float $range) : array{
		$range = max(1.0, min(64.0, $range));
		$level = $from->getLevel();
		$minX = ((int) floor($from->x - $range)) >> 4;
		$maxX = ((int) floor($from->x + $range)) >> 4;
		$minZ = ((int) floor($from->z - $range)) >> 4;
		$maxZ = ((int) floor($from->z + $range)) >> 4;
		$result = [];
		$seen = 0;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$chunk = self::chunk($level, $x, $z);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getEntities() as $entity){
					if(++$seen > 512){
						return $result;
					}
					if($entity === $from || $entity->closed || $entity->getLevel() !== $level){
						continue;
					}
					$dx = $entity->x - $from->x;
					$dy = $entity->y - $from->y;
					$dz = $entity->z - $from->z;
					if($dx * $dx + $dy * $dy + $dz * $dz <= $range * $range){
						$result[$entity->getId()] = $entity;
						if(count($result) >= 256){
							return $result;
						}
					}
				}
			}
		}
		return $result;
	}

	/** Roof test over the actual loaded column, including glass and leaves. */
	public static function canSeeSky(Entity $entity) : bool{
		$level = $entity->getLevel();
		$x = (int) floor($entity->x);
		$z = (int) floor($entity->z);
		$chunk = self::chunk($level, $x >> 4, $z >> 4);
		if($chunk === null){
			return false;
		}
		$eye = new Vector3($entity->x, $entity->y + $entity->getEyeHeight(), $entity->z);
		if(method_exists($level, 'canSeeSky')){
			return $level->canSeeSky($eye);
		}
		for($y = max(0, (int) floor($eye->y)); $y <= 127; ++$y){
			$id = $chunk->getBlockId($x & 15, $y, $z & 15);
			// Actual overhead blocks shelter; tiny non-roof plants/torches do not.
			if($id !== 0 && !in_array($id, [6, 31, 32, 37, 38, 39, 40, 50, 51, 55, 59, 63, 65, 66, 68, 75, 76, 83, 104, 105, 106, 115, 141, 142, 171, 175, 244], true)){
				return false;
			}
		}
		return true;
	}

	/** Full footprint + support test for exceptional Enderman teleports. */
	public static function canStand(Entity $entity, Vector3 $position) : bool{
		$level = $entity->getLevel();
		$half = max(0.2, $entity->width / 2);
		$height = max(1.0, $entity->height);
		if($position->y < 1 || $position->y + $height >= 128 || !self::loadedBox($level, $position->x - $half - 1, $position->z - $half - 1, $position->x + $half + 1, $position->z + $half + 1)){
			return false;
		}
		for($x = (int) floor($position->x - $half); $x <= (int) floor($position->x + $half); ++$x){
			for($z = (int) floor($position->z - $half); $z <= (int) floor($position->z + $half); ++$z){
				$support = self::block($level, $x, (int) floor($position->y) - 1, $z);
				if($support === null || in_array($support, [0, 8, 9, 10, 11, 51, 81], true)){
					return false;
				}
				$block = $level->getBlock(new Vector3($x, (int) floor($position->y) - 1, $z));
				if(!$block->isSolid()){
					return false;
				}
			}
		}
		$bb = new AxisAlignedBB($position->x - $half, $position->y + 0.01, $position->z - $half, $position->x + $half, $position->y + $height, $position->z + $half);
		for($x = (int) floor($bb->minX); $x <= (int) floor($bb->maxX); ++$x){
			for($z = (int) floor($bb->minZ); $z <= (int) floor($bb->maxZ); ++$z){
				for($y = (int) floor($bb->minY); $y <= (int) floor($bb->maxY); ++$y){
					$id = self::block($level, $x, $y, $z);
					if($id === null || in_array($id, [8, 9, 10, 11, 51, 81], true)){
						return false;
					}
					if($id !== 0 && $level->getBlock(new Vector3($x, $y, $z))->collidesWithBB($bb)){
						return false;
					}
				}
			}
		}
		return true;
	}

	/** Refuse paths through unknown chunks before any teleport/collision API. */
	public static function loadedPath(Entity $entity, Vector3 $to, float $margin = 1.5) : bool{
		$dx = $to->x - $entity->x;
		$dz = $to->z - $entity->z;
		$steps = (int) ceil(max(abs($dx), abs($dz)) / 2);
		if($steps > 64){
			return false;
		}
		for($i = 0; $i <= max(1, $steps); ++$i){
			$t = $i / max(1, $steps);
			$x = $entity->x + $dx * $t;
			$z = $entity->z + $dz * $t;
			if(!self::loadedBox($entity->getLevel(), $x - $margin, $z - $margin, $x + $margin, $z + $margin)){
				return false;
			}
		}
		return true;
	}
}
