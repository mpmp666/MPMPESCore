<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\ChunkManager;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\ender\EndCityGenerator;
use pocketmine\level\generator\ender\noise\EndTerrainNoise;

/** Seeded region starts, biome filtering, deterministic layouts, clipped current-chunk writes. */
final class NaturalStructures{
	private StructureTerrain $terrain;
	private array $plans = [];
	private bool $enabled;
	private array $settings;
	private string $dimension;
	private int $seed;
	private ?EndTerrainNoise $endTerrain = null;

	public function __construct(Generator $generator, ChunkManager $level, array $options = []){
		$name = strtolower($generator->getName());
		$this->dimension = $name === 'ender' ? 'end' : ($name === 'nether' ? 'nether' : 'overworld');
		$this->seed = (int) $level->getSeed();
		$this->settings = is_array($options['structure-settings'] ?? null) ? $options['structure-settings'] : [];
		$this->enabled = ($options['structures'] ?? true) !== false && ($this->settings['enabled'] ?? true) !== false;
		$this->terrain = new StructureTerrain(get_class($generator), $this->seed);
		if($generator instanceof Ender){$this->endTerrain=$generator->terrainNoise();}
	}

	/** Family => [region spacing in chunks, separation, underground/surface, biome ids]. */
	public static function definitions(string $dimension) : array{
		return match($dimension){
			'end' => ['end_city' => [20, 11, 'end', []], 'end_exit_portal' => [32, 16, 'end_center', []]],
			'nether' => ['bastion' => [28, 10, 'nether', []], 'nether_fortress' => [28, 10, 'nether', []], 'nether_fossils' => [12, 5, 'nether', []], 'ruined_portal' => [24, 9, 'nether', []]],
			default => [
				'village' => [28, 10, 'surface', [1,2,5,12]],
				'pillager_outpost' => [32, 12, 'surface', [1,2,4,5,12,27]],
				'woodland_mansion' => [64, 24, 'surface', [4,27]],
				'desert_pyramid' => [28, 10, 'surface', [2]],
				'desert_well' => [20, 8, 'surface', [2]],
				'jungle_temple' => [28, 10, 'surface', [4,27]],
				'swamp_hut' => [28, 10, 'surface', [6]],
				'igloo' => [28, 10, 'surface', [5,12]],
				'ruined_portal' => [28, 10, 'surface', []],
				'shipwreck' => [24, 8, 'ocean', [0,7]],
				'underwater_ruin' => [20, 8, 'ocean', [0,7]],
				'ocean_monument' => [32, 12, 'ocean', [0]],
				'buried_treasure' => [20, 8, 'buried', [0,7,2]],
				'trail_ruins' => [28, 10, 'buried', [4,5,27]],
				'ancient_city' => [40, 16, 'underground', []],
				'trial_chambers' => [32, 12, 'underground', []],
				'stronghold' => [64, 24, 'underground', []],
				'mineshaft' => [20, 8, 'underground', []],
				'bonus_dungeon' => [12, 5, 'underground', []],
				'fossil' => [24, 10, 'underground', [2,6]]
			]
		};
	}

	private static function number(string $value) : int{
		return (int) hexdec(substr(hash('sha256', $value), 0, 7));
	}

	private function definition(string $family, array $def) : array{
		$override = $this->settings['families'][$family] ?? [];
		// Vanilla End City triangular placement is fixed at 20/11, salt 10387313.
		if($this->dimension !== 'end' && is_array($override) && isset($override['spacing'])){
			$def[0] = max(12, min(128, (int) $override['spacing']));
			$def[1] = min($def[1], $def[0] - 4);
		}
		return $def;
	}

	public function start(string $family, int $regionX, int $regionZ) : ?array{
		$def = self::definitions($this->dimension)[$family] ?? null;
		if(!$this->enabled || $def === null || ($this->settings['families'][$family]['enabled'] ?? true) === false){ return null; }
		$def = $this->definition($family, $def);
		$key = $family . ':' . $regionX . ':' . $regionZ;
		if(array_key_exists($key, $this->plans)){ return $this->plans[$key]; }
		if($this->dimension === 'end'){
			return $this->remember($key, $this->endStart($family, $regionX, $regionZ));
		}
		$hash = self::number($this->seed . ':' . $key);
		$span = $def[0] - $def[1];
		$cx = $regionX * $def[0] + $hash % $span;
		$cz = $regionZ * $def[0] + intdiv($hash, 97) % $span;
		$x = $cx * 16 + 8; $z = $cz * 16 + 8;
		// One architectural fountain at the actual main-island centre, never a portal mechanic.
		if($family==='end_exit_portal'){
			if($regionX!==0||$regionZ!==0){return null;}
			$x=131;$z=131;
		}
		$plan = null;
		[$height, $biome, $water] = $this->terrain->sample($x, $z);
		$valid = ($def[3] === [] || in_array($biome, $def[3], true));
		if($def[2] === 'surface' && ($height < 61 || $water > $height || $height > 100)){ $valid = false; }
		if($def[2] === 'ocean' && ($water < 55 || $height > $water - 5)){ $valid = false; }
		if($def[2] === 'end' && ($height < 40 || hypot($x - 135, $z - 135) < 240)){ $valid = false; }
		if($valid){
			$variant = $family === 'village' ? match($biome){ 2 => 'desert', 5 => 'taiga', 12 => 'snowy', default => ($hash % 5 === 0 ? 'savanna' : 'plains') } : '';
			$pieces = in_array($family, LegacyStructures::families(), true) ? [['template'=>$family,'x'=>0,'y'=>0,'z'=>0,'rotation'=>$hash & 3]] : StructureAssembler::build($family, $hash, $variant);
			if($pieces !== []){
				if($family==='end_exit_portal'){$pieces[0]['rotation']=0;}
				$bounds = StructureRepository::bounds($pieces);
				$heightSpan = $bounds[4] - $bounds[1];
				$ground = StructureRepository::manifest()['templates'][$pieces[0]['template']]['ground_level'] ?? (StructureRepository::template($pieces[0]['template'])['ground_level'] ?? 0);
				if(in_array($def[2],['surface','end','end_center'],true)&&!$this->rootFits($pieces[0],$x,$z,$height)){
					return $this->remember($key,null);
				}
				$netherFloor=$def[2]==='nether'?$this->terrain->netherFloor($x,$z,124-$heightSpan):null;
				if($def[2]==='nether'&&$netherFloor===null){return $this->remember($key,null);}
				$y = match($def[2]){
					'underground' => max(8, min(35, $height - $heightSpan - 12)) - $bounds[1],
					'buried' => $height - min(12, intdiv($heightSpan, 2)) - $bounds[1],
					'nether' => $netherFloor + 1 - $bounds[1],
					'ocean' => max(4, $height + 1) - $bounds[1] - (int)$ground,
					default => $height + 1 - (int) $ground
				};
				if($heightSpan <= 122){
					$y = max(2 - $bounds[1], min(125 - $bounds[4], $y));
					$plan = ['family'=>$family,'variant'=>$variant,'x'=>$x,'y'=>$y,'z'=>$z,'pieces'=>$pieces,'bounds'=>$bounds,'mode'=>$def[2],'seed'=>$hash];
				}
			}
		}
		return $this->remember($key,$plan);
	}

	/** Source End City start: triangular placement, raw terrain samples and biome tag. */
	private function endStart(string $family,int $regionX,int $regionZ) : ?array{
		if($this->endTerrain===null){return null;}
		if($family==='end_exit_portal'){
			if($regionX!==0||$regionZ!==0){return null;}
			$podiumY=max(1,$this->endTerrain->surfaceHeight(0,0)-1);
			// The actual source fountain is placed by EndFeatures, after structures.
			return ['family'=>$family,'variant'=>'','x'=>-4,'y'=>$podiumY-1,'z'=>-4,'pieces'=>[],
				'bounds'=>[0,0,0,8,6,8],'mode'=>'end_center','seed'=>$this->seed];
		}
		if($family!=='end_city'){return null;}
		$terrain=$this->endTerrain;
		$plan=EndCityGenerator::plan($this->seed,$regionX,$regionZ,
			static fn(int $x,int $z):int=>$terrain->surfaceHeight($x,$z),
			static fn(int $qx,int $qy,int $qz):bool=>in_array($terrain->biome($qx*4,$qz*4),['end_highlands','end_midlands'],true));
		if($plan===null){return null;}
		$plan['variant']='';$plan['mode']='end';$plan['seed']=$plan['source_seed'];
		$plan['height_clipped']=$plan['y']+$plan['bounds'][4]>127||$plan['y']+$plan['bounds'][1]<0;
		return $plan;
	}

	private function remember(string $key,?array $plan) : ?array{
		if(count($this->plans)>=128){array_shift($this->plans);}
		$this->plans[$key]=$plan;
		return $plan;
	}

	/** Check only the grounded root, not intentionally suspended bridges or ships. */
	private function rootFits(array $root,int $x,int $z,int $anchorHeight) : bool{
		$size=StructureRepository::manifest()['templates'][$root['template']]['size']??StructureRepository::template($root['template'])['size'];
		foreach([[0,0],[$size[0]-1,0],[0,$size[2]-1],[$size[0]-1,$size[2]-1]] as [$lx,$lz]){
			[$lx,$lz]=StructureRepository::rotate($lx,$lz,$root['rotation']);
			[$height,,$water]=$this->terrain->sample($x+$root['x']+$lx,$z+$root['z']+$lz);
			if($height<1||$water>$height||abs($height-$anchorHeight)>12){return false;}
		}
		return true;
	}

	/** Only a bounded neighborhood can intersect a chunk (assembler radius <=112 blocks). */
	public function forChunk(int $chunkX, int $chunkZ) : array{
		if(!$this->enabled){ return []; }
		if($this->dimension==='end'){return $this->endForChunk($chunkX,$chunkZ);}
		$plans = [];
		foreach(self::definitions($this->dimension) as $family => $def){
			if($family==='end_exit_portal'){
				if($chunkX===8&&$chunkZ===8){
					$plan=$this->start($family,0,0);
					if($plan!==null){$plans[]=$plan;}
				}
				continue;
			}
			$def = $this->definition($family, $def);
			$reach=(int)ceil(StructureAssembler::MAX_SPAN/16);
			$minRegionX=(int)floor(($chunkX-$reach)/$def[0])-1;
			$maxRegionX=(int)floor(($chunkX+$reach)/$def[0])+1;
			$minRegionZ=(int)floor(($chunkZ-$reach)/$def[0])-1;
			$maxRegionZ=(int)floor(($chunkZ+$reach)/$def[0])+1;
			for($rx=$minRegionX;$rx<=$maxRegionX;++$rx){
				for($rz=$minRegionZ;$rz<=$maxRegionZ;++$rz){
					$span = $def[0] - $def[1];
					$hash = self::number($this->seed . ':' . $family . ':' . $rx . ':' . $rz);
					$anchorX = $rx * $def[0] + $hash % $span;
					$anchorZ = $rz * $def[0] + intdiv($hash, 97) % $span;
					if(abs($anchorX - $chunkX) > $reach + 1 || abs($anchorZ - $chunkZ) > $reach + 1){ continue; }
					$plan = $this->start($family, $rx, $rz);
					if($plan === null){ continue; }
					$b = $plan['bounds'];
					if($plan['x'] + $b[3] >= $chunkX * 16 && $plan['x'] + $b[0] <= $chunkX * 16 + 15 && $plan['z'] + $b[5] >= $chunkZ * 16 && $plan['z'] + $b[2] <= $chunkZ * 16 + 15){ $plans[] = $plan; }
				}
			}
		}
		return $plans;
	}

	/** Discover every source layout that may cross this chunk, without old 112-block caps. */
	private function endForChunk(int $chunkX,int $chunkZ) : array{
		$plans=[];$reach=(int)ceil(EndCityGenerator::MAX_HORIZONTAL_REACH/16)+1;
		$spacing=EndCityGenerator::SPACING;
		$minRX=(int)floor(($chunkX-$reach)/$spacing)-1;$maxRX=(int)floor(($chunkX+$reach)/$spacing);
		$minRZ=(int)floor(($chunkZ-$reach)/$spacing)-1;$maxRZ=(int)floor(($chunkZ+$reach)/$spacing);
		for($rx=$minRX;$rx<=$maxRX;++$rx){for($rz=$minRZ;$rz<=$maxRZ;++$rz){
			$candidate=EndCityGenerator::candidate($this->seed,$rx,$rz);
			if(abs($candidate['chunkX']-$chunkX)>$reach||abs($candidate['chunkZ']-$chunkZ)>$reach){continue;}
			$plan=$this->start('end_city',$rx,$rz);if($plan===null){continue;}
			$b=$plan['bounds'];
			if($plan['x']+$b[3]>=$chunkX*16&&$plan['x']+$b[0]<=$chunkX*16+15&&$plan['z']+$b[5]>=$chunkZ*16&&$plan['z']+$b[2]<=$chunkZ*16+15){$plans[]=$plan;}
		}}
		return $plans;
	}

	/** Used by PopulationTask in its worker; metadata travels separately to the main thread. */
	public function populate(ChunkManager $level, int $chunkX, int $chunkZ) : array{
		$tiles = []; $entities=[];
		$chunk = $level->getChunk($chunkX, $chunkZ);
		if($chunk === null){ return []; }
		foreach($this->forChunk($chunkX, $chunkZ) as $plan){
			// Nether templates have omitted exterior cells. Carve their bounded piece envelopes
			// into the cave wall so room interiors and bridge headroom are usable at the chosen floor.
			if($plan['mode']==='nether'){
				foreach($plan['pieces'] as $piece){
					$b=StructureRepository::bounds([$piece]);
					$minX=max(0,$plan['x']+$b[0]-$chunkX*16);$maxX=min(15,$plan['x']+$b[3]-$chunkX*16);
					$minZ=max(0,$plan['z']+$b[2]-$chunkZ*16);$maxZ=min(15,$plan['z']+$b[5]-$chunkZ*16);
					for($x=$minX;$x<=$maxX;++$x){for($z=$minZ;$z<=$maxZ;++$z){
						for($y=max(1,$plan['y']+$b[1]);$y<=min(126,$plan['y']+$b[4]);++$y){
							if($chunk->getBlockId($x,$y,$z)!==7){$chunk->setBlock($x,$y,$z,0,0);$chunk->setBlockLight($x,$y,$z,0);}
						}
					}}
				}
			}
			$result = StructurePlacer::chunk($chunk, $plan['pieces'], $plan['x'], $plan['y'], $plan['z'], $plan['mode']==='surface');
			$tiles = array_merge($tiles, $result['tiles']);
			$entities=array_merge($entities,$result['entities']);
		}
		return ['tiles'=>$tiles,'entities'=>$entities];
	}


	public function locate(string $family, int $x, int $z, int $radius = 6) : ?array{
		if($family==='end_exit_portal'&&$this->dimension==='end'){return $this->start($family,0,0);}
		$def = self::definitions($this->dimension)[$family] ?? null;
		if($def === null){ return null; }
		$def = $this->definition($family, $def);
		$rx = (int) floor(($x >> 4) / $def[0]); $rz = (int) floor(($z >> 4) / $def[0]);
		$best = null; $distance = PHP_INT_MAX;
		for($ring = 0; $ring <= min(12, max(0, $radius)); ++$ring){
			for($dx = -$ring; $dx <= $ring; ++$dx){
				for($dz = -$ring; $dz <= $ring; ++$dz){
					if(max(abs($dx), abs($dz)) !== $ring){ continue; }
					$plan = $this->start($family, $rx + $dx, $rz + $dz);
					if($plan !== null && ($d = ($plan['x']-$x)**2 + ($plan['z']-$z)**2) < $distance){ $distance=$d; $best=$plan; }
				}
			}
			if($best !== null && $ring >= 1){ return $best; }
		}
		return $best;
	}
}
