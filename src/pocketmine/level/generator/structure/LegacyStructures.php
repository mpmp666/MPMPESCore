<?php

namespace pocketmine\level\generator\structure;

/**
 * Bounded procedural adaptations of Java's non-NBT structure families.
 *
 * Geometry references: the uploaded MCP-Reborn-26.2 source, under
 * net/minecraft/world/level/levelgen/structure/structures/{DesertPyramidPiece,
 * JungleTemplePiece, SwampHutPiece, StrongholdPieces, MineshaftPieces,
 * NetherFortressPieces, OceanMonumentPieces, BuriedTreasurePieces}.java and
 * feature/{EndPodiumFeature, EndGatewayFeature, DesertWellFeature,
 * MonsterRoomFeature}.java. These are recognizable, connected, finite subsets
 * of those buildings; they do not reproduce Java's recursive piece selection,
 * terrain fitting, archaeology, loot tables, entities or structure algorithms.
 *
 * All states use the numeric MCPE IDs in this tree's block/Block.php. Unsupported
 * prismarine/dark prismarine/sea lanterns become stone/stone bricks/glowstone;
 * the end portal surface becomes obsidian and the gateway core glowstone.
 * Those two end features are architectural monuments, not teleport providers.
 * Legacy stairs have no explicit Java corner shape. Piston puzzles are replaced
 * with accessible treasure alcoves and working legacy pressure-plate traps.
 *
 * Coordinates include explicit air for rooms and passages, but omitted exterior
 * cells are left to the placement engine. Y=0 is the bottom of the template:
 * desert pyramid ground is Y=14, jungle temple ground Y=4, hut deck Y=4,
 * stronghold/mineshaft floor Y=1, fortress bridge deck Y=8, monument base Y=0.
 * Place underground families accordingly; no terrain is queried here.
 *
 * Each palette entry contains four [id, meta] pairs for 0/90/180/270 degree
 * clockwise rotation, matching (x,z) -> (length - 1 - z,x). blockData consists
 * of little-endian pack('v4', x, y, z, paletteIndex) records, one per final cell.
 * Tile NBT is a plain array with legacy numeric item IDs and EntityId values.
 * Inventory lists must be encoded as compound lists by the placement engine.
 * Seed randomness is local; generation never changes PHP's global RNG state.
 */
final class LegacyStructures{
	private static array $templateCache = [];
	private static int $templateCacheBytes = 0;
	private const TEMPLATE_CACHE_LIMIT = 16777216;

	/** @var int[] Final cell state, keyed by (y << 14) | (z << 7) | x. */
	private $cells = [];
	/** @var array[] */
	private $tiles = [];
	/** @var int[] */
	private $size;
	/** @var int */
	private $groundLevel = 0;
	/** @var int */
	private $random;

	public static function families() : array{
		return [
			'desert_pyramid', 'jungle_temple', 'swamp_hut', 'stronghold',
			'mineshaft', 'nether_fortress', 'ocean_monument', 'buried_treasure',
			'end_exit_portal', 'end_gateway', 'desert_well', 'bonus_dungeon'
		];
	}

	public static function template(string $family, int $seed = 0) : array{
		$cacheKey = $family . ':' . $seed;
		if(isset(self::$templateCache[$cacheKey])){
			$value = self::$templateCache[$cacheKey];
			unset(self::$templateCache[$cacheKey]);
			self::$templateCache[$cacheKey] = $value;
			return $value;
		}
		$methods = [
			'desert_pyramid' => 'desertPyramid', 'jungle_temple' => 'jungleTemple',
			'swamp_hut' => 'swampHut', 'stronghold' => 'stronghold',
			'mineshaft' => 'mineshaft', 'nether_fortress' => 'netherFortress',
			'ocean_monument' => 'oceanMonument', 'buried_treasure' => 'buriedTreasure',
			'end_exit_portal' => 'endExitPortal', 'end_gateway' => 'endGateway',
			'desert_well' => 'desertWell', 'bonus_dungeon' => 'bonusDungeon'
		];
		if(!isset($methods[$family])){
			throw new \InvalidArgumentException('Unknown procedural structure family: ' . $family);
		}
		$builder = new self($seed);
		$builder->{$methods[$family]}();
		$value = $builder->finish();
		$bytes = strlen($value['blockData']) + count($value['palette']) * 32 + count($value['tiles']) * 256;
		$value['_cache_bytes'] = $bytes;
		while(self::$templateCache !== [] && self::$templateCacheBytes + $bytes > self::TEMPLATE_CACHE_LIMIT){
			$oldest = array_key_first(self::$templateCache);
			self::$templateCacheBytes -= self::$templateCache[$oldest]['_cache_bytes'];
			unset(self::$templateCache[$oldest]);
		}
		if($bytes <= self::TEMPLATE_CACHE_LIMIT){
			self::$templateCache[$cacheKey] = $value;
			self::$templateCacheBytes += $bytes;
		}
		return $value;
	}

	private function __construct(int $seed){
		$this->random = ($seed & 0x7fffffff) ?: 1;
	}

	private function begin(int $width, int $height, int $length, int $groundLevel = 0){
		if($width < 1 || $width > 96 || $length < 1 || $length > 96 || $height < 1 || $height > 128 || $groundLevel < 0 || $groundLevel >= $height){
			throw new \LogicException('Procedural structure dimensions exceed legacy limits');
		}
		$this->size = [$width, $height, $length];
		$this->groundLevel = $groundLevel;
	}

	private function next(int $bound) : int{
		$this->random = ($this->random * 1103515245 + 12345) & 0x7fffffff;
		return (($this->random >> 8) & 0x7fffff) % $bound;
	}

	private function put(int $x, int $y, int $z, int $id, int $meta = 0){
		if($x < 0 || $y < 0 || $z < 0 || $x >= $this->size[0] || $y >= $this->size[1] || $z >= $this->size[2]){
			throw new \LogicException('Out-of-bounds procedural structure block at ' . $x . ',' . $y . ',' . $z);
		}
		if($id < 0 || $id > 255 || $meta < 0 || $meta > 15){
			throw new \LogicException('Invalid legacy block state');
		}
		$this->cells[($y << 14) | ($z << 7) | $x] = ($id << 4) | $meta;
	}

	private function fill(int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $id, int $meta = 0){
		for($y = $y0; $y <= $y1; ++$y){
			for($z = $z0; $z <= $z1; ++$z){
				for($x = $x0; $x <= $x1; ++$x){
					$this->put($x, $y, $z, $id, $meta);
				}
			}
		}
	}

	/** A shell also writes its interior, making carved underground rooms usable. */
	private function box(int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $id, int $meta = 0, int $inside = 0){
		for($y = $y0; $y <= $y1; ++$y){
			for($z = $z0; $z <= $z1; ++$z){
				for($x = $x0; $x <= $x1; ++$x){
					$edge = $x === $x0 || $x === $x1 || $y === $y0 || $y === $y1 || $z === $z0 || $z === $z1;
					$this->put($x, $y, $z, $edge ? $id : $inside, $edge ? $meta : 0);
				}
			}
		}
	}

	/** Cobble/moss or stone-brick ageing, with no holes in the structural shell. */
	private function weather(int $id){
		foreach($this->cells as $key => $state){
			if(($state >> 4) !== $id){
				continue;
			}
			$roll = $this->next(10);
			if($id === 4 && $roll < 5){
				$this->cells[$key] = 48 << 4;
			}elseif($id === 98 && $roll < 4){
				$this->cells[$key] = (98 << 4) | ($roll < 2 ? 1 : 2);
			}
		}
	}

	private function loot(string $kind) : array{
		$tables = [
			'temple' => [[265, 1, 5], [266, 1, 4], [264, 1, 2], [352, 3, 8], [287, 2, 6], [367, 3, 8], [388, 1, 3], [322, 1, 1]],
			'library' => [[340, 2, 6], [339, 3, 8], [345, 1, 1], [331, 2, 6], [266, 1, 3]],
			'mine' => [[265, 2, 6], [263, 3, 8], [66, 4, 12], [297, 1, 4], [264, 1, 2], [362, 2, 5], [361, 2, 5]],
			'fortress' => [[266, 1, 5], [265, 1, 4], [264, 1, 2], [259, 1, 1], [377, 2, 5], [372, 2, 7], [329, 1, 1]],
			'treasure' => [[264, 1, 3], [266, 3, 8], [265, 3, 9], [388, 2, 5], [267, 1, 1], [297, 2, 5]],
			'dungeon' => [[329, 1, 1], [265, 1, 4], [297, 1, 4], [331, 2, 6], [289, 2, 6], [287, 2, 6], [322, 1, 1]]
		];
		$table = $tables[$kind];
		$items = [];
		$count = 3 + $this->next(3);
		for($slot = 0; $slot < $count; ++$slot){
			$entry = $table[$this->next(count($table))];
			$items[] = ['id' => $entry[0], 'Damage' => 0, 'Count' => $entry[1] + $this->next($entry[2] - $entry[1] + 1), 'Slot' => $slot];
		}
		return $items;
	}

	private function chest(int $x, int $y, int $z, int $facing, string $loot = 'temple'){
		$this->put($x, $y, $z, 54, $facing);
		$this->tiles[] = ['pos' => [$x, $y, $z], 'name' => 'Chest', 'nbt' => ['Items' => $this->loot($loot)]];
	}

	private function dispenser(int $x, int $y, int $z, int $facing){
		$this->put($x, $y, $z, 23, $facing);
		$this->tiles[] = ['pos' => [$x, $y, $z], 'name' => 'Dispenser', 'nbt' => ['Items' => [
			['id' => 262, 'Damage' => 0, 'Count' => 12 + $this->next(13), 'Slot' => 0]
		]]];
	}

	private function spawner(int $x, int $y, int $z, int $entityId){
		$this->put($x, $y, $z, 52);
		$this->tiles[] = ['pos' => [$x, $y, $z], 'name' => 'MobSpawner', 'nbt' => [
			'EntityId' => $entityId, 'Delay' => 100 + $this->next(200), 'MinSpawnDelay' => 200,
			'MaxSpawnDelay' => 799, 'SpawnCount' => 4, 'SpawnRange' => 4
		]];
	}

	private function door(int $x, int $y, int $z, int $facing, bool $iron = false){
		$this->put($x, $y, $z, $iron ? 71 : 64, $facing);
		$this->put($x, $y + 1, $z, $iron ? 71 : 64, 8);
	}

	private function finish() : array{
		ksort($this->cells, SORT_NUMERIC);
		$palette = [];
		$indices = [];
		$data = '';
		foreach($this->cells as $key => $state){
			if(!isset($indices[$state])){
				$indices[$state] = count($palette);
				$id = $state >> 4;
				$meta = $state & 15;
				$rotations = [];
				for($r = 0; $r < 4; ++$r){
					$rotations[] = [$id, $meta];
					$meta = self::rotateMeta($id, $meta);
				}
				$palette[] = $rotations;
			}
			$data .= pack('v4', $key & 127, $key >> 14, ($key >> 7) & 127, $indices[$state]);
		}
		return ['format' => 1, 'size' => $this->size, 'ground_level' => $this->groundLevel, 'palette' => $palette,
			'blockData' => $data, 'tiles' => $this->tiles, 'entities' => [], 'markers' => [], 'connectors' => []];
	}

	/** Legacy metadata rotation; vertical/upper/hinge/power bits are preserved. */
	private static function rotateMeta(int $id, int $meta) : int{
		if(in_array($id, [53, 67, 108, 109, 114, 128, 134, 135, 136, 156, 163, 164, 180], true)){
			// Stair metadata follows the server's east/west/south/north convention.
			return ($meta & 12) | [0 => 2, 1 => 3, 2 => 1, 3 => 0][$meta & 3];
		}
		if(in_array($id, [23, 54, 61, 62, 68, 125, 146, 154], true)){
			return ($meta & 8) | ([2 => 5, 5 => 3, 3 => 4, 4 => 2][$meta & 7] ?? ($meta & 7));
		}
		if($id === 65){
			return [2 => 5, 5 => 3, 3 => 4, 4 => 2][$meta] ?? $meta;
		}
		if(in_array($id, [50, 75, 76], true)){
			return ($meta & 8) | ([1 => 3, 3 => 2, 2 => 4, 4 => 1][$meta & 7] ?? ($meta & 7));
		}
		if(in_array($id, [77, 143], true)){
			return ($meta & 8) | ([2 => 4, 4 => 3, 3 => 5, 5 => 2][$meta & 7] ?? ($meta & 7));
		}
		if(in_array($id, [64, 71, 193, 194, 195, 196, 197], true)){
			return ($meta & 8) ? $meta : (($meta & 12) | (($meta + 1) & 3));
		}
		if($id === 66){
			return [1, 0, 5, 4, 2, 3, 7, 8, 9, 6][$meta] ?? $meta;
		}
		if(in_array($id, [27, 28, 126], true)){
			return ($meta & 8) | ([1, 0, 5, 4, 2, 3][$meta & 7] ?? ($meta & 7));
		}
		if(in_array($id, [96, 167], true)){
			// MCPE trapdoor attachment: east, west, south, north (not Java's table).
			return ($meta & 12) | [2, 3, 1, 0][$meta & 3];
		}
		if(in_array($id, [86, 91, 93, 94, 107, 183, 184, 185, 186, 187], true)){
			return ($meta & 12) | [0 => 1, 1 => 2, 2 => 3, 3 => 0][$meta & 3];
		}
		if(in_array($id, [120, 127, 131], true)){
			return ($meta & 12) | ((($meta & 3) + 1) & 3);
		}
		if(in_array($id, [68, 63], true)){
			return (($meta + 2) & 15);
		}
		if($id === 69){
			return ($meta & 8) | ([0 => 7, 7 => 1, 1 => 6, 6 => 0, 2 => 5, 5 => 3, 3 => 4, 4 => 2][$meta & 7]);
		}
		if(in_array($id, [17, 162, 170], true)){
			$axis = $meta & 12;
			return ($meta & 3) | ($axis === 4 ? 8 : ($axis === 8 ? 4 : $axis));
		}
		if($id === 106){
			return (($meta << 1) & 15) | (($meta >> 3) & 1);
		}
		return $meta;
	}

	private function desertPyramid(){
		$this->begin(21, 30, 21, 18);
		// A sand-filled plinth anchors the stepped pyramid; carving starts at ground.
		$this->fill(0, 14, 0, 20, 18, 20, 12);
		$this->fill(7, 4, 7, 13, 17, 13, 24, 2);
		$this->fill(8, 5, 8, 12, 17, 12, 0);
		for($step = 1; $step <= 9; ++$step){
			$this->fill($step, 18 + $step, $step, 20 - $step, 18 + $step, 20 - $step, 24);
			if($step < 9){
				$this->fill($step + 1, 18 + $step, $step + 1, 19 - $step, 18 + $step, 19 - $step, 0);
			}
		}
		foreach([0, 16] as $x){
			$this->box($x, 18, 0, $x + 4, 27, 4, 24);
			$this->fill($x + 1, 28, 1, $x + 3, 28, 3, 24, 2);
			$this->put($x + 2, 28, 0, 128, 2);
			$this->put($x + 2, 28, 4, 128, 3);
			$this->put($x, 28, 2, 128, 0);
			$this->put($x + 4, 28, 2, 128, 1);
			// Orange ankh motifs on the two facade towers.
			foreach([20, 21, 23, 25] as $y){
				$this->put($x + 2, $y, 0, 159, 1);
			}
			foreach([18, 20] as $y){
				$this->fill($x + 1, $y, 0, $x + 3, $y, 0, 159, 1);
				$this->put($x + 2, $y, 0, 24, 1);
			}
			$this->fill($x + 1, 22, 0, $x + 3, 22, 0, 24, 2);
		}
		$this->box(8, 18, 0, 12, 22, 5, 24);
		$this->fill(9, 19, 0, 11, 21, 7, 0);
		$this->fill(4, 19, 2, 8, 20, 2, 0);
		$this->fill(12, 19, 2, 16, 20, 2, 0);
		$this->fill(8, 22, 0, 12, 24, 0, 24, 2);
		$this->put(9, 23, 0, 159, 1);
		$this->put(10, 23, 0, 24, 1);
		$this->put(11, 23, 0, 159, 1);
		foreach([8, 12] as $x){
			foreach([8, 12] as $z){
				$this->fill($x, 19, $z, $x, 21, $z, 24, 2);
			}
		}
		// Cross-shaped orange floor mosaic with a sealed, accessible treasure shaft.
		for($d = -3; $d <= 3; ++$d){
			$this->put(10 + $d, 18, 10, 159, 1);
			$this->put(10, 18, 10 + $d, 159, 1);
		}
		foreach([[9, 9], [11, 9], [9, 11], [11, 11]] as $p){
			$this->put($p[0], 18, $p[1], 159, 1);
		}
		$this->box(7, 1, 7, 11, 4, 13, 4, 0, 0);
		$this->fill(8, 2, 8, 10, 4, 12, 0);
		$this->fill(7, 2, 9, 7, 3, 11, 0);
		$this->fill(11, 2, 9, 11, 3, 11, 0);
		$this->fill(8, 2, 7, 10, 3, 7, 0);
		$this->fill(8, 2, 13, 10, 3, 13, 0);
		$this->put(9, 1, 3, 4);
		$this->put(9, 1, 4, 4);
		$this->put(9, 1, 5, 4);
		$this->put(9, 1, 6, 4);
		$this->put(8, 1, 6, 0);
		$this->put(7, 1, 6, 0);
		$this->put(7, 1, 7, 0);
		$this->put(9, 2, 3, 23);
		$this->put(9, 2, 3, 23);
		$this->put(9, 2, 3, 23);
		$this->chest(8, 1, 3, 2);
		$this->chest(9, 1, 10, 3);
		// High slits distinguish the four sides of the stepped sanctuary.
		$this->fill(10, 21, 3, 10, 22, 3, 0);
		$this->fill(3, 21, 10, 3, 22, 10, 0);
		$this->fill(17, 21, 10, 17, 22, 10, 0);
		$this->fill(10, 21, 17, 10, 22, 17, 0);
		// Entrance stair is open to air in front, unlike the guarded upper shell.
		for($step = 0; $step < 7; ++$step){
			$this->fill(9, 17 + $step, 6 + $step, 11, 17 + $step, 6 + $step, 24);
			$this->fill(9, 18 + $step, 6 + $step, 11, 24, 6 + $step, 0);
		}
	}

	private function jungleTemple(){
		$this->begin(12, 15, 15, 4);
		$this->fill(0, 0, 0, 11, 4, 14, 4);
		$this->fill(3, 3, 0, 8, 4, 0, 0);
		// Ground-level doorway opens to a short covered cobblestone entry tunnel.
		$this->fill(4, 5, 0, 7, 6, 2, 0);
		$this->fill(4, 5, 2, 7, 5, 4, 48);
		$this->box(2, 5, 2, 9, 6, 12, 4);
		$this->fill(3, 5, 3, 8, 6, 11, 0);
		$this->box(1, 7, 1, 10, 10, 13, 4);
		$this->fill(2, 7, 2, 9, 7, 12, 4);
		$this->fill(3, 11, 3, 8, 11, 11, 4);
		$this->fill(4, 12, 4, 7, 12, 10, 4);
		$this->fill(5, 13, 6, 6, 13, 8, 4);
		foreach([2, 9] as $x){
			foreach([2, 12] as $z){
				$this->fill($x, 11, $z, $x, 13, $z, 4);
			}
		}
		// Repeated pilasters and slit windows around the upper gallery.
		foreach([3, 5, 7, 9, 11] as $z){
			$this->fill(0, 7, $z, 0, 9, $z, 4);
			$this->fill(11, 7, $z, 11, 9, $z, 4);
		}
		$this->fill(4, 8, 1, 7, 9, 1, 0);
		$this->fill(4, 8, 13, 7, 9, 13, 0);
		foreach([3, 8] as $x){
			$this->fill($x, 8, 1, $x, 9, 1, 0);
			$this->fill($x, 8, 13, $x, 9, 13, 0);
		}
		$this->fill(5, 5, 2, 6, 6, 3, 0);
		$this->fill(5, 8, 1, 6, 9, 1, 0);
		$this->fill(5, 7, 1, 6, 7, 1, 0);
		$this->fill(5, 7, 1, 6, 7, 13, 0);
		for($x = 4; $x <= 7; ++$x){
			$this->put($x, 4, 0, 67, 2);
		}
		foreach([4, 7] as $x){
			for($step = 0; $step < 3; ++$step){
				$this->fill($x, 5, 8 + $step, $x, 5 + $step, 8 + $step, 4);
				$this->put($x, 5 + $step, 8 + $step, 67, 2);
				$this->fill($x, 6 + $step, 8 + $step, $x, 9, 8 + $step, 0);
			}
		}
		$this->put(5, 13, 6, 67, 2);
		$this->put(6, 13, 6, 67, 2);
		$this->put(5, 13, 8, 67, 3);
		$this->put(6, 13, 8, 67, 3);
		// Continuous buried trap corridor and accessible rear treasure chamber.
		$this->fill(1, 1, 1, 3, 3, 13, 0);
		$this->fill(1, 1, 1, 9, 3, 5, 0);
		$this->fill(1, 1, 12, 10, 3, 13, 0);
		$this->fill(7, 1, 8, 10, 3, 13, 0);
		for($step = 0; $step < 4; ++$step){
			$y = 4 - $step;
			$z = 6 + $step;
			$this->fill(5, $y + 1, $z, 6, 6, $z, 0);
			$this->fill(5, $y - 1, $z, 6, $y - 1, $z, 4);
			$this->fill(5, $y, $z, 6, $y, $z, 67, 3);
		}
		// Covered, two-block-high basement reaches the rear corridor and both rooms.
		$this->fill(1, 1, 1, 3, 3, 13, 0);
		$this->fill(1, 1, 1, 9, 3, 5, 0);
		$this->fill(1, 1, 12, 10, 3, 13, 0);
		$this->fill(7, 1, 8, 10, 3, 13, 0);
		// First treasure room (Java chest site 8,-3,3; y origin is +4).
		$this->fill(7, 0, 2, 10, 0, 5, 48);
		$this->fill(7, 1, 2, 7, 3, 5, 0);
		$this->chest(8, 1, 3, 2);
		// Hidden-room pistons are replaced by a visible cut-cobble lintel and cache.
		$this->fill(8, 0, 9, 10, 0, 11, 48);
		$this->fill(8, 1, 10, 10, 3, 11, 0);
		$this->chest(9, 1, 10, 4);
		// Tripwire is decorative and supported; the separate dispenser remains live.
		$this->fill(7, 1, 1, 7, 1, 5, 4);
		$this->put(7, 2, 1, 131, 2);
		$this->put(7, 2, 5, 131, 0);
		$this->fill(7, 2, 2, 7, 2, 4, 132);
		$this->put(7, 3, 1, 4);
		$this->put(7, 3, 5, 4);
		// Trap dispenser faces along the passage; its arrow payload is generated above.
		$this->fill(3, 0, 2, 4, 0, 4, 48);
		$this->dispenser(3, 1, 3, 4);
		// The three-lever puzzle survives as a facade beside an open alcove.
		$this->fill(6, 1, 12, 6, 3, 14, 4);
		$this->fill(6, 1, 13, 6, 2, 13, 0);
		$this->put(7, 2, 12, 69, 1);
		$this->put(7, 2, 13, 69, 1);
		$this->put(7, 2, 14, 69, 1);
		$this->weather(4);
	}

	private function swampHut(){
		$this->begin(7, 9, 9, 4);
		$this->groundLevel = 5;
		$this->fill(1, 4, 1, 5, 4, 7, 5, 1);
		$this->fill(2, 4, 0, 4, 4, 0, 5, 1);
		$this->fill(1, 7, 2, 5, 7, 7, 5, 1);
		$this->fill(2, 5, 2, 3, 6, 2, 5, 1);
		$this->fill(1, 5, 3, 1, 6, 6, 5, 1);
		$this->fill(5, 5, 3, 5, 6, 6, 5, 1);
		$this->fill(2, 5, 7, 4, 6, 7, 5, 1);
		$this->fill(2, 5, 3, 4, 6, 6, 0);
		foreach([1, 5] as $x){
			foreach([2, 7] as $z){
				$this->fill($x, 0, $z, $x, 6, $z, 17);
			}
		}
		$this->put(1, 5, 1, 85);
		$this->put(5, 5, 1, 85);
		$this->fill(1, 3, 0, 2, 3, 0, 0);
		$this->fill(4, 3, 0, 5, 3, 0, 0);
		$this->put(2, 6, 2, 85);
		$this->put(2, 6, 2, 85);
		$this->put(3, 6, 7, 85);
		$this->put(1, 6, 4, 0);
		$this->put(5, 6, 4, 0);
		$this->put(5, 6, 5, 0);
		$this->put(1, 5, 5, 140);
		$this->tiles[] = ['pos' => [1, 5, 5], 'name' => 'FlowerPot', 'nbt' => ['item' => 40, 'mData' => 0]];
		$this->put(3, 5, 6, 58);
		$this->put(4, 5, 6, 118, 6);
		$this->tiles[] = ['pos' => [4, 5, 6], 'name' => 'minecraft:cauldron', 'nbt' => ['PotionId' => 65535, 'SplashPotion' => 0]];
		$this->fill(0, 7, 1, 6, 7, 1, 134, 2);
		$this->fill(0, 7, 8, 6, 7, 8, 134, 3);
		$this->fill(0, 7, 2, 0, 7, 7, 134, 0);
		$this->fill(6, 7, 2, 6, 7, 7, 134, 1);
		$this->fill(1, 8, 2, 5, 8, 7, 158, 1);
	}

	private function stronghold(){
		$this->begin(53, 15, 51);
		// Central entry, axial corridor and a transverse four-way crossing.
		$this->box(22, 1, 0, 30, 8, 10, 98);
		$this->box(24, 1, 9, 28, 6, 35, 98);
		$this->box(19, 1, 14, 33, 7, 22, 98);
		$this->box(12, 1, 16, 40, 6, 20, 98);
		$this->fill(25, 2, 0, 27, 4, 35, 0);
		$this->fill(12, 2, 17, 40, 4, 19, 0);
		$this->fill(20, 2, 15, 32, 6, 21, 0);
		// Ribbed crossing ceiling, central square pillar and arch lintels.
		foreach([20, 32] as $x){
			$this->fill($x, 2, 15, $x, 6, 15, 98, 3);
			$this->fill($x, 2, 21, $x, 6, 21, 98, 3);
		}
		$this->fill(24, 6, 15, 28, 6, 21, 44, 5);
		$this->fill(20, 6, 17, 32, 6, 19, 44, 5);
		foreach([7, 12, 24, 30] as $z){
			$this->put(25, 4, $z, 50, 1);
			$this->put(27, 4, $z, 50, 2);
		}
		$this->door(26, 2, 1, 1);
		// Source-inspired 14x15 double-height library with a balcony and ladder.
		$this->box(0, 1, 12, 13, 12, 28, 98);
		$this->fill(12, 2, 17, 13, 4, 19, 0);
		for($z = 13; $z <= 27; ++$z){
			$pier = (($z - 13) % 4) === 0;
			foreach([1, 12] as $x){
				$this->fill($x, 2, $z, $x, 5, $z, $pier ? 5 : 47);
				$this->fill($x, 8, $z, $x, 10, $z, $pier ? 5 : 47);
			}
			if($pier){
				$this->put(2, 4, $z, 50, 1);
				$this->put(11, 4, $z, 50, 2);
			}
		}
		// Restore the library's side doorway after shelves are installed.
		$this->fill(12, 2, 17, 13, 4, 19, 0);
		foreach([4, 8] as $x){
			foreach([15, 21] as $z){
				$this->fill($x, 2, $z, $x + 1, 4, $z + 3, 47);
			}
		}
		$this->fill(1, 7, 13, 3, 7, 27, 5);
		$this->fill(10, 7, 13, 12, 7, 27, 5);
		$this->fill(4, 7, 13, 9, 7, 15, 5);
		$this->fill(4, 7, 25, 9, 7, 27, 5);
		$this->fill(3, 8, 16, 3, 8, 24, 85);
		$this->fill(10, 8, 16, 10, 8, 24, 85);
		$this->fill(4, 8, 15, 9, 8, 15, 85);
		$this->fill(4, 8, 25, 9, 8, 25, 85);
		$this->fill(2, 2, 26, 2, 7, 26, 65, 5);
		$this->put(2, 7, 26, 0);
		$this->fill(6, 9, 19, 7, 9, 20, 85);
		$this->put(6, 8, 19, 50, 5);
		$this->put(7, 8, 20, 50, 5);
		$this->chest(9, 2, 26, 2, 'library');
		$this->chest(11, 8, 14, 3, 'library');
		foreach([[2, 11, 15], [10, 11, 24], [5, 6, 17], [8, 5, 24]] as $p){
			if($this->next(3) !== 0){
				$this->put($p[0], $p[1], $p[2], 30);
			}
		}
		// A prison branch with three barred cells and a chest corridor.
		$this->box(39, 1, 12, 52, 7, 26, 98);
		$this->fill(39, 2, 17, 40, 4, 19, 0);
		foreach([14, 18, 22] as $z){
			$this->fill(46, 2, $z, 46, 5, $z + 2, 101);
			$this->fill(47, 1, $z + 3, 51, 6, $z + 3, 98);
			$this->door(46, 2, $z + 1, 0, true);
			$this->put(45, 2, $z + 1, 70);
		}
		$this->chest(41, 2, 24, 2);
		// Twelve-frame end portal room, raised stair dais, lava wells and spawner.
		$this->box(20, 1, 34, 32, 10, 50, 98);
		$this->fill(25, 2, 34, 27, 4, 35, 0);
		foreach([22, 30] as $x){
			$this->fill($x, 2, 38, $x, 4, 44, 101);
			$this->fill($x, 1, 37, $x, 1, 47, 11);
		}
		$this->fill(24, 1, 41, 28, 1, 45, 11);
		$this->fill(24, 2, 41, 28, 3, 45, 98);
		$this->fill(25, 3, 42, 27, 3, 44, 49);
		for($d = 0; $d < 3; ++$d){
			$this->put(25 + $d, 4, 41, 120, 0);
			$this->put(25 + $d, 4, 45, 120, 2);
			$this->put(24, 4, 42 + $d, 120, 3);
			$this->put(28, 4, 42 + $d, 120, 1);
		}
		$this->fill(25, 2, 38, 27, 2, 38, 109, 2);
		$this->fill(25, 2, 39, 27, 2, 40, 98);
		$this->fill(25, 3, 39, 27, 3, 39, 109, 2);
		$this->spawner(26, 2, 37, 39); // Legacy silverfish ID.
		foreach([37, 41, 45, 48] as $z){
			$this->put(21, 6, $z, 101);
			$this->put(31, 6, $z, 101);
		}
		$this->weather(98);
	}

	/** Three-wide mine galleries; rotations apply equally to rails and supports. */
	private function mineTunnel(int $x0, int $z0, int $x1, int $z1, bool $alongX){
		$this->box($x0, 0, $z0, $x1, 6, $z1, 1);
		$this->fill($x0 + 1, 1, $z0 + 1, $x1 - 1, 1, $z1 - 1, 5);
		$this->fill($x0 + 1, 2, $z0 + 1, $x1 - 1, 5, $z1 - 1, 0);
		$start = ($alongX ? $x0 : $z0) + 2;
		$end = ($alongX ? $x1 : $z1) - 1;
		for($p = $start; $p <= $end; ++$p){
			$x = $alongX ? $p : $x0 + 2;
			$z = $alongX ? $z0 + 2 : $p;
			$this->put($x, 2, $z, 66, $alongX ? 1 : 0);
			if(($p - $start) % 5 !== 0){
				continue;
			}
			if($alongX){
				$this->fill($p, 2, $z0 + 1, $p, 4, $z0 + 1, 85);
				$this->fill($p, 2, $z1 - 1, $p, 4, $z1 - 1, 85);
				$this->fill($p, 5, $z0 + 1, $p, 5, $z1 - 1, 5);
				$this->put($p, 4, $z0 + 2, 50, 5);
			}else{
				$this->fill($x0 + 1, 2, $p, $x0 + 1, 4, $p, 85);
				$this->fill($x1 - 1, 2, $p, $x1 - 1, 4, $p, 85);
				$this->fill($x0 + 1, 5, $p, $x1 - 1, 5, $p, 5);
				$this->put($x0 + 2, 4, $p, 50, 5);
			}
		}
	}

	private function mineshaft(){
		$this->begin(47, 13, 47);
		// A loop rather than isolated corridor stubs, with a central crosscut.
		$this->mineTunnel(2, 8, 44, 12, true);
		$this->mineTunnel(2, 34, 44, 38, true);
		$this->mineTunnel(8, 8, 12, 38, false);
		$this->mineTunnel(34, 8, 38, 38, false);
		$this->mineTunnel(21, 2, 25, 44, false);
		$this->mineTunnel(8, 21, 38, 25, true);
		// Open intersections after all shells, so later pieces cannot seal them.
		foreach([[10, 10], [23, 10], [36, 10], [10, 23], [23, 23], [36, 23], [10, 36], [23, 36], [36, 36]] as $p){
			$this->fill($p[0] - 1, 2, $p[1] - 1, $p[0] + 1, 4, $p[1] + 1, 0);
			$this->fill($p[0] - 1, 1, $p[1] - 1, $p[0] + 1, 1, $p[1] + 1, 5);
		}
		// Restore continuous rails on the main route and four curved loop corners.
		$this->fill(10, 2, 10, 36, 2, 10, 66, 1);
		$this->fill(10, 2, 36, 36, 2, 36, 66, 1);
		$this->fill(10, 2, 11, 10, 2, 35, 66, 0);
		$this->fill(36, 2, 11, 36, 2, 35, 66, 0);
		$this->put(10, 2, 10, 66, 6);
		$this->put(36, 2, 10, 66, 7);
		$this->put(36, 2, 36, 66, 8);
		$this->put(10, 2, 36, 66, 9);
		$this->fill(23, 2, 3, 23, 2, 43, 66, 0);
		$this->fill(11, 2, 23, 35, 2, 23, 66, 1);
		// Large two-level crossing room with four solid timber columns.
		$this->box(17, 0, 17, 29, 11, 29, 1);
		$this->fill(18, 1, 18, 28, 1, 28, 5);
		$this->fill(21, 2, 17, 25, 4, 29, 0);
		$this->fill(17, 2, 21, 29, 4, 25, 0);
		foreach([19, 27] as $x){
			foreach([19, 27] as $z){
				$this->fill($x, 2, $z, $x, 9, $z, 17);
			}
		}
		$this->fill(18, 6, 18, 28, 6, 20, 5);
		$this->fill(18, 6, 26, 28, 6, 28, 5);
		$this->fill(18, 6, 21, 20, 6, 25, 5);
		$this->fill(26, 6, 21, 28, 6, 25, 5);
		$this->fill(20, 7, 21, 20, 7, 25, 85);
		$this->fill(26, 7, 21, 26, 7, 25, 85);
		$this->fill(18, 2, 24, 18, 6, 24, 65, 5);
		$this->put(18, 6, 24, 0);
		$this->fill(23, 2, 18, 23, 2, 28, 66, 0);
		$this->chest(20, 2, 19, 3, 'mine');
		$this->chest(27, 7, 27, 2, 'mine');
		$this->chest(35, 2, 18, 4, 'mine');
		$this->chest(11, 2, 30, 5, 'mine');
		// A cobweb-infested branch, using the actual legacy cave-spider entity ID.
		$this->spawner(23, 2, 41, 40);
		foreach([[22, 3, 39], [24, 3, 40], [22, 4, 42], [24, 4, 43], [11, 4, 16], [35, 4, 28], [27, 9, 27]] as $p){
			$this->put($p[0], $p[1], $p[2], 30);
		}
		foreach([[3, 3, 9], [9, 3, 13], [37, 4, 29], [28, 3, 29]] as $p){
			$this->put($p[0], $p[1], $p[2], $this->next(3) === 0 ? 15 : 16);
		}
	}

	private function fortressBridge(int $x0, int $z0, int $x1, int $z1, bool $alongX){
		$this->fill($x0, 6, $z0, $x1, 8, $z1, 112);
		$this->fill($x0, 9, $z0, $x1, 12, $z1, 0);
		if($alongX){
			$this->fill($x0, 9, $z0, $x1, 9, $z0, 112);
			$this->fill($x0, 9, $z1, $x1, 9, $z1, 112);
			for($x = $x0 + 2; $x <= $x1 - 2; $x += 6){
				$this->fill($x, 0, $z0, $x + 1, 5, $z1, 112);
				$this->fill($x, 10, $z0, $x + 1, 11, $z0, 113);
				$this->fill($x, 10, $z1, $x + 1, 11, $z1, 113);
				$this->put($x + 2, 6, $z0 + 1, 114, 4);
			}
		}else{
			$this->fill($x0, 9, $z0, $x0, 9, $z1, 112);
			$this->fill($x1, 9, $z0, $x1, 9, $z1, 112);
			for($z = $z0 + 2; $z <= $z1 - 2; $z += 6){
				$this->fill($x0, 0, $z, $x1, 5, $z + 1, 112);
				$this->fill($x0, 10, $z, $x0, 11, $z + 1, 113);
				$this->fill($x1, 10, $z, $x1, 11, $z + 1, 113);
				$this->put($x0 + 1, 6, $z + 2, 114, 6);
			}
		}
	}

	private function netherFortress(){
		$this->begin(63, 25, 63);
		$this->fortressBridge(4, 29, 58, 33, true);
		$this->fortressBridge(29, 14, 33, 58, false);
		// Open 19-block crossing with four deep masonry piers.
		$this->fill(24, 7, 24, 38, 8, 38, 112);
		$this->fill(25, 9, 25, 37, 13, 37, 0);
		foreach([24, 36] as $x){
			foreach([24, 36] as $z){
				$this->fill($x, 0, $z, $x + 2, 8, $z + 2, 112);
				$this->fill($x, 9, $z, $x + 2, 9, $z + 2, 112);
				$this->fill($x + 1, 10, $z + 1, $x + 1, 12, $z + 1, 113);
			}
		}
		$this->fill(24, 9, 29, 38, 12, 33, 0);
		$this->fill(29, 9, 24, 33, 12, 38, 0);
		// Castle entrance hall: roof, tall slit windows, square lava well.
		$this->box(21, 8, 0, 41, 19, 18, 112);
		$this->fill(22, 6, 1, 40, 7, 17, 112);
		foreach([22, 38] as $x){
			foreach([1, 15] as $z){
				$this->fill($x, 0, $z, $x + 2, 7, $z + 2, 112);
			}
		}
		$this->fill(29, 9, 0, 33, 13, 0, 0);
		$this->fill(29, 9, 18, 33, 13, 18, 0);
		foreach([4, 9, 14] as $z){
			$this->fill(21, 12, $z, 21, 16, $z + 1, 113);
			$this->fill(41, 12, $z, 41, 16, $z + 1, 113);
		}
		foreach([25, 37] as $x){
			$this->fill($x, 9, 3, $x, 17, 3, 112);
			$this->fill($x, 9, 15, $x, 17, 15, 112);
			$this->fill($x, 18, 3, $x, 18, 15, 114, $x === 25 ? 4 : 5);
		}
		$this->fill(28, 9, 6, 34, 9, 12, 112);
		$this->fill(29, 9, 7, 33, 9, 11, 11);
		$this->fill(30, 10, 8, 32, 10, 10, 112);
		$this->fill(31, 11, 9, 31, 14, 9, 11);
		$this->put(31, 15, 9, 112);
		$this->chest(23, 9, 16, 2, 'fortress');
		// Raised blaze throne at the west end, battlements and open back.
		$this->box(0, 8, 24, 14, 15, 38, 112);
		$this->fill(1, 15, 25, 13, 15, 37, 0);
		$this->fill(14, 9, 30, 14, 12, 32, 0);
		$this->fill(1, 9, 25, 1, 11, 37, 113);
		$this->fill(2, 9, 25, 2, 10, 37, 112);
		$this->fill(3, 9, 26, 4, 9, 36, 112);
		$this->fill(4, 10, 28, 4, 10, 34, 114, 1);
		$this->fill(5, 9, 28, 7, 10, 34, 112);
		$this->fill(6, 11, 29, 6, 11, 33, 114, 1);
		$this->fill(7, 11, 29, 10, 11, 33, 112);
		$this->spawner(8, 12, 31, 43);
		foreach([25, 37] as $z){
			$this->fill(2, 12, $z, 12, 13, $z, 113);
		}
		foreach([2, 6, 10, 14] as $x){
			$this->put($x, 16, 24, 112);
			$this->put($x, 16, 38, 112);
		}
		// Nether-wart garden mirrors the source's soul-sand corridor rooms.
		$this->box(48, 8, 23, 62, 18, 41, 112);
		$this->fill(48, 9, 30, 48, 12, 32, 0);
		foreach([51, 58] as $x){
			$this->fill($x, 8, 26, $x + 1, 8, 38, 88);
			$this->fill($x, 9, 26, $x + 1, 9, 38, 115, 3);
		}
		$this->fill(54, 8, 25, 55, 8, 39, 112);
		foreach([26, 31, 36] as $z){
			$this->fill(62, 12, $z, 62, 15, $z + 1, 113);
		}
		$this->chest(60, 9, 39, 2, 'fortress');
		// Ascending south staircase leads to an elevated landing pavilion.
		$this->box(25, 8, 48, 37, 22, 62, 112);
		$this->fill(29, 9, 48, 33, 12, 48, 0);
		for($step = 0; $step < 6; ++$step){
			$this->fill(29, 9, 50 + $step, 33, 9 + $step, 50 + $step, 112);
			$this->fill(29, 10 + $step, 50 + $step, 33, 19, 50 + $step, 0);
			$this->fill(29, 9 + $step, 50 + $step, 33, 9 + $step, 50 + $step, 114, 2);
		}
		$this->fill(27, 14, 56, 35, 14, 61, 112);
		$this->fill(27, 15, 56, 35, 20, 61, 0);
		$this->fill(25, 16, 57, 25, 19, 59, 113);
		$this->fill(37, 16, 57, 37, 19, 59, 113);
	}

	private function oceanMonument(){
		$this->begin(58, 25, 58, 10);
		// The water is intentional: this family is an underwater monument.
		$this->fill(0, 1, 0, 57, 24, 57, 9);
		$this->fill(0, 0, 0, 57, 0, 57, 1);
		// Two broad front wings, with three nested stepped ridges apiece.
		foreach([0, 33] as $x){
			$this->box($x, 1, 0, $x + 24, 5, 21, 98, 0, 9);
			$this->box($x + 3, 5, 3, $x + 21, 8, 21, 98, 0, 9);
			$this->box($x + 7, 8, 7, $x + 17, 11, 21, 98, 0, 9);
			for($step = 0; $step < 4; ++$step){
				$this->fill($x + $step, 2 + $step, $step, $x + $step, 2 + $step, 20, 1);
				$this->fill($x + 24 - $step, 2 + $step, $step, $x + 24 - $step, 2 + $step, 20, 1);
				$this->fill($x + $step, 2 + $step, $step, $x + 24 - $step, 2 + $step, $step, 1);
			}
			$this->fill($x + 11, 2, 0, $x + 13, 4, 1, 9);
			$this->fill($x + 10, 1, 4, $x + 14, 1, 18, 98, 1);
			foreach([5, 11, 17] as $z){
				$this->put($x + 5, 4, $z, 89);
				$this->put($x + 19, 4, $z, 89);
			}
		}
		// Main terraced mass rises behind the wings, ending in the crown room.
		for($tier = 0; $tier < 5; ++$tier){
			$x0 = 4 + $tier * 3;
			$z0 = 20 + $tier * 3;
			$x1 = 53 - $tier * 3;
			$z1 = 57 - $tier * 2;
			$y = 1 + $tier * 3;
			$this->box($x0, $y, $z0, $x1, $y + 3, $z1, 98, 0, 9);
			$this->fill($x0, $y + 3, $z0, $x1, $y + 3, $z0, 1);
			$this->fill($x0, $y + 3, $z1, $x1, $y + 3, $z1, 1);
			$this->fill($x0, $y + 3, $z0, $x0, $y + 3, $z1, 1);
			$this->fill($x1, $y + 3, $z0, $x1, $y + 3, $z1, 1);
		}
		$this->box(22, 16, 32, 35, 21, 43, 98, 0, 9);
		$this->fill(24, 22, 34, 33, 22, 41, 1);
		foreach([23, 34] as $x){
			foreach([33, 42] as $z){
				$this->fill($x, 22, $z, $x, 23, $z, 98);
				$this->put($x, 24, $z, 89);
			}
		}
		// Central approach: four ribbed archways between the wing facades.
		$this->fill(25, 0, 0, 32, 0, 23, 98);
		for($z = 3; $z <= 19; $z += 4){
			$this->fill(25, 1, $z, 25, 5, $z, 98);
			$this->fill(32, 1, $z, 32, 5, $z, 98);
			$this->fill(26, 5, $z, 31, 5, $z, 98);
			$this->fill(27, 6, $z, 30, 6, $z, 1);
			$this->put(25, 3, $z, 89);
			$this->put(32, 3, $z, 89);
		}
		// Interior grid of flooded rooms. Open arches join every room row.
		for($x = 9; $x <= 41; $x += 8){
			for($z = 24; $z <= 40; $z += 8){
				$this->box($x, 1, $z, $x + 8, 6, $z + 8, 98, 0, 9);
				$this->fill($x + 3, 2, $z, $x + 5, 4, $z, 9);
				$this->fill($x + 3, 2, $z + 8, $x + 5, 4, $z + 8, 9);
				$this->fill($x, 2, $z + 3, $x, 4, $z + 5, 9);
				$this->fill($x + 8, 2, $z + 3, $x + 8, 4, $z + 5, 9);
				$this->put($x + 1, 5, $z + 1, 89);
				$this->put($x + 7, 5, $z + 7, 89);
			}
		}
		// Two-block-high spine, wing connections and an ascent to the crown.
		$this->fill(27, 1, 1, 30, 5, 51, 9);
		$this->fill(12, 2, 18, 45, 4, 20, 9);
		$this->fill(12, 2, 20, 14, 4, 28, 9);
		$this->fill(43, 2, 20, 45, 4, 28, 9);
		$this->fill(27, 2, 42, 30, 19, 44, 9);
		// Central dark-stone vault with the source's eight gold blocks.
		$this->box(23, 2, 31, 34, 11, 42, 98, 1, 9);
		$this->fill(28, 3, 31, 29, 5, 31, 9);
		$this->fill(28, 3, 42, 29, 5, 42, 9);
		$this->fill(27, 4, 35, 30, 7, 38, 98, 1);
		$this->fill(28, 5, 36, 29, 6, 37, 41);
		foreach([24, 33] as $x){
			foreach([32, 41] as $z){
				$this->put($x, 4, $z, 89);
				$this->put($x, 9, $z, 89);
			}
		}
		// Sponge room at the rear and recognizable ocean-floor support feet.
		$this->box(10, 7, 40, 18, 12, 48, 98, 0, 9);
		$this->fill(13, 7, 41, 15, 8, 43, 9);
		for($x = 11; $x <= 17; $x += 2){
			for($z = 41; $z <= 47; $z += 2){
				$this->fill($x, 10, $z, $x, 11, $z, 19);
			}
		}
		foreach([1, 9, 17, 25, 33, 41, 49, 56] as $p){
			$this->fill($p, 0, 55, min(57, $p + 1), 3, 56, 98);
		}
	}

	private function buriedTreasure(){
		$this->begin(5, 5, 5);
		// The Java piece is a chest fitted into shore strata, not a surface house.
		$this->fill(0, 0, 0, 4, 0, 4, 24);
		$this->fill(0, 1, 0, 4, 4, 4, 12);
		$this->put(1, 1, 1, 24);
		$this->put(3, 1, 3, 24);
		$this->chest(2, 1, 2, 2, 'treasure');
	}

	private function endExitPortal(){
		$this->begin(9, 7, 9, 1);
		// Bedrock fountain, circular lip and central four-high torch pillar.
		for($x = 0; $x < 9; ++$x){
			for($z = 0; $z < 9; ++$z){
				$d = ($x - 4) * ($x - 4) + ($z - 4) * ($z - 4);
				if($d > 12){
					continue;
				}
				$this->put($x, 0, $z, $d < 7 ? 7 : 121);
				$this->put($x, 1, $z, $d < 7 ? 49 : 7);
				$this->fill($x, 2, $z, $x, 6, $z, 0);
			}
		}
		$this->fill(4, 1, 4, 4, 4, 4, 7);
		$this->put(5, 3, 4, 50, 1);
		$this->put(3, 3, 4, 50, 2);
		$this->put(4, 3, 5, 50, 3);
		$this->put(4, 3, 3, 50, 4);
		$this->groundLevel = 1;
	}

	private function endGateway(){
		$this->begin(3, 5, 3);
		for($y = 0; $y < 5; ++$y){
			for($z = 0; $z < 3; ++$z){
				for($x = 0; $x < 3; ++$x){
					$center = $x === 1 && $z === 1;
					$end = $y === 0 || $y === 4;
					$id = 0;
					if($y === 2 && $center){
						$id = 89;
					}elseif($end && $center){
						$id = 7;
					}elseif(!$end && $y !== 2 && ($x === 1 || $z === 1)){
						$id = 7;
					}
					$this->put($x, $y, $z, $id);
				}
			}
		}
	}

	private function desertWell(){
		$this->begin(5, 6, 5);
		$this->fill(0, 0, 0, 4, 1, 4, 24);
		$this->fill(0, 2, 0, 4, 2, 4, 24);
		$this->fill(1, 2, 1, 3, 2, 3, 0);
		$this->put(2, 1, 2, 9);
		foreach([[1, 2], [3, 2], [2, 1], [2, 3]] as $p){
			$this->put($p[0], 1, $p[1], 9);
		}
		foreach([[0, 2], [4, 2], [2, 0], [2, 4]] as $p){
			$this->put($p[0], 2, $p[1], 44, 1);
		}
		foreach([1, 3] as $x){
			foreach([1, 3] as $z){
				$this->fill($x, 2, $z, $x, 4, $z, 24);
			}
		}
		$this->fill(1, 5, 1, 3, 5, 3, 44, 1);
		$this->put(2, 5, 2, 24);
	}

	private function bonusDungeon(){
		$wide = $this->next(2) === 0;
		$width = $wide ? 11 : 9;
		$this->begin($width, 7, 11);
		$this->box(0, 0, 0, $width - 1, 6, 10, 4);
		$this->fill(1, 1, 1, $width - 2, 1, 9, 4);
		$this->fill(1, 2, 1, $width - 2, 5, 9, 0);
		$this->fill(3, 2, 0, 4, 4, 0, 0);
		$this->fill($width - 5, 2, 10, $width - 4, 4, 10, 0);
		$this->weather(4);
		$this->spawner(intdiv($width, 2), 2, 5, [32, 32, 34, 35][$this->next(4)]);
		$this->chest(1, 2, 2, 5, 'dungeon');
		$this->chest($width - 2, 2, 8, 4, 'dungeon');
	}
}
