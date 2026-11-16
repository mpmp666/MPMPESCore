<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender;

use pocketmine\block\Block;
use pocketmine\level\format\FullChunk;
use pocketmine\level\generator\ender\noise\EndTerrainNoise;
use pocketmine\level\generator\ender\noise\JavaRandom;
use pocketmine\level\generator\ender\noise\XoroshiroRandom;

/**
 * Java 26.2 End feature geometry, clipped to the supplied chunk.
 *
 * Spike/podium/platform positions come from EndSpikeFeature, EndPodiumFeature,
 * EndPlatformFeature and EnderDragonFight, rather than terrain-relative pillars.
 * The exit fountain is the source's inactive, pre-fight fountain (air interior).
 * The natural placed_feature JSON uses END_SPAWN_POINT.below(), origin Y49:
 * floor48, clear49..51. Runtime entering-the-End mutation at spawn point Y50
 * (floor49, clear50..52) is gameplay and is not implemented here.
 * Legacy visual substitutions keep source occupied cells: chorus is purple/
 * magenta stained clay and the gateway core is glowstone. Connection/age block
 * properties, entities and portal/gameplay logic are outside this old protocol.
 */
final class EndFeatures{
	/** EndPlacements.END_PLATFORM / placed_feature/end_platform.json. */
	public const PLATFORM_ORIGIN_Y = 49;
	private int $seed;
	private EndTerrainNoise $terrain;
	/** @var array<int, array<string, int|bool>> */
	private array $spikes = [];
	private int $spikeKey;
	private int $podiumY;
	private int $biomeZoomSeed;
	/** Immutable per-source-chunk drafts, bounded independently of loaded chunks. */
	private array $islandPlans = [];
	private array $decorationPlans = [];
	private array $gatewayPlans = [];
	private array $biomes = [];
	private array $zoomedBiomes = [];
	// Logical draft IDs only. put() maps these unsupported states to registered
	// legacy materials: purple/magenta clay and a glowstone gateway core.
	private const CHORUS_PLANT = 240;
	private const CHORUS_FLOWER = 200;
	private const END_GATEWAY = 209;
	private const HORIZONTAL = [[0, -1], [1, 0], [0, 1], [-1, 0]];

	public function __construct(int $seed, EndTerrainNoise $terrain){
		$this->seed = $seed;
		$this->terrain = $terrain;
		// BiomeManager.obfuscateSeed: Guava hashes a little-endian long and reads
		// the first eight digest bytes as another little-endian long.
		$hash = hash('sha256', pack('V2', $seed & 0xffffffff, ($seed >> 32) & 0xffffffff), true);
		$words = unpack('Vlo/Vhi', substr($hash, 0, 8));
		$this->biomeZoomSeed = ($words['hi'] << 32) | $words['lo'];
		$this->spikeKey = (new JavaRandom($seed))->nextLong() & 65535;
		$random = new JavaRandom($this->spikeKey);
		$sizes = range(0, 9);
		// Util.toShuffledList(IntStream, RandomSource), descending Fisher-Yates.
		for($i = 10; $i > 1; --$i){
			$j = $random->nextInt($i);
			[$sizes[$i - 1], $sizes[$j]] = [$sizes[$j], $sizes[$i - 1]];
		}
		for($i = 0; $i < 10; ++$i){
			$angle = 2.0 * (-M_PI + (M_PI / 10) * $i);
			$size = $sizes[$i];
			$this->spikes[] = [
				'centerX' => (int) floor(42.0 * cos($angle)),
				'centerZ' => (int) floor(42.0 * sin($angle)),
				'radius' => 2 + intdiv($size, 3),
				'height' => 76 + $size * 3,
				'guarded' => $size === 1 || $size === 2,
				'size' => $size
			];
		}
		// EnderDragonFight.spawnExitPortal: heightmap position, below(), minY+1.
		$this->podiumY = max(1, $terrain->surfaceHeight(0, 0) - 1);
	}

	/** Source layout, independent of chunk generation order. */
	public function getSpikes() : array{
		return $this->spikes;
	}

	public function getSpikeKey() : int{
		return $this->spikeKey;
	}

	public function getPodiumY() : int{
		return $this->podiumY;
	}

	/**
	 * Populate only this FullChunk; never request, mutate or create adjacent chunks.
	 * Replaying each intersecting piece also fills spike/cage borders correctly.
	 * @return array<string, mixed> Geometry that intersects this chunk.
	 */
	public function populate(FullChunk $chunk) : array{
		$chunkX = (int) $chunk->getX();
		$chunkZ = (int) $chunk->getZ();
		$metadata = ['spikes' => [], 'podium' => false, 'platform' => false, 'gateways' => [], 'islands' => [], 'chorus' => []];
		// Reconstruct every intersecting source-chunk feature, always in the same
		// order. All drafts read immutable base terrain, never loaded neighbours.
		$plans = [];
		for($cx = $chunkX - 1; $cx <= $chunkX + 1; ++$cx){
			for($cz = $chunkZ - 1; $cz <= $chunkZ + 1; ++$cz){
				$plan = $this->plans($cx, $cz);
				$plans[] = $plan;
				$this->applyBlocks($chunk, $plan['islandBlocks']);
				foreach($plan['islands'] as $island){
					if($this->intersects($chunkX, $chunkZ, $island['x'] - 6, $island['z'] - 6, $island['x'] + 6, $island['z'] + 6)){
						$metadata['islands'][] = $island;
					}
				}
			}
		}
		foreach($plans as $plan){
			$this->applyBlocks($chunk, $plan['gatewayBlocks']);
			foreach($plan['gateways'] as $gateway){
				if($this->intersects($chunkX, $chunkZ, $gateway['x'] - 1, $gateway['z'] - 1, $gateway['x'] + 1, $gateway['z'] + 1)){
					$metadata['gateways'][] = $gateway;
				}
			}
		}
		foreach($this->spikes as $spike){
			$extent = max($spike['radius'], $spike['guarded'] ? 2 : 0);
			if(!$this->intersects($chunkX, $chunkZ, $spike['centerX'] - $extent, $spike['centerZ'] - $extent, $spike['centerX'] + $extent, $spike['centerZ'] + $extent)){
				continue;
			}
			$this->placeSpike($chunk, $spike);
			$metadata['spikes'][] = $spike;
		}
		foreach($plans as $plan){
			$this->applyBlocks($chunk, $plan['chorusBlocks']);
			foreach($plan['chorus'] as $plant){
				if($this->intersects($chunkX, $chunkZ, $plant['x'] - 7, $plant['z'] - 7, $plant['x'] + 7, $plant['z'] + 7)){
					$metadata['chorus'][] = $plant;
				}
			}
		}
		if($this->intersects($chunkX, $chunkZ, -4, -4, 4, 4)){
			$this->placePodium($chunk);
			$metadata['podium'] = true;
		}
		if($this->intersects($chunkX, $chunkZ, 98, -2, 102, 2)){
			$this->placePlatform($chunk);
			$metadata['platform'] = true;
		}
		return $metadata;
	}

	/**
	 * Source feature drafts for one decorating chunk, with world-coordinate block
	 * arrays keyed by x:y:z. Useful for locating real gateway feature origins.
	 *
	 * Exact: Xoroshiro WorldgenRandom seeding, FeatureSorter indices, placement
	 * modifiers, BiomeManager zoom, and individual feature algorithms. Terrain
	 * queries deliberately use an immutable terrain + earlier feature draft. They
	 * do not include city blocks or order-dependent overlaps from other chunks;
	 * those collision cases cannot reproduce Java's mutable WorldGenRegion using
	 * this chunk-only API. Applying overlapping drafts uses ascending chunk x/z.
	 */
	public function plans(int $chunkX, int $chunkZ) : array{
		$key = $chunkX . ':' . $chunkZ;
		if(isset($this->decorationPlans[$key])){
			return $this->decorationPlans[$key];
		}
		$islands = $this->islandsForChunk($chunkX, $chunkZ);
		$gateway = $this->gatewaysForChunk($chunkX, $chunkZ);
		$plan = [
			'chunkX' => $chunkX, 'chunkZ' => $chunkZ,
			'decorationSeed' => $this->decorationSeed($chunkX, $chunkZ),
			'featureSeeds' => [],
			'islands' => $islands['origins'], 'islandBlocks' => $islands['blocks'],
			'gateways' => $gateway['origins'], 'gatewayBlocks' => $gateway['blocks'],
			'chorus' => [], 'chorusBlocks' => []
		];
		foreach(['end_island_decorated' => [0, 0], 'end_gateway_return' => [0, 4], 'end_spike' => [1, 4], 'chorus_plant' => [0, 9], 'end_platform' => [0, 10]] as $name => [$index, $step]){
			$plan['featureSeeds'][$name] = JavaRandom::addLong($plan['decorationSeed'], $index + 10000 * $step);
		}
		if($this->nearBiome($chunkX, $chunkZ, 'end_highlands')){
			$blocks = $this->earlierBlocks($chunkX, $chunkZ, true);
			$random = $this->featureRandom($chunkX, $chunkZ, 0, 9);
			// CountPlacement UniformInt[0,4], then lazy flatMap placements: each
			// complete plant consumes random draws BEFORE the next x/z position.
			$count = $random->nextInt(5);
			for($i = 0; $i < $count; ++$i){
				$x = $chunkX * 16 + $random->nextInt(16);
				$z = $chunkZ * 16 + $random->nextInt(16);
				$y = $this->draftSurface($blocks, $x, $z);
				if($y <= 0 || $this->biomeAt($x, $y, $z) !== 'end_highlands'){
					continue;
				}
				if($this->draftBlock($blocks, $x, $y, $z) !== Block::AIR || $this->draftBlock($blocks, $x, $y - 1, $z) !== Block::END_STONE){
					continue;
				}
				$before = $blocks;
				$this->draftPut($blocks, $x, $y, $z, self::CHORUS_PLANT);
				$this->growChorus($blocks, $random, $x, $y, $z, $x, $z, 0);
				foreach($blocks as $blockKey => $block){
					if(!isset($before[$blockKey]) || $before[$blockKey] !== $block){
						$plan['chorusBlocks'][$blockKey] = $block;
					}
				}
				$plan['chorus'][] = ['x' => $x, 'y' => $y, 'z' => $z, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ];
			}
		}
		if(count($this->decorationPlans) >= 48){
			unset($this->decorationPlans[array_key_first($this->decorationPlans)]);
		}
		return $this->decorationPlans[$key] = $plan;
	}

	/** All source return gateways in this decorating chunk (usually empty). */
	public function gateways(int $chunkX, int $chunkZ) : array{
		return $this->gatewaysForChunk($chunkX, $chunkZ)['origins'];
	}

	public function decorationSeed(int $chunkX, int $chunkZ) : int{
		$random = new XoroshiroRandom(0);
		return $random->setDecorationSeed($this->seed, $chunkX * 16, $chunkZ * 16);
	}

	private function featureRandom(int $chunkX, int $chunkZ, int $index, int $step) : XoroshiroRandom{
		$random = new XoroshiroRandom(0);
		$random->setFeatureSeed($this->decorationSeed($chunkX, $chunkZ), $index, $step);
		return $random;
	}

	private function nearBiome(int $chunkX, int $chunkZ, string $biome) : bool{
		for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
			for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
				$key = $x . ':' . $z;
				if(!isset($this->biomes[$key])){
					if(count($this->biomes) >= 4096){
						$this->biomes = [];
					}
					$this->biomes[$key] = $this->terrain->biome($x * 16, $z * 16);
				}
				if($this->biomes[$key] === $biome){
					return true;
				}
			}
		}
		return false;
	}

	/** BiomeManager's source Voronoi zoom; End noise biomes ignore quart y. */
	public function biomeAt(int $x, int $y, int $z) : string{
		$key = $x . ':' . $y . ':' . $z;
		if(isset($this->zoomedBiomes[$key])){
			return $this->zoomedBiomes[$key];
		}
		$px = ($x - 2) >> 2;
		$py = ($y - 2) >> 2;
		$pz = ($z - 2) >> 2;
		$fx = (($x - 2) & 3) / 4.0;
		$fy = (($y - 2) & 3) / 4.0;
		$fz = (($z - 2) & 3) / 4.0;
		$best = INF;
		$bestX = $px;
		$bestZ = $pz;
		for($i = 0; $i < 8; ++$i){
			$ox = ($i & 4) === 0 ? 0 : 1;
			$oy = ($i & 2) === 0 ? 0 : 1;
			$oz = ($i & 1) === 0 ? 0 : 1;
			$qx = $px + $ox;
			$qy = $py + $oy;
			$qz = $pz + $oz;
			$r = $this->biomeZoomSeed;
			foreach([$qx, $qy, $qz, $qx, $qy, $qz] as $coordinate){
				$r = self::biomeMix($r, $coordinate);
			}
			$dx = $fx - $ox + self::biomeFiddle($r);
			$r = self::biomeMix($r, $this->biomeZoomSeed);
			$dy = $fy - $oy + self::biomeFiddle($r);
			$r = self::biomeMix($r, $this->biomeZoomSeed);
			$dz = $fz - $oz + self::biomeFiddle($r);
			$distance = $dz * $dz + $dy * $dy + $dx * $dx;
			if($distance < $best){
				$best = $distance;
				$bestX = $qx;
				$bestZ = $qz;
			}
		}
		if(count($this->zoomedBiomes) >= 2048){
			$this->zoomedBiomes = [];
		}
		return $this->zoomedBiomes[$key] = $this->terrain->biome($bestX * 4, $bestZ * 4);
	}

	private static function biomeMix(int $value, int $coordinate) : int{
		return JavaRandom::addLong(JavaRandom::multiplyLong($value, JavaRandom::addLong(JavaRandom::multiplyLong($value, 6364136223846793005), 1442695040888963407)), $coordinate);
	}

	private static function biomeFiddle(int $value) : float{
		return ((($value >> 24) & 1023) / 1024.0 - 0.5) * 0.9;
	}

	private function islandsForChunk(int $chunkX, int $chunkZ) : array{
		$key = $chunkX . ':' . $chunkZ;
		if(isset($this->islandPlans[$key])){
			return $this->islandPlans[$key];
		}
		$plan = ['origins' => [], 'blocks' => []];
		if($this->nearBiome($chunkX, $chunkZ, 'small_end_islands')){
			$random = $this->featureRandom($chunkX, $chunkZ, 0, 0);
			// RarityFilter uses nextFloat(), not nextInt(14).
			$threshold = unpack('f', pack('f', 1.0 / 14.0))[1];
			if($random->nextFloat() < $threshold){
				// countExtra(1,.25,1): weighted [1*3, 2*1].
				$count = $random->nextInt(4) < 3 ? 1 : 2;
				for($i = 0; $i < $count; ++$i){
					$x = $chunkX * 16 + $random->nextInt(16);
					$z = $chunkZ * 16 + $random->nextInt(16);
					$y = 55 + $random->nextInt(16);
					if($this->biomeAt($x, $y, $z) !== 'small_end_islands'){
						continue;
					}
					$size = $random->nextInt(3) + 4.0;
					$plan['origins'][] = ['x' => $x, 'y' => $y, 'z' => $z, 'size' => (int) $size, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ];
					for($dy = 0; $size > 0.5; --$dy){
						for($dx = (int) floor(-$size); $dx <= (int) ceil($size); ++$dx){
							for($dz = (int) floor(-$size); $dz <= (int) ceil($size); ++$dz){
								if($dx * $dx + $dz * $dz <= ($size + 1.0) * ($size + 1.0)){
									$this->draftPut($plan['blocks'], $x + $dx, $y + $dy, $z + $dz, Block::END_STONE);
								}
							}
						}
						$size -= $random->nextInt(2) + 0.5;
					}
				}
			}
		}
		if(count($this->islandPlans) >= 128){
			unset($this->islandPlans[array_key_first($this->islandPlans)]);
		}
		return $this->islandPlans[$key] = $plan;
	}

	private function gatewaysForChunk(int $chunkX, int $chunkZ) : array{
		$key = $chunkX . ':' . $chunkZ;
		if(isset($this->gatewayPlans[$key])){
			return $this->gatewayPlans[$key];
		}
		$plan = ['origins' => [], 'blocks' => []];
		if($this->nearBiome($chunkX, $chunkZ, 'end_highlands')){
			$random = $this->featureRandom($chunkX, $chunkZ, 0, 4);
			$threshold = unpack('f', pack('f', 1.0 / 700.0))[1];
			if($random->nextFloat() < $threshold){
				$x = $chunkX * 16 + $random->nextInt(16);
				$z = $chunkZ * 16 + $random->nextInt(16);
				$blocks = $this->earlierBlocks($chunkX, $chunkZ, false);
				$y = $this->draftSurface($blocks, $x, $z);
				if($y > 0){
					$y += 3 + $random->nextInt(7);
					if($this->biomeAt($x, $y, $z) === 'end_highlands'){
						$plan['origins'][] = ['x' => $x, 'y' => $y, 'z' => $z, 'exit' => [100, 50, 0], 'exact' => true, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ];
						for($dx = -1; $dx <= 1; ++$dx){
							for($dz = -1; $dz <= 1; ++$dz){
								for($dy = -2; $dy <= 2; ++$dy){
									if($dx === 0 && $dy === 0 && $dz === 0){
										$id = self::END_GATEWAY;
									}elseif($dy === 0){
										$id = Block::AIR;
									}elseif(abs($dy) === 2){
										$id = $dx === 0 && $dz === 0 ? Block::BEDROCK : Block::AIR;
									}else{
										$id = $dx === 0 || $dz === 0 ? Block::BEDROCK : Block::AIR;
									}
									$this->draftPut($plan['blocks'], $x + $dx, $y + $dy, $z + $dz, $id);
								}
							}
						}
					}
				}
			}
		}
		if(count($this->gatewayPlans) >= 128){
			unset($this->gatewayPlans[array_key_first($this->gatewayPlans)]);
		}
		return $this->gatewayPlans[$key] = $plan;
	}

	private function earlierBlocks(int $chunkX, int $chunkZ, bool $withGateways) : array{
		$blocks = [];
		for($cx = $chunkX - 1; $cx <= $chunkX + 1; ++$cx){
			for($cz = $chunkZ - 1; $cz <= $chunkZ + 1; ++$cz){
				foreach($this->islandsForChunk($cx, $cz)['blocks'] as $key => $block){
					$blocks[$key] = $block;
				}
			}
		}
		if($withGateways){
			for($cx = $chunkX - 1; $cx <= $chunkX + 1; ++$cx){
				for($cz = $chunkZ - 1; $cz <= $chunkZ + 1; ++$cz){
					foreach($this->gatewaysForChunk($cx, $cz)['blocks'] as $key => $block){
						$blocks[$key] = $block;
					}
				}
			}
		}
		return $blocks;
	}

	private function draftPut(array &$blocks, int $x, int $y, int $z, int $id, int $data = 0) : void{
		if($y >= 0 && $y < 128){
			$blocks[$x . ':' . $y . ':' . $z] = [$x, $y, $z, $id, $data];
		}
	}

	private function draftBlock(array &$blocks, int $x, int $y, int $z) : int{
		if($y < 0 || $y >= 128){
			return Block::AIR;
		}
		$key = $x . ':' . $y . ':' . $z;
		if(isset($blocks[$key])){
			return $blocks[$key][3];
		}
		return $this->terrain->chunkGrid($x >> 4, $z >> 4)->solid($x & 15, $y, $z & 15) ? Block::END_STONE : Block::AIR;
	}

	private function draftSurface(array &$blocks, int $x, int $z) : int{
		for($y = 127; $y >= 0; --$y){
			if($this->draftBlock($blocks, $x, $y, $z) !== Block::AIR){
				return $y + 1;
			}
		}
		return 0;
	}

	private function allChorusNeighborsEmpty(array &$blocks, int $x, int $y, int $z, ?int $ignore = null) : bool{
		foreach(self::HORIZONTAL as $direction => [$dx, $dz]){
			if($direction !== $ignore && $this->draftBlock($blocks, $x + $dx, $y, $z + $dz) !== Block::AIR){
				return false;
			}
		}
		return true;
	}

	private function growChorus(array &$blocks, XoroshiroRandom $random, int $x, int $y, int $z, int $rootX, int $rootZ, int $depth) : void{
		$height = $random->nextInt(4) + 1 + ($depth === 0 ? 1 : 0);
		for($i = 0; $i < $height; ++$i){
			$targetY = $y + $i + 1;
			if(!$this->allChorusNeighborsEmpty($blocks, $x, $targetY, $z)){
				return;
			}
			$this->draftPut($blocks, $x, $targetY, $z, self::CHORUS_PLANT);
			$this->draftPut($blocks, $x, $targetY - 1, $z, self::CHORUS_PLANT);
		}
		$placedStem = false;
		if($depth < 4){
			$stems = $random->nextInt(4) + ($depth === 0 ? 1 : 0);
			for($i = 0; $i < $stems; ++$i){
				$direction = $random->nextInt(4);
				[$dx, $dz] = self::HORIZONTAL[$direction];
				$targetX = $x + $dx;
				$targetY = $y + $height;
				$targetZ = $z + $dz;
				if(abs($targetX - $rootX) < 8 && abs($targetZ - $rootZ) < 8
					&& $this->draftBlock($blocks, $targetX, $targetY, $targetZ) === Block::AIR
					&& $this->draftBlock($blocks, $targetX, $targetY - 1, $targetZ) === Block::AIR
					&& $this->allChorusNeighborsEmpty($blocks, $targetX, $targetY, $targetZ, ($direction + 2) % 4)){
					$placedStem = true;
					$this->draftPut($blocks, $targetX, $targetY, $targetZ, self::CHORUS_PLANT);
					$this->draftPut($blocks, $x, $targetY, $z, self::CHORUS_PLANT);
					$this->growChorus($blocks, $random, $targetX, $targetY, $targetZ, $rootX, $rootZ, $depth + 1);
				}
			}
		}
		if(!$placedStem){
			$this->draftPut($blocks, $x, $y + $height, $z, self::CHORUS_FLOWER, 5);
		}
	}

	private function applyBlocks(FullChunk $chunk, array $blocks) : void{
		foreach($blocks as [$x, $y, $z, $id, $data]){
			$this->put($chunk, $x, $y, $z, $id, $data);
		}
	}

	private function intersects(int $chunkX, int $chunkZ, int $minX, int $minZ, int $maxX, int $maxZ) : bool{
		return $maxX >= $chunkX * 16 && $minX <= $chunkX * 16 + 15
			&& $maxZ >= $chunkZ * 16 && $minZ <= $chunkZ * 16 + 15;
	}

	private function put(FullChunk $chunk, int $x, int $y, int $z, int $id, int $data = 0) : void{
		if($y < 0 || $y >= 128 || ($x >> 4) !== (int) $chunk->getX() || ($z >> 4) !== (int) $chunk->getZ()){
			return;
		}
		if($id === self::CHORUS_PLANT){
			$id = Block::STAINED_CLAY;
			$data = 10;
		}elseif($id === self::CHORUS_FLOWER){
			$id = Block::STAINED_CLAY;
			$data = 2;
		}elseif($id === self::END_GATEWAY){
			$id = Block::GLOWSTONE;
			$data = 0;
		}
		$chunk->setBlock($x & 15, $y, $z & 15, $id, $data);
	}

	private function placeSpike(FullChunk $chunk, array $spike) : void{
		$cx = $spike['centerX'];
		$cz = $spike['centerZ'];
		$radius = $spike['radius'];
		$height = $spike['height'];
		$minX = max($cx - $radius, (int) $chunk->getX() * 16);
		$maxX = min($cx + $radius, (int) $chunk->getX() * 16 + 15);
		$minZ = max($cz - $radius, (int) $chunk->getZ() * 16);
		$maxZ = min($cz + $radius, (int) $chunk->getZ() * 16 + 15);
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$inside = ($x - $cx) * ($x - $cx) + ($z - $cz) * ($z - $cz) <= $radius * $radius + 1;
				for($y = 0; $y <= $height + 10; ++$y){
					if($inside && $y < $height){
						$this->put($chunk, $x, $y, $z, Block::OBSIDIAN);
					}elseif($y > 65){
						$this->put($chunk, $x, $y, $z, Block::AIR);
					}
				}
			}
		}
		if($spike['guarded']){
			for($dx = -2; $dx <= 2; ++$dx){
				for($dz = -2; $dz <= 2; ++$dz){
					for($dy = 0; $dy <= 3; ++$dy){
						if(abs($dx) === 2 || abs($dz) === 2 || $dy === 3){
							// Legacy iron bars infer their connections from neighbouring blocks.
							$this->put($chunk, $cx + $dx, $height + $dy, $cz + $dz, Block::IRON_BARS);
						}
					}
				}
			}
		}
		$this->put($chunk, $cx, $height, $cz, Block::BEDROCK);
		$this->put($chunk, $cx, $height + 1, $cz, Block::FIRE);
	}

	private function placePodium(FullChunk $chunk) : void{
		$originY = $this->podiumY;
		for($x = -4; $x <= 4; ++$x){
			for($z = -4; $z <= 4; ++$z){
				for($dy = -1; $dy <= 32; ++$dy){
					$distanceSquared = $x * $x + $dy * $dy + $z * $z;
					if($distanceSquared >= 3.5 * 3.5){
						continue;
					}
					$insideRim = $distanceSquared < 2.5 * 2.5;
					$id = $dy < 0 ? ($insideRim ? Block::BEDROCK : Block::END_STONE)
						: ($dy === 0 && !$insideRim ? Block::BEDROCK : Block::AIR);
					$this->put($chunk, $x, $originY + $dy, $z, $id);
				}
			}
		}
		for($dy = 0; $dy < 4; ++$dy){
			$this->put($chunk, 0, $originY + $dy, 0, Block::BEDROCK);
		}
		// Torch metadata is this engine's legacy attachment direction.
		foreach([[1, 0, 1], [-1, 0, 2], [0, 1, 3], [0, -1, 4]] as [$x, $z, $data]){
			$this->put($chunk, $x, $originY + 2, $z, Block::TORCH, $data);
		}
	}

	private function placePlatform(FullChunk $chunk) : void{
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				for($dy = -1; $dy < 3; ++$dy){
					$this->put($chunk, 100 + $dx, self::PLATFORM_ORIGIN_Y + $dy, $dz, $dy === -1 ? Block::OBSIDIAN : Block::AIR);
				}
			}
		}
	}
}
