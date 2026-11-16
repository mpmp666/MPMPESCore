<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/**
 * Vanilla Minecraft Java 26.2 End base terrain, for new, unblended chunks.
 *
 * density() is the raw input to the interpolated marker, including the 0.64 scale.
 * Interpolate those values on 8x4x8 cells, THEN apply squeeze (or just test > 0).
 * Do not sample density() separately at every block: that changes island geometry.
 * No features, structures, spawn platform, bedrock, or old-chunk blending are added.
 */
final class EndTerrainNoise{
	public const MIN_Y = 0;
	public const HEIGHT = 128;
	public const CELL_WIDTH = 8;
	public const CELL_HEIGHT = 4;
	private BlendedNoise $baseNoise;
	private EndIslands $islands;
	/** @var array<string, float> Bounded lattice cache shared by adjacent chunks. */
	private array $lattice = [];
	/** @var array<string, EndChunkGrid> */
	private array $grids = [];

	public function __construct(int $seed){
		$this->baseNoise = new BlendedNoise(new JavaRandom($seed));
		$this->islands = new EndIslands($seed);
	}

	/** End island height before the (height-8)/128 density conversion. */
	public function islandHeight(int $x, int $z) : float{
		return $this->islands->height($x, $z);
	}

	public function islandDensity(int $x, int $z) : float{
		return $this->islands->compute($x, $z);
	}

	/** Exposed for reference tests; no slides, island offset, or interpolation. */
	public function blendedNoise(int $x, int $y, int $z) : float{
		return $this->baseNoise->compute($x, $y, $z);
	}

	/** Raw final-density lattice input BEFORE interpolation and squeeze. */
	public function density(int $x, int $y, int $z) : float{
		$key = $x . ':' . $y . ':' . $z;
		if(array_key_exists($key, $this->lattice)){
			return $this->lattice[$key];
		}
		if(count($this->lattice) >= 4096){
			$this->lattice = [];
		}
		$bottomAlpha = ($y - 4.0) / 28.0;
		$bottom = $bottomAlpha < 0.0 ? 0.0 : ($bottomAlpha > 1.0 ? 1.0 : 0.0 + $bottomAlpha * (1.0 - 0.0));
		if($bottom == 0.0){
			return $this->lattice[$key] = 0.64 * -0.234375;
		}
		$topAlpha = ($y - 56.0) / 256.0;
		$top = $topAlpha < 0.0 ? 1.0 : ($topAlpha > 1.0 ? 0.0 : 1.0 + $topAlpha * (0.0 - 1.0));
		if($top == 0.0){
			$topSlide = -23.4375;
		}else{
			$slopedCheese = $this->islandDensity($x, $z) + $this->baseNoise->compute($x, $y, $z);
			$topSlide = -23.4375 + $top * (23.4375 + $slopedCheese);
		}
		return $this->lattice[$key] = 0.64 * (-0.234375 + $bottom * (0.234375 + $topSlide));
	}

	public static function squeeze(float $value) : float{
		$c = is_nan($value) ? NAN : max(-1.0, min(1.0, $value));
		return $c / 2.0 - $c * $c * $c / 24.0;
	}

	/**
	 * Full chunk sampler: 3x33x3 lattice nodes and inexpensive block interpolation.
	 * Retains at most eight complete grids; callers may retain returned grid objects.
	 */
	public function chunkGrid(int $chunkX, int $chunkZ) : EndChunkGrid{
		$key = $chunkX . ':' . $chunkZ;
		if(isset($this->grids[$key])){
			return $this->grids[$key];
		}
		if(count($this->grids) >= 8){
			unset($this->grids[array_key_first($this->grids)]);
		}
		$nodes = [];
		for($gx = 0; $gx <= 2; ++$gx){
			$x = $chunkX * 16 + $gx * 8;
			for($gz = 0; $gz <= 2; ++$gz){
				$z = $chunkZ * 16 + $gz * 8;
				for($gy = 0; $gy <= 32; ++$gy){
					$nodes[] = $this->density($x, $gy * 4, $z);
				}
			}
		}
		return $this->grids[$key] = new EndChunkGrid($chunkX, $chunkZ, $nodes);
	}

	/** Final density at a world block, with Java NoiseChunk interpolation order. */
	public function interpolatedDensity(int $x, int $y, int $z) : float{
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		return $this->chunkGrid($chunkX, $chunkZ)->density($x & 15, $y, $z & 15);
	}

	/** WORLD_SURFACE_WG: first air above the highest solid block; zero for void. */
	public function surfaceHeight(int $x, int $z) : int{
		return $this->chunkGrid($x >> 4, $z >> 4)->surfaceHeight($x & 15, $z & 15);
	}

	/** TheEndBiomeSource's unzoomed noise biome, at world block coordinates. */
	public function biome(int $x, int $z) : string{
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		if($chunkX * $chunkX + $chunkZ * $chunkZ <= 4096){
			return 'the_end';
		}
		$height = $this->islandDensity(($chunkX * 2 + 1) * 8, ($chunkZ * 2 + 1) * 8);
		if($height > 0.25){
			return 'end_highlands';
		}
		if($height >= -0.0625){
			return 'end_midlands';
		}
		return $height < -0.21875 ? 'small_end_islands' : 'end_barrens';
	}
}
