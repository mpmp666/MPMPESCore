<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\generator\ender\noise\JavaRandom;

/**
 * Geometry port of the supplied Minecraft 26.2 EndCityPieces.
 *
 * Every build owns its random stream, ship flag and temporary piece groups.
 * Template pivots and inclusive collision boxes follow StructureTemplate; no
 * height, span or piece-count restriction is added to the source depth limit.
 */
final class EndCityLayout{
	private const MAX_GEN_DEPTH = 8;
	private const TOWER_BRIDGES = [[0, 1, -1, 0], [1, 6, -1, 1], [3, 0, -1, 5], [2, 5, -1, 6]];
	private const FAT_TOWER_BRIDGES = [[0, 4, -1, 0], [1, 12, -1, 4], [3, 0, -1, 8], [2, 8, -1, 12]];

	private JavaRandom $random;
	private array $templates;
	private bool $shipCreated = false;

	/** The JigsawLayout form retains StructureAssembler's existing entry point. */
	public function __construct(JigsawLayout|JavaRandom|int $source){
		$this->random = $source instanceof JavaRandom ? $source : new JavaRandom($source instanceof JigsawLayout ? $source->seed() : $source);
		$this->templates = StructureRepository::manifest()['templates'];
	}

	/**
	 * Manual assembly treats the supplied seed as the source random seed.
	 * Variants do not force ships or consume extra random draws in vanilla.
	 *
	 * @return list<array{template:string,x:int,y:int,z:int,rotation:int,ignore_air:bool,generation_depth:int}>
	 */
	public function build(string $variant = '') : array{
		return $this->buildAtRotation($this->random->nextInt(4));
	}

	/** Natural generation has already drawn Rotation.getRandom on this stream. */
	public function buildAtRotation(int $rotation) : array{
		$this->shipCreated = false;
		$pieces = [];
		$last = $this->add($pieces, $this->piece('base_floor', 0, 0, 0, $rotation, true));
		$last = $this->add($pieces, $this->relative($last, 'second_floor_1', -1, 0, -1, $rotation, false));
		$last = $this->add($pieces, $this->relative($last, 'third_floor_1', -1, 4, -1, $rotation, false));
		$last = $this->add($pieces, $this->relative($last, 'third_roof', -1, 8, -1, $rotation, true));
		$this->recursiveChildren('tower', 1, $last, null, $pieces);
		return array_map(static fn(\stdClass $piece) : array => [
			'template' => $piece->template, 'x' => $piece->x, 'y' => $piece->y, 'z' => $piece->z,
			'rotation' => $piece->rotation, 'ignore_air' => $piece->ignore_air, 'generation_depth' => $piece->generation_depth
		], $pieces);
	}

	private function piece(string $name, int $x, int $y, int $z, int $rotation, bool $overwrite) : \stdClass{
		$key = 'end_city/' . $name;
		if(!isset($this->templates[$key]['size'])){
			throw new \RuntimeException('Missing Minecraft 26.2 End City template: ' . $key);
		}
		[$w, $h, $l] = $this->templates[$key]['size'];
		[$dx, $dz] = StructureRepository::rotate($w - 1, $l - 1, $rotation);
		return (object)[
			'template' => $key, 'x' => $x, 'y' => $y, 'z' => $z, 'rotation' => $rotation & 3,
			// makeSettings(true) ignores structure blocks; false also ignores air.
			'ignore_air' => !$overwrite, 'generation_depth' => 0,
			'box' => [$x + min(0, $dx), $y, $z + min(0, $dz), $x + max(0, $dx), $y + $h - 1, $z + max(0, $dz)]
		];
	}

	private function relative(\stdClass $parent, string $name, int $x, int $y, int $z, int $rotation, bool $overwrite) : \stdClass{
		[$x, $z] = StructureRepository::rotate($x, $z, $parent->rotation);
		return $this->piece($name, $parent->x + $x, $parent->y + $y, $parent->z + $z, $rotation, $overwrite);
	}

	private function add(array &$pieces, \stdClass $piece) : \stdClass{
		$pieces[] = $piece;
		return $piece;
	}

	private function recursiveChildren(string $generator, int $depth, \stdClass $parent, ?array $offset, array &$pieces) : bool{
		if($depth > self::MAX_GEN_DEPTH){ return false; }
		$children = [];
		$generated = match($generator){
			'house' => $this->house($depth, $parent, $offset, $children),
			'tower' => $this->tower($depth, $parent, $children),
			'bridge' => $this->bridge($depth, $parent, $children),
			'fat_tower' => $this->fatTower($depth, $parent, $children),
			default => throw new \LogicException('Unknown End City section generator')
		};
		if(!$generated){ return false; }
		$tag = $this->random->nextInt();
		foreach($children as $child){
			$child->generation_depth = $tag;
			// Source findCollisionPiece returns only the FIRST intersecting piece.
			// In particular, do not skip a matching group and inspect later pieces.
			$collision = $this->findCollisionPiece($pieces, $child->box);
			if($collision !== null && $collision->generation_depth !== $parent->generation_depth){ return false; }
		}
		array_push($pieces, ...$children);
		return true;
	}

	private function findCollisionPiece(array $pieces, array $b) : ?\stdClass{
		foreach($pieces as $piece){
			$a = $piece->box;
			if($a[3] >= $b[0] && $a[0] <= $b[3] && $a[4] >= $b[1] && $a[1] <= $b[4] && $a[5] >= $b[2] && $a[2] <= $b[5]){
				return $piece;
			}
		}
		return null;
	}

	private function house(int $depth, \stdClass $parent, ?array $offset, array &$pieces) : bool{
		if($depth > self::MAX_GEN_DEPTH){ return false; }
		if($offset === null){ throw new \LogicException('End City house requires its source offset'); }
		$r = $parent->rotation;
		$last = $this->add($pieces, $this->relative($parent, 'base_floor', $offset[0], $offset[1], $offset[2], $r, true));
		$floors = $this->random->nextInt(3);
		if($floors === 0){
			$this->add($pieces, $this->relative($last, 'base_roof', -1, 4, -1, $r, true));
		}elseif($floors === 1){
			$last = $this->add($pieces, $this->relative($last, 'second_floor_2', -1, 0, -1, $r, false));
			$last = $this->add($pieces, $this->relative($last, 'second_roof', -1, 8, -1, $r, false));
			$this->recursiveChildren('tower', $depth + 1, $last, null, $pieces);
		}else{
			$last = $this->add($pieces, $this->relative($last, 'second_floor_2', -1, 0, -1, $r, false));
			$last = $this->add($pieces, $this->relative($last, 'third_floor_2', -1, 4, -1, $r, false));
			$last = $this->add($pieces, $this->relative($last, 'third_roof', -1, 8, -1, $r, true));
			$this->recursiveChildren('tower', $depth + 1, $last, null, $pieces);
		}
		return true;
	}

	private function tower(int $depth, \stdClass $parent, array &$pieces) : bool{
		$r = $parent->rotation;
		$last = $this->add($pieces, $this->relative($parent, 'tower_base', 3 + $this->random->nextInt(2), -3, 3 + $this->random->nextInt(2), $r, true));
		$last = $this->add($pieces, $this->relative($last, 'tower_piece', 0, 7, 0, $r, true));
		$bridgePiece = $this->random->nextInt(3) === 0 ? $last : null;
		$height = 1 + $this->random->nextInt(3);
		for($i = 0; $i < $height; ++$i){
			$last = $this->add($pieces, $this->relative($last, 'tower_piece', 0, 4, 0, $r, true));
			if($i < $height - 1 && $this->random->nextBoolean()){ $bridgePiece = $last; }
		}
		if($bridgePiece !== null){
			foreach(self::TOWER_BRIDGES as [$turn, $x, $y, $z]){
				if($this->random->nextBoolean()){
					$start = $this->add($pieces, $this->relative($bridgePiece, 'bridge_end', $x, $y, $z, ($r + $turn) & 3, true));
					$this->recursiveChildren('bridge', $depth + 1, $start, null, $pieces);
				}
			}
			$this->add($pieces, $this->relative($last, 'tower_top', -1, 4, -1, $r, true));
		}else{
			if($depth !== 7){ return $this->recursiveChildren('fat_tower', $depth + 1, $last, null, $pieces); }
			$this->add($pieces, $this->relative($last, 'tower_top', -1, 4, -1, $r, true));
		}
		return true;
	}

	private function bridge(int $depth, \stdClass $parent, array &$pieces) : bool{
		$r = $parent->rotation;
		$length = $this->random->nextInt(4) + 1;
		$last = $this->add($pieces, $this->relative($parent, 'bridge_piece', 0, 0, -4, $r, true));
		$last->generation_depth = -1;
		$nextY = 0;
		for($i = 0; $i < $length; ++$i){
			if($this->random->nextBoolean()){
				$last = $this->add($pieces, $this->relative($last, 'bridge_piece', 0, $nextY, -4, $r, true));
				$nextY = 0;
			}else{
				if($this->random->nextBoolean()){
					$last = $this->add($pieces, $this->relative($last, 'bridge_steep_stairs', 0, $nextY, -4, $r, true));
				}else{
					$last = $this->add($pieces, $this->relative($last, 'bridge_gentle_stairs', 0, $nextY, -8, $r, true));
				}
				$nextY = 4;
			}
		}
		if(!$this->shipCreated && $this->random->nextInt(10 - $depth) === 0){
			$this->add($pieces, $this->relative($last, 'ship', -8 + $this->random->nextInt(8), $nextY, -70 + $this->random->nextInt(10), $r, true));
			// This remains set even if a containing recursive group is rejected.
			$this->shipCreated = true;
		}elseif(!$this->recursiveChildren('house', $depth + 1, $last, [-3, $nextY + 1, -11], $pieces)){
			return false;
		}
		$last = $this->add($pieces, $this->relative($last, 'bridge_end', 4, $nextY, 0, ($r + 2) & 3, true));
		$last->generation_depth = -1;
		return true;
	}

	private function fatTower(int $depth, \stdClass $parent, array &$pieces) : bool{
		$r = $parent->rotation;
		$last = $this->add($pieces, $this->relative($parent, 'fat_tower_base', -3, 4, -3, $r, true));
		$last = $this->add($pieces, $this->relative($last, 'fat_tower_middle', 0, 4, 0, $r, true));
		for($i = 0; $i < 2 && $this->random->nextInt(3) !== 0; ++$i){
			$last = $this->add($pieces, $this->relative($last, 'fat_tower_middle', 0, 8, 0, $r, true));
			foreach(self::FAT_TOWER_BRIDGES as [$turn, $x, $y, $z]){
				if($this->random->nextBoolean()){
					$start = $this->add($pieces, $this->relative($last, 'bridge_end', $x, $y, $z, ($r + $turn) & 3, true));
					$this->recursiveChildren('bridge', $depth + 1, $start, null, $pieces);
				}
			}
		}
		$this->add($pieces, $this->relative($last, 'fat_tower_top', -2, 8, -2, $r, true));
		return true;
	}
}
