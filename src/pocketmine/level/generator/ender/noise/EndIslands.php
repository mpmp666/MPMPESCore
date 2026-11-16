<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/** DensityFunctions.EndIslandDensityFunction, including Java float/int semantics. */
final class EndIslands{
	private SimplexNoise $noise;
	/** @var array<string, float|null> Per-candidate island size; null means rejected. */
	private array $candidates = [];
	/** @var array<string, array> Accepted candidates for each 25x25 neighborhood. */
	private array $neighborhoods = [];
	/** @var array<string, float> Exact height values indexed by section coordinates. */
	private array $heights = [];

	public function __construct(int $seed){
		$random = new JavaRandom($seed);
		$random->consumeCount(17292);
		$this->noise = new SimplexNoise($random);
	}

	/** Round to IEEE754 binary32 after a Java float operation. */
	private static function f32(float $value) : float{
		return unpack('g', pack('g', $value))[1];
	}

	private static function clampHeight(float $value) : float{
		// Java Math.min/Math.max propagate NaN, which matters in far End rings.
		return is_nan($value) ? NAN : ($value < -100.0 ? -100.0 : min($value, 80.0));
	}

	/** @return array<array{int, int, float}> */
	private function neighborhood(int $chunkX, int $chunkZ) : array{
		$key = $chunkX . ':' . $chunkZ;
		if(isset($this->neighborhoods[$key])){
			return $this->neighborhoods[$key];
		}
		if(count($this->neighborhoods) >= 512){
			$this->neighborhoods = [];
		}
		$accepted = [];
		for($xo = -12; $xo <= 12; ++$xo){
			for($zo = -12; $zo <= 12; ++$zo){
				$x = $chunkX + $xo;
				$z = $chunkZ + $zo;
				if($x * $x + $z * $z <= 4096){
					continue;
				}
				$candidateKey = $x . ':' . $z;
				if(!array_key_exists($candidateKey, $this->candidates)){
					if(count($this->candidates) >= 32768){
						$this->candidates = [];
					}
					$size = null;
					if($this->noise->getValue((float) $x, (float) $z) < -0.8999999761581421){
						$ax = self::f32(abs(self::f32((float) $x)) * 3439.0);
						$az = self::f32(abs(self::f32((float) $z)) * 147.0);
						$size = self::f32(self::f32(fmod(self::f32($ax + $az), 13.0)) + 9.0);
					}
					$this->candidates[$candidateKey] = $size;
				}
				$size = $this->candidates[$candidateKey];
				if($size !== null){
					$accepted[] = [$xo, $zo, $size];
				}
			}
		}
		return $this->neighborhoods[$key] = $accepted;
	}

	/** Source getHeightValue; section coordinates are block coordinates / 8. */
	public function heightValue(int $sectionX, int $sectionZ) : float{
		$key = $sectionX . ':' . $sectionZ;
		if(array_key_exists($key, $this->heights)){
			return $this->heights[$key];
		}
		if(count($this->heights) >= 8192){
			$this->heights = [];
		}
		$chunkX = intdiv($sectionX, 2);
		$chunkZ = intdiv($sectionZ, 2);
		$subX = $sectionX % 2;
		$subZ = $sectionZ % 2;
		// The source squares/adds as int32 before conversion to float, not as long.
		$distanceSquared = ($sectionX * $sectionX + $sectionZ * $sectionZ) & 0xffffffff;
		if($distanceSquared >= 0x80000000){
			$distanceSquared -= 0x100000000;
		}
		$radius = self::f32(sqrt(self::f32((float) $distanceSquared)));
		$height = self::clampHeight(self::f32(100.0 - self::f32($radius * 8.0)));
		foreach($this->neighborhood($chunkX, $chunkZ) as [$xo, $zo, $size]){
			$xd = (float) ($subX - $xo * 2);
			$zd = (float) ($subZ - $zo * 2);
			$distance = self::f32(sqrt(self::f32(self::f32($xd * $xd) + self::f32($zd * $zd))));
			$newHeight = self::clampHeight(self::f32(100.0 - self::f32($distance * $size)));
			$height = is_nan($height) ? NAN : max($height, $newHeight);
		}
		return $this->heights[$key] = $height;
	}

	/** Raw -100..80 island height at world block coordinates (occasionally NaN far out). */
	public function height(int $blockX, int $blockZ) : float{
		return $this->heightValue(intdiv($blockX, 8), intdiv($blockZ, 8));
	}

	public function compute(int $blockX, int $blockZ) : float{
		return ($this->height($blockX, $blockZ) - 8.0) / 128.0;
	}
}
