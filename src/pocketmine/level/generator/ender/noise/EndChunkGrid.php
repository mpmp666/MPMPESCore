<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/** A sampled End chunk; all x/z arguments below are LOCAL coordinates 0..15. */
final class EndChunkGrid{
	/** @var float[] Nodes in (gridX*3+gridZ)*33+gridY order. */
	private array $nodes;
	private array $surfaces = [];

	public function __construct(public readonly int $chunkX, public readonly int $chunkZ, array $nodes){
		if(count($nodes) !== 297){
			throw new \InvalidArgumentException('End grid needs 297 lattice nodes');
		}
		$this->nodes = $nodes;
	}

	/** @return float[] Useful for reference fixtures; gx/gz=0..2, gy=0..32. */
	public function latticeValues() : array{
		return $this->nodes;
	}

	/** Raw interpolated value; its >0 test is identical to final squeezed density. */
	public function rawDensity(int $x, int $y, int $z) : float{
		if($x < 0 || $x > 15 || $z < 0 || $z > 15 || $y < 0 || $y >= 128){
			throw new \OutOfRangeException('End block coordinates require x/z=0..15,y=0..127');
		}
		$gx = $x >> 3;
		$gz = $z >> 3;
		$gy = $y >> 2;
		$fy = ($y & 3) / 4.0;
		$fx = ($x & 7) / 8.0;
		$fz = ($z & 7) / 8.0;
		$n = $this->nodes;
		$i00 = ($gx * 3 + $gz) * 33 + $gy;
		$i10 = $i00 + 99;
		$i01 = $i00 + 33;
		$i11 = $i10 + 33;
		// NoiseInterpolator.updateForY, updateForX, updateForZ (in that order).
		$xz00 = $n[$i00] + $fy * ($n[$i00 + 1] - $n[$i00]);
		$xz10 = $n[$i10] + $fy * ($n[$i10 + 1] - $n[$i10]);
		$xz01 = $n[$i01] + $fy * ($n[$i01 + 1] - $n[$i01]);
		$xz11 = $n[$i11] + $fy * ($n[$i11 + 1] - $n[$i11]);
		$z0 = $xz00 + $fx * ($xz10 - $xz00);
		$z1 = $xz01 + $fx * ($xz11 - $xz01);
		return $z0 + $fz * ($z1 - $z0);
	}

	public function density(int $x, int $y, int $z) : float{
		return EndTerrainNoise::squeeze($this->rawDensity($x, $y, $z));
	}

	public function solid(int $x, int $y, int $z) : bool{
		return $this->rawDensity($x, $y, $z) > 0.0;
	}

	/** @return float[] Final densities indexed by y=0..127. */
	public function column(int $x, int $z) : array{
		$column = [];
		for($y = 0; $y < 128; ++$y){
			$column[] = $this->density($x, $y, $z);
		}
		return $column;
	}

	/** WORLD_SURFACE_WG / first air above the highest base terrain solid. */
	public function surfaceHeight(int $x, int $z) : int{
		$key = $x * 16 + $z;
		if(array_key_exists($key, $this->surfaces)){
			return $this->surfaces[$key];
		}
		for($y = 127; $y >= 0; --$y){
			if($this->solid($x, $y, $z)){
				return $this->surfaces[$key] = $y + 1;
			}
		}
		return $this->surfaces[$key] = 0;
	}

	/** @return array<int, array<int, int>> First-air surfaces indexed [localX][localZ]. */
	public function surfaces() : array{
		$result = [];
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$result[$x][$z] = $this->surfaceHeight($x, $z);
			}
		}
		return $result;
	}

	/**
	 * Emit every solid base terrain block, reusing Java's interpolation work per cell.
	 * Callback signature: function(int localX, int y, int localZ): void.
	 */
	public function forEachSolid(callable $consumer) : void{
		$n = $this->nodes;
		for($gx = 0; $gx < 2; ++$gx){
			for($gz = 0; $gz < 2; ++$gz){
				for($gy = 31; $gy >= 0; --$gy){
					$i00 = ($gx * 3 + $gz) * 33 + $gy;
					$i10 = $i00 + 99;
					$i01 = $i00 + 33;
					$i11 = $i10 + 33;
					for($dy = 3; $dy >= 0; --$dy){
						$fy = $dy / 4.0;
						$y = $gy * 4 + $dy;
						$xz00 = $n[$i00] + $fy * ($n[$i00 + 1] - $n[$i00]);
						$xz10 = $n[$i10] + $fy * ($n[$i10 + 1] - $n[$i10]);
						$xz01 = $n[$i01] + $fy * ($n[$i01 + 1] - $n[$i01]);
						$xz11 = $n[$i11] + $fy * ($n[$i11 + 1] - $n[$i11]);
						for($dx = 0; $dx < 8; ++$dx){
							$fx = $dx / 8.0;
							$x = $gx * 8 + $dx;
							$z0 = $xz00 + $fx * ($xz10 - $xz00);
							$z1 = $xz01 + $fx * ($xz11 - $xz01);
							for($dz = 0; $dz < 8; ++$dz){
								if($z0 + ($dz / 8.0) * ($z1 - $z0) > 0.0){
									$consumer($x, $y, $gz * 8 + $dz);
								}
							}
						}
					}
				}
			}
		}
	}
}
