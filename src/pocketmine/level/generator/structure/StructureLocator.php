<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\generator\ender\EndCityGenerator;

/** Read-only nearest-start search using the same region placement as generation. */
final class StructureLocator{
	private NaturalStructures $engine;
	private int $seed;
	private string $dimension;
	private array $settings;

	public function __construct(NaturalStructures $engine, int $seed, string $dimension, array $settings = []){
		$this->engine = $engine;
		$this->seed = $seed;
		$this->dimension = self::dimension($dimension);
		$this->settings = $settings;
	}

	public static function dimension(string $name) : string{
		$name = strtolower(trim($name));
		return ['normal'=>'overworld', 'hell'=>'nether', 'ender'=>'end'][$name] ?? $name;
	}

	/** Village subtype names filter natural starts instead of changing their layout. */
	public static function normalize(string $name) : array{
		$name = strtolower(trim($name));
		if(strpos($name, 'minecraft:') === 0){ $name = substr($name, 10); }
		$aliases = [
			'mansion'=>'woodland_mansion', 'outpost'=>'pillager_outpost',
			'bastion_remnant'=>'bastion', 'fortress'=>'nether_fortress', 'endcity'=>'end_city',
			'ocean_ruin'=>'underwater_ruin', 'ocean_ruins'=>'underwater_ruin', 'underwater_ruins'=>'underwater_ruin',
			'monument'=>'ocean_monument', 'jungle_pyramid'=>'jungle_temple', 'witch_hut'=>'swamp_hut',
			'dungeon'=>'bonus_dungeon', 'nether_fossil'=>'nether_fossils'
		];
		$name = $aliases[$name] ?? $name;
		foreach(['plains', 'desert', 'savanna', 'snowy', 'taiga'] as $variant){
			if(in_array($name, ['village_'.$variant, $variant.'_village', 'village/'.$variant], true)){
				return ['family'=>'village', 'variant'=>$variant];
			}
		}
		return ['family'=>$name, 'variant'=>''];
	}

	/** An error here means unsupported/disabled, never an exhausted terrain search. */
	public static function requestError(string $name, string $dimension, array $settings = []) : ?string{
		$request = self::normalize($name);
		$family = $request['family'];
		$dimension = self::dimension($dimension);
		if(!in_array($dimension, ['overworld', 'nether', 'end'], true)){
			return '该世界生成器不支持自然建筑定位：'.$dimension.'。';
		}
		$known = [];
		foreach(['overworld', 'nether', 'end'] as $candidate){
			if(isset(NaturalStructures::definitions($candidate)[$family])){ $known[] = $candidate; }
		}
		if($known === []){ return '未知或不支持自然生成的建筑：'.$name.'。使用 /structure list 查看建筑系列。'; }
		if(!in_array($dimension, $known, true)){
			return $family.' 不在当前维度 '.$dimension.' 自然生成；支持维度：'.implode(', ', $known).'。';
		}
		if(($settings['enabled'] ?? true) === false || ($settings['families'][$family]['enabled'] ?? true) === false){
			return $family.' 的自然生成已在配置中禁用。';
		}
		return null;
	}

	/** Keep spacing/separation clamping identical to NaturalStructures::start(). */
	public static function definition(string $family, string $dimension, array $settings = []) : ?array{
		$dimension = self::dimension($dimension);
		$def = NaturalStructures::definitions($dimension)[$family] ?? null;
		if($def !== null && $family === 'end_city' && $dimension === 'end'){
			$def[0] = EndCityGenerator::SPACING;
			$def[1] = EndCityGenerator::SEPARATION;
			return $def; // Source End City random-spread placement has fixed spacing.
		}
		if($def !== null && is_array($settings['families'][$family] ?? null) && isset($settings['families'][$family]['spacing'])){
			$def[0] = max(12, min(128, (int) $settings['families'][$family]['spacing']));
			$def[1] = min($def[1], $def[0] - 4);
		}
		return $def;
	}

	/** Exact candidate anchor in blocks; End Cities use their source placement stream. */
	public static function anchor(int $seed, string $family, int $rx, int $rz, int $spacing, int $separation) : array{
		if($family === 'end_exit_portal'){ return [-4, -4]; }
		if($family === 'end_city'){
			$candidate = EndCityGenerator::candidate($seed, $rx, $rz);
			return [$candidate['x'], $candidate['z']];
		}
		$hash = (int) hexdec(substr(hash('sha256', $seed.':'.$family.':'.$rx.':'.$rz), 0, 7));
		$span = $spacing - $separation;
		return [($rx * $spacing + $hash % $span) * 16 + 8, ($rz * $spacing + intdiv($hash, 97) % $span) * 16 + 8];
	}

	/** Minimum squared distance to any possible anchor in this region. */
	public static function regionLowerBoundSquared(int $rx, int $rz, int $x, int $z, int $spacing, int $separation, int $anchorOffset = 8) : int{
		$minX = $rx * $spacing * 16 + $anchorOffset;
		$minZ = $rz * $spacing * 16 + $anchorOffset;
		$span = ($spacing - $separation - 1) * 16;
		$dx = max($minX - $x, 0, $x - ($minX + $span));
		$dz = max($minZ - $z, 0, $z - ($minZ + $span));
		return $dx * $dx + $dz * $dz;
	}

	/** Lower bound for the entire infinite set outside the enumerated rectangle. */
	private static function outsideLowerBoundSquared(int $minRX, int $maxRX, int $minRZ, int $maxRZ, int $x, int $z, int $spacing, int $separation, int $anchorOffset = 8) : int{
		$step = $spacing * 16;
		$span = ($spacing - $separation - 1) * 16;
		$distances = [
			max(0, $x - (($minRX - 1) * $step + $span + $anchorOffset)),
			max(0, ($maxRX + 1) * $step + $anchorOffset - $x),
			max(0, $z - (($minRZ - 1) * $step + $span + $anchorOffset)),
			max(0, ($maxRZ + 1) * $step + $anchorOffset - $z)
		];
		$distance = min($distances);
		return $distance * $distance;
	}

	/**
	 * Enumerate cheaply, then call the terrain engine best-first by exact anchor distance.
	 * A successful result is proven against both unvisited anchors and the infinite exterior.
	 * Budget/radius exhaustion without that proof is explicitly incomplete, even with a hit.
	 */
	public function locate(string $name, int $x, int $z, int $regionBudget = 256, int $radius = 40) : array{
		$request = self::normalize($name);
		$family = $request['family'];
		$result = [
			'status'=>'incomplete', 'complete'=>false, 'family'=>$family, 'variant'=>$request['variant'],
			'dimension'=>$this->dimension, 'origin'=>[$x, $z], 'plan'=>null,
			'regionsChecked'=>0, 'regionsEnumerated'=>0, 'regionsRemaining'=>0,
			'distanceSquared'=>null, 'remainingLowerBoundSquared'=>null,
			'outsideLowerBoundSquared'=>null, 'enumeratedExhausted'=>false, 'proof'=>'none'
		];
		$error = self::requestError($name, $this->dimension, $this->settings);
		if($error !== null){ $result['status'] = 'error'; $result['error'] = $error; return $result; }
		if($regionBudget < 1 || $regionBudget > 4096 || $radius < 0 || $radius > 128){
			throw new \InvalidArgumentException('Invalid structure search budget/radius');
		}
		$def = self::definition($family, $this->dimension, $this->settings);
		$rx = (int) floor($x / ($def[0] * 16));
		$rz = (int) floor($z / ($def[0] * 16));
		$minRX = $rx - $radius; $maxRX = $rx + $radius;
		$minRZ = $rz - $radius; $maxRZ = $rz + $radius;
		$finite = $family === 'end_exit_portal';
		if($finite){ $minRX = $maxRX = $minRZ = $maxRZ = 0; }
		$result['searchBounds'] = [$minRX, $minRZ, $maxRX, $maxRZ];
		$anchorOffset = $family === 'end_city' ? 7 : 8;
		$outside = $finite ? null : self::outsideLowerBoundSquared($minRX, $maxRX, $minRZ, $maxRZ, $x, $z, $def[0], $def[1], $anchorOffset);
		$result['outsideLowerBoundSquared'] = $outside;
		$regions = [];
		for($cx = $minRX; $cx <= $maxRX; ++$cx){
			for($cz = $minRZ; $cz <= $maxRZ; ++$cz){
				[$ax, $az] = self::anchor($this->seed, $family, $cx, $cz, $def[0], $def[1]);
				$regions[] = [($ax - $x) ** 2 + ($az - $z) ** 2, $cx, $cz, $ax, $az];
			}
		}
		usort($regions, static function(array $a, array $b) : int{
			return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]);
		});
		$result['regionsEnumerated'] = count($regions);
		$best = null; $bestDistance = null; $checked = 0;
		foreach($regions as [$distance, $cx, $cz, $ax, $az]){
			if($best !== null && $distance >= $bestDistance){ break; }
			if($checked >= $regionBudget){ break; }
			++$checked;
			$plan = $this->engine->start($family, $cx, $cz);
			if($plan === null || ($request['variant'] !== '' && ($plan['variant'] ?? '') !== $request['variant'])){ continue; }
			if($plan['x'] !== $ax || $plan['z'] !== $az){
				throw new \RuntimeException('建筑定位与生成器锚点不一致；请检查定位配置。');
			}
			$best = $plan; $bestDistance = $distance;
			$result['region'] = [$cx, $cz];
		}
		$next = $regions[$checked][0] ?? null;
		$remaining = $next === null ? $outside : ($outside === null ? $next : min($next, $outside));
		$result['plan'] = $best;
		$result['distanceSquared'] = $bestDistance;
		$result['regionsChecked'] = $checked;
		$result['regionsRemaining'] = count($regions) - $checked;
		$result['enumeratedExhausted'] = $next === null;
		$result['remainingLowerBoundSquared'] = $remaining;
		if($best !== null && ($remaining === null || $remaining >= $bestDistance)){
			$result['status'] = 'found'; $result['complete'] = true; $result['proof'] = 'distance';
		}elseif($remaining === null){
			$result['status'] = 'absent'; $result['complete'] = true; $result['proof'] = 'exhausted';
		}else{
			$result['proof'] = $checked >= $regionBudget && $next !== null ? 'budget' : 'boundary';
		}
		return $result;
	}
}
