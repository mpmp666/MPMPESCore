<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

/**
 * Deterministic geometry-only layout assembly for the converted vanilla library.
 * All positions are template pivots. In particular rotation 1 maps (x,z) to (-z,x).
 * Terrain, block processors, loot and entity behaviour belong to the placement layer.
 */
final class StructureAssembler{
	public const MAX_PIECES = 80;
	public const MAX_SPAN = 112;
	public const MAX_HEIGHT = 110;

	/** @return list<array{template:string,x:int,y:int,z:int,rotation:int}> */
	public static function build(string $family, int $seed, string $variant = '') : array{
		$family = self::key(strtolower(trim($family)));
		$variant = self::key(strtolower(trim($variant)));
		if(isset(StructureRepository::manifest()['templates'][$family])){
			$layout = new JigsawLayout($seed);
			return [$layout->piece($family, 0, 0, 0, $layout->random(4))];
		}
		$aliases = ['mansion' => 'woodland_mansion', 'woodlandmansion' => 'woodland_mansion', 'outpost' => 'pillager_outpost', 'pillageroutpost' => 'pillager_outpost', 'bastion_remnant' => 'bastion', 'endcity' => 'end_city', 'ancientcity' => 'ancient_city', 'trial_chamber' => 'trial_chambers', 'trail_ruin' => 'trail_ruins', 'ocean_ruin' => 'underwater_ruin', 'ocean_ruins' => 'underwater_ruin'];
		$family = $aliases[$family] ?? $family;
		foreach(['plains', 'desert', 'savanna', 'snowy', 'taiga'] as $biome){
			if($family === 'village_' . $biome || $family === 'village/' . $biome || $family === 'village/' . $biome . '/zombie'){
				$variant = $biome . (str_contains($family, 'zombie') || str_contains($variant, 'zombie') ? '/zombie' : '');
				$family = 'village';
			}
		}
		if($family === 'shipwreck' && $variant === ''){ $variant = 'full'; }
		$layout = new JigsawLayout($seed);
		if($family === 'end_city'){
			return (new EndCityLayout($layout))->build($variant);
		}
		if($family === 'woodland_mansion'){
			return (new MansionLayout($layout))->build($variant);
		}
		if($family === 'igloo'){
			return $layout->igloo($variant);
		}
		$pool = match($family){
			'village' => 'village/' . self::villageBiome($variant) . '/town_centers',
			'bastion' => 'bastion/starts',
			'ancient_city' => 'ancient_city/city_center',
			'trial_chambers' => 'trial_chambers/chamber/end',
			'trail_ruins' => 'trail_ruins/tower',
			'pillager_outpost' => 'pillager_outpost/base_plates',
			default => ''
		};
		if($pool !== ''){
			return $layout->assemble($family, $pool, $variant);
		}
		return $layout->standalone($family, $variant);
	}

	public static function families() : array{
		$result = StructureRepository::manifest()['families'] ?? [];
		ksort($result, SORT_STRING);
		return $result;
	}

	public static function key(string $key) : string{
		return str_starts_with($key, 'minecraft:') ? substr($key, 10) : $key;
	}

	private static function villageBiome(string $variant) : string{
		foreach(['desert', 'savanna', 'snowy', 'taiga', 'plains'] as $biome){
			if(str_contains($variant, $biome)){
				return $biome;
			}
		}
		return 'plains';
	}
}

/** Internal, per-build state; it never consumes PHP's process-global random stream. */
final class JigsawLayout{
	private int $initialSeed;
	private int $randomState;
	private array $manifest;
	private array $pools;
	private array $metadata = [];
	private array $pieces = [];
	private array $nodes = [];
	private array $queue = [];
	private array $used = [];
	private array $aliases = [];
	private string $family = '';
	private string $variant = '';
	private int $serial = 0;

	public function __construct(int $seed){
		$this->initialSeed = $seed;
		// Park-Miller stays entirely in signed 64-bit integer arithmetic on PHP 8.4.
		$this->randomState = $seed % 2147483646;
		if($this->randomState < 0){ $this->randomState += 2147483646; }
		++$this->randomState;
		$this->manifest = StructureRepository::manifest();
		$this->pools = StructureRepository::pools();
	}

	public function seed() : int{ return $this->initialSeed; }

	public function random(int $bound) : int{
		$this->randomState = ($this->randomState * 48271) % 2147483647;
		return $bound > 1 ? $this->randomState % $bound : 0;
	}

	public function shuffle(array $values) : array{
		$values = array_values($values);
		for($i = count($values) - 1; $i > 0; --$i){
			$j = $this->random($i + 1);
			[$values[$i], $values[$j]] = [$values[$j], $values[$i]];
		}
		return $values;
	}

	public function exists(string $key) : bool{ return isset($this->manifest['templates'][$key]); }
	public function templates() : array{ return $this->manifest['templates']; }

	public function info(string $key) : array{
		if(!isset($this->metadata[$key])){
			$t = $this->manifest['templates'][$key] ?? [];
			if(!array_key_exists('connectors', $t)){ $t = StructureRepository::template($key); }
			$this->metadata[$key] = ['size' => $t['size'], 'connectors' => $t['connectors'] ?? []];
		}
		return $this->metadata[$key];
	}

	public function size(string $key) : array{ return $this->manifest['templates'][$key]['size'] ?? $this->info($key)['size']; }

	public static function rotate(int $x, int $z, int $rotation) : array{
		return match($rotation & 3){ 1 => [-$z, $x], 2 => [-$x, -$z], 3 => [$z, -$x], default => [$x, $z] };
	}

	public function piece(string $key, int $x, int $y, int $z, int $rotation = 0) : array{
		return ['template' => $key, 'x' => $x, 'y' => $y, 'z' => $z, 'rotation' => $rotation & 3];
	}

	public function bounds(array $piece) : array{
		[$w, $h, $l] = $this->size($piece['template']);
		[$x1, $z1] = self::rotate(0, 0, $piece['rotation']);
		[$x2, $z2] = self::rotate($w - 1, $l - 1, $piece['rotation']);
		return [$piece['x'] + min($x1, $x2), $piece['y'], $piece['z'] + min($z1, $z2), $piece['x'] + max($x1, $x2), $piece['y'] + $h - 1, $piece['z'] + max($z1, $z2)];
	}

	/** Exact global bounds, including negative rotated corners. */
	public function fits(array $candidate, array $existing) : bool{
		if(count($candidate) + count($existing) > StructureAssembler::MAX_PIECES){ return false; }
		$b = null;
		foreach(array_merge($existing, $candidate) as $p){
			$c = $this->bounds($p);
			$b = $b === null ? $c : [min($b[0], $c[0]), min($b[1], $c[1]), min($b[2], $c[2]), max($b[3], $c[3]), max($b[4], $c[4]), max($b[5], $c[5])];
		}
		return $b === null || ($b[3] - $b[0] + 1 <= StructureAssembler::MAX_SPAN && $b[5] - $b[2] + 1 <= StructureAssembler::MAX_SPAN && $b[4] - $b[1] + 1 <= StructureAssembler::MAX_HEIGHT);
	}

	public function relative(array $parent, string $key, int $x, int $y, int $z, ?int $rotation = null) : array{
		[$x, $z] = self::rotate($x, $z, $parent['rotation']);
		return $this->piece($key, $parent['x'] + $x, $parent['y'] + $y, $parent['z'] + $z, $rotation ?? $parent['rotation']);
	}

	public function assemble(string $family, string $pool, string $variant) : array{
		$this->family = $family;
		$this->variant = $variant;
		if($family === 'trial_chambers'){
			$ranged = ['skeleton', 'stray', 'poison_skeleton'][$this->random(3)];
			foreach(['ranged', 'slow_ranged'] as $type){ $this->aliases['trial_chambers/spawner/contents/' . $type] = 'trial_chambers/spawner/' . $type . '/' . $ranged; }
			$this->aliases['trial_chambers/spawner/contents/melee'] = 'trial_chambers/spawner/melee/' . ['zombie', 'husk', 'spider'][$this->random(3)];
			$this->aliases['trial_chambers/spawner/contents/small_melee'] = 'trial_chambers/spawner/small_melee/' . ['slime', 'cave_spider', 'silverfish', 'baby_zombie'][$this->random(4)];
		}
		$starts = $this->poolElements($pool);
		foreach($starts as $element){
			$keys = $this->elementKeys($element);
			if($keys === [] || !$this->startMatches($keys[0])){ continue; }
			$r = $this->random(4);
			$group = [];
			foreach($keys as $key){ $group[] = $this->piece($key, 0, 0, 0, $r); }
			if(!$this->fits($group, [])){ continue; }
			$this->accept($group, 0, [], -1);
			break;
		}
		if($this->pieces === []){ return $this->standalone($family, $variant); }
		$maxDepth = match($family){ 'village' => 7, 'bastion' => 7, 'ancient_city' => 8, 'trial_chambers' => 16, default => 8 };
		while($this->queue !== [] && count($this->pieces) < StructureAssembler::MAX_PIECES){
			// Stable priority queue: larger placement_priority first, then breadth-first.
			usort($this->queue, static fn(array $a, array $b) : int => ($b['priority'] <=> $a['priority']) ?: (($a['depth'] <=> $b['depth']) ?: ($a['serial'] <=> $b['serial'])));
			$task = array_shift($this->queue);
			if(isset($this->used[$task['id']])){ continue; }
			$this->used[$task['id']] = true;
			$node = $this->nodes[$task['node']];
			$source = $task['connector'];
			$pool = $this->poolName((string)($source['pool'] ?? 'empty'));
			if($pool === 'empty' || $pool === '' || $this->entityPool($pool)){ continue; }
			$sourceWorld = $this->connector($source, $node['piece']);
			$direction = self::direction($sourceWorld['front']);
			$destination = [$sourceWorld['pos'][0] + $direction[0], $sourceWorld['pos'][1] + $direction[1], $sourceWorld['pos'][2] + $direction[2]];
			$inside = self::containsPoint($node['box'], $destination);
			$context = $inside ? $task['node'] : $node['context'];
			$ignore = $inside ? array_merge($node['ancestors'], [$task['node']]) : $node['ancestors'];
			$candidates = $this->poolElements($pool, $task['depth'] >= $maxDepth);
			$placed = false;
			foreach($candidates as $element){
				$type = StructureAssembler::key((string)($element['element_type'] ?? ''));
				if($type === 'empty_pool_element'){ break; }
				$keys = $this->elementKeys($element);
				if($keys === []){ continue; }
				$meta = $this->info($keys[0]);
				foreach($this->shuffle([0, 1, 2, 3]) as $rotation){
					foreach($this->orderedConnectors($meta['connectors']) as $index => $target){
						$targetWorld = $this->connector($target, $this->piece($keys[0], 0, 0, 0, $rotation));
						if(!$this->matches($sourceWorld, $targetWorld)){ continue; }
						$pos = [$destination[0] - $targetWorld['pos'][0], $destination[1] - $targetWorld['pos'][1], $destination[2] - $targetWorld['pos'][2]];
						$group = [];
						foreach($keys as $key){ $group[] = $this->piece($key, $pos[0], $pos[1], $pos[2], $rotation); }
						if(!$this->fits($group, $this->pieces) || !$this->collisionFree($group, $context, $ignore)){ continue; }
						$new = $this->accept($group, $task['depth'] + 1, $ignore, $context);
						// Mark the incoming connector by its original coordinates, not shuffled index.
						$this->used[$new . ':' . $this->connectorId($target)] = true;
						$placed = true;
						break 3;
					}
				}
			}
		}
		return $this->pieces;
	}

	private function entityPool(string $pool) : bool{
		// These templates only carry actor markers. Keep the geometry budget for
		// streets, houses and structural detail; manual access remains available.
		return str_contains($pool, '/villagers') || str_contains($pool, '/zombie/villagers') || str_contains($pool, '/common/animals') || in_array($pool, ['village/common/cats', 'village/common/iron_golem', 'bastion/mobs/piglin', 'bastion/mobs/hoglin', 'bastion/mobs/piglin_melee', 'bastion/mobs/piglin_sword', 'bastion/mobs/piglin_crossbow', 'bastion/mobs/crossbow_piglin', 'bastion/mobs/melee_piglin', 'bastion/mobs/hoglin'], true);
	}

	private function startMatches(string $key) : bool{
		if($this->family === 'village'){
			return str_contains($key, '/zombie/') === str_contains($this->variant, 'zombie');
		}
		if($this->family === 'bastion' && $this->variant !== ''){
			$v = ['housing' => 'units', 'stable' => 'hoglin_stable', 'treasure_room' => 'treasure'][$this->variant] ?? $this->variant;
			return str_contains($key, 'bastion/' . $v . '/');
		}
		return true;
	}

	private function poolName(string $name) : string{
		$name = StructureAssembler::key($name);
		$name = $this->aliases[$name] ?? $name;
		if($this->family === 'village' && str_contains($this->variant, 'zombie') && !str_contains($name, '/zombie/')){
			$parts = explode('/', $name);
			if(count($parts) >= 3 && $parts[0] === 'village' && $parts[1] !== 'common'){
				$zombie = $parts[0] . '/' . $parts[1] . '/zombie/' . implode('/', array_slice($parts, 2));
				if(isset($this->pools[$zombie]) || isset($this->pools['minecraft:' . $zombie])){ $name = $zombie; }
			}
		}
		return $name;
	}

	private function poolElements(string $name, bool $fallbackOnly = false, array $visited = []) : array{
		$name = $this->poolName($name);
		if(isset($visited[$name]) || $name === '' || $name === 'empty'){ return []; }
		$visited[$name] = true;
		$pool = $this->pools[$name] ?? $this->pools['minecraft:' . $name] ?? null;
		if(!is_array($pool)){ return []; }
		$result = [];
		if(!$fallbackOnly){
			$entries = array_values($pool['elements'] ?? []);
			// Weighted sampling without replacement over original repeated entries.
			$remaining = [];
			foreach($entries as $entry){
				$weight = max(0, min(150, (int)($entry['weight'] ?? 1)));
				if($weight > 0){ $remaining[] = ['element' => $entry['element'] ?? $entry, 'weight' => $weight]; }
			}
			while($remaining !== []){
				$total = array_sum(array_column($remaining, 'weight'));
				$choice = $this->random($total);
				foreach($remaining as $i => $entry){
					$choice -= $entry['weight'];
					if($choice < 0){
						$result[] = $entry['element'];
						// Equivalent candidates need only one geometric attempt.
						array_splice($remaining, $i, 1);
						break;
					}
				}
			}
		}
		$fallback = StructureAssembler::key((string)($pool['fallback'] ?? 'empty'));
		return array_merge($result, $this->poolElements($fallback, false, $visited));
	}

	private function elementKeys(array $element) : array{
		$type = StructureAssembler::key((string)($element['element_type'] ?? 'single_pool_element'));
		if($type === 'empty_pool_element' || $type === 'feature_pool_element'){ return []; }
		if($type === 'list_pool_element'){
			// Vanilla lists overlay all members at one pivot; the first owns jigsaws.
			$result = [];
			foreach($element['elements'] ?? [] as $child){ $result = array_merge($result, $this->elementKeys($child)); }
			return $result;
		}
		$key = StructureAssembler::key((string)($element['location'] ?? ''));
		return $this->exists($key) ? [$key] : [];
	}

	private function orderedConnectors(array $connectors) : array{
		$connectors = $this->shuffle($connectors);
		usort($connectors, static fn(array $a, array $b) : int => (int)($b['selection_priority'] ?? 0) <=> (int)($a['selection_priority'] ?? 0));
		return $connectors;
	}

	private function connectorId(array $c) : string{ return implode(',', $c['pos']) . '/' . ($c['front'] ?? '') . '/' . ($c['name'] ?? ''); }

	private function accept(array $group, int $depth, array $ancestors, int $context) : int{
		$index = count($this->nodes);
		foreach($group as $piece){ $this->pieces[] = $piece; }
		$piece = $group[0];
		$box = $this->bounds($piece);
		foreach(array_slice($group, 1) as $p){
			$b = $this->bounds($p);
			$box = [min($box[0], $b[0]), min($box[1], $b[1]), min($box[2], $b[2]), max($box[3], $b[3]), max($box[4], $b[4]), max($box[5], $b[5])];
		}
		$this->nodes[] = ['piece' => $piece, 'box' => $box, 'context' => $context, 'ancestors' => $ancestors];
		foreach($this->orderedConnectors($this->info($piece['template'])['connectors']) as $c){
			$this->queue[] = ['node' => $index, 'connector' => $c, 'depth' => $depth, 'priority' => (int)($c['placement_priority'] ?? 0), 'serial' => $this->serial++, 'id' => $index . ':' . $this->connectorId($c)];
		}
		return $index;
	}

	private function collisionFree(array $group, int $context, array $ignore) : bool{
		foreach($group as $piece){
			$b = $this->bounds($piece);
			if($context >= 0){
				$region = $this->nodes[$context]['box'];
				if($this->family === 'village' && $region[4] - $region[1] <= 2){
					// Terrain-matching roads reserve a two-dimensional building plot.
					// Their two-block source height does not limit an attached house.
					$region[1] = -StructureAssembler::MAX_HEIGHT;
					$region[4] = StructureAssembler::MAX_HEIGHT;
				}
				if(!self::containsBox($region, $b)){ return false; }
			}
			foreach($this->nodes as $index => $node){
				if(in_array($index, $ignore, true)){ continue; }
				$a = $node['box'];
				// Templates intentionally share a one-block seam at walls and jigsaws.
				$dx = min($a[3], $b[3]) - max($a[0], $b[0]) + 1;
				$dy = min($a[4], $b[4]) - max($a[1], $b[1]) + 1;
				$dz = min($a[5], $b[5]) - max($a[2], $b[2]) + 1;
				if($dx > 1 && $dy > 1 && $dz > 1){ return false; }
			}
		}
		return true;
	}

	private static function containsPoint(array $b, array $p) : bool{
		return $p[0] >= $b[0] && $p[0] <= $b[3] && $p[1] >= $b[1] && $p[1] <= $b[4] && $p[2] >= $b[2] && $p[2] <= $b[5];
	}
	private static function containsBox(array $a, array $b) : bool{
		return $b[0] >= $a[0] && $b[1] >= $a[1] && $b[2] >= $a[2] && $b[3] <= $a[3] && $b[4] <= $a[4] && $b[5] <= $a[5];
	}

	public static function direction(string $direction) : array{
		return match($direction){ 'north' => [0, 0, -1], 'south' => [0, 0, 1], 'west' => [-1, 0, 0], 'east' => [1, 0, 0], 'up' => [0, 1, 0], 'down' => [0, -1, 0], default => [0, 0, 0] };
	}
	private static function rotatedDirection(string $direction, int $rotation) : string{
		$dirs = ['north', 'east', 'south', 'west'];
		$i = array_search($direction, $dirs, true);
		return $i === false ? $direction : $dirs[($i + $rotation) & 3];
	}
	private function connector(array $c, array $piece) : array{
		[$x, $y, $z] = $c['pos'];
		[$x, $z] = self::rotate((int)$x, (int)$z, $piece['rotation']);
		$c['pos'] = [$piece['x'] + $x, $piece['y'] + (int)$y, $piece['z'] + $z];
		$c['front'] = self::rotatedDirection((string)($c['front'] ?? 'north'), $piece['rotation']);
		$c['top'] = self::rotatedDirection((string)($c['top'] ?? 'up'), $piece['rotation']);
		return $c;
	}
	private function matches(array $source, array $target) : bool{
		$a = self::direction($source['front']);
		$b = self::direction($target['front']);
		$joint = $source['joint'] ?? (in_array($source['front'], ['up', 'down'], true) ? 'rollable' : 'aligned');
		return $a === [-$b[0], -$b[1], -$b[2]] && ($joint === 'rollable' || $source['top'] === $target['top']) && StructureAssembler::key((string)($source['target'] ?? 'empty')) === StructureAssembler::key((string)($target['name'] ?? 'empty'));
	}

	public function standalone(string $family, string $variant = '') : array{
		if($this->exists($family)){ return [$this->piece($family, 0, 0, 0, $this->random(4))]; }
		$keys = [];
		$variant = StructureAssembler::key($variant);
		$selectionVariant = in_array($variant, ['single', 'group'], true) ? '' : $variant;
		foreach($this->manifest['templates'] as $key => $entry){

			if(($entry['family'] ?? explode('/', $key)[0]) !== $family && !str_starts_with($key, $family . '/')){ continue; }
			if($selectionVariant !== '' && !str_contains($key, $selectionVariant)){ continue; }
			$keys[] = $key;
		}
		sort($keys, SORT_STRING);
		if($keys === []){ throw new \InvalidArgumentException('Unknown structure family or variant: ' . $family . ($variant !== '' ? '/' . $variant : '')); }
		$key = $keys[$this->random(count($keys))];
		$result = [$this->piece($key, 0, 0, 0, $this->random(4))];
		// Ocean ruins use either one small template or a big central ruin with
		// scattered small companions. The variant can force either shape.
		if(in_array($family, ['underwater_ruin', 'underwater_ruins', 'underwater'], true)){
			$bigKeys = array_values(array_filter($keys, static fn(string $k) : bool => str_contains($k, '/big_')));
			$small = array_values(array_filter($keys, static fn(string $k) : bool => !str_contains($k, '/big_')));
			$useGroup = $variant === 'group' || ($variant !== 'single' && str_contains($key, '/big_'));
			if($useGroup && $bigKeys !== [] && $small !== []){
				$big = $bigKeys[$this->random(count($bigKeys))];
				$result[0] = $this->piece($big, 0, 0, 0, $this->random(4));
				foreach($this->shuffle([[-22, -20], [22, -20], [-22, 20], [22, 20]]) as [$x, $z]){
					if($this->random(4) === 0){ continue; }
					$p = $this->piece($small[$this->random(count($small))], $x, 0, $z, $this->random(4));
					if($this->fits([$p], $result)){ $result[] = $p; }
				}
			}
		}
		return $result;
	}

	public function igloo(string $variant = '') : array{
		if(!$this->exists('igloo/top')){ return $this->standalone('igloo', $variant); }
		$r = $this->random(4);
		$top = $this->piece('igloo/top', 0, 0, 0, $r);
		$result = [$top];
		if($variant === 'top' || ($variant !== 'basement' && $this->random(2) === 0)){ return $result; }
		$depth = 4 + $this->random(7);
		for($i = 0; $i < $depth; ++$i){ $result[] = $this->relative($top, 'igloo/middle', 2, -3 * ($i + 1), 4); }
		$result[] = $this->relative($top, 'igloo/bottom', 0, -3 * $depth - 6, -2);
		return $result;
	}
}
