<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

/** Compact two-storey mansion using the vanilla eight-block grid and shell pivots. */
final class MansionLayout{
	private JigsawLayout $layout;
	private array $pieces = [];
	private int $rotation = 0;

	public function __construct(JigsawLayout $layout){ $this->layout = $layout; }

	public function build(string $variant = '') : array{
		$this->rotation = $this->layout->random(4);
		$this->add('entrance', -9, 0, 0);
		for($floor = 0; $floor < 2; ++$floor){
			$y = $floor * 8;
			$door = 'indoors_door_' . ($floor + 1);
			$wall = $floor === 0 ? 'wall_flat' : 'wall_window';
			// Four west-side rooms open east into the stair hall and back corridors.
			foreach([0, 8, 16, 24] as $z){
				$room = $floor === 0 ? '1x1_a' . (1 + $this->layout->random(5)) : '1x1_b' . (1 + $this->layout->random(5));
				$this->add($room, -16, $y, $z);
				$this->add($door, -9, $y, $z);
			}
			foreach([16, 24] as $z){
				$this->add('corridor_floor', -8, $y, $z);
				$this->add('corridor_floor', 0, $y, $z);
				// Vanilla carpet joins contain the floor strip between seven-wide tiles.
				$this->add('carpet_west_' . ($floor + 1), -1, $y, $z - 1);
			}
			$this->add('carpet_south_' . ($floor + 1), -9, $y, 21);
			$this->add('carpet_south_' . ($floor + 1), -1, $y, 21);
			// Exact source wall orientation: unrotated wall is the EAST boundary.
			foreach([0, 8, 16, 24] as $z){ $this->add($wall, -17, $y, $z + 7, 2); }
			foreach([-16, -8, 0] as $x){ $this->add($wall, $x + 7, $y, 31, 1); }
			foreach([16, 24] as $z){ $this->add($wall, 7, $y, $z); }
			// The entrance owns the remaining north and east shell and the staircase.
			$this->add($wall, -16, $y, -1, 3);
		}
		// Shells must be placed after interiors: their intentional overlaps close
		// seams, and may never be dropped by a template bounding-box collision test.
		foreach([0, 8, 16, 24] as $z){
			foreach([-16, -8, 0] as $x){ $this->add('roof', $x, 19, $z); }
			$this->add('roof_front', -16, 16, $z + 7, 2);
			$this->add('roof_front', 6, 16, $z);
		}
		foreach([-16, -8, 0] as $x){
			$this->add('roof_front', $x - 1, 16, 0, 3);
			$this->add('roof_front', $x + 6, 16, 30, 1);
		}
		return $this->pieces;
	}

	private function add(string $name, int $x, int $y, int $z, int $rotation = 0) : void{
		$key = 'woodland_mansion/' . $name;
		if(!$this->layout->exists($key)){ throw new \RuntimeException('Missing mansion template: ' . $key); }
		[$x, $z] = JigsawLayout::rotate($x, $z, $this->rotation);
		$this->pieces[] = $this->layout->piece($key, $x, $y, $z, ($rotation + $this->rotation) & 3);
	}
}
