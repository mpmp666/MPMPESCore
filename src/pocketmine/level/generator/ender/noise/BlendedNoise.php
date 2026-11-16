<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/** Minecraft 26.2 End old_blended_noise, seeded by RandomState's legacy path. */
final class BlendedNoise{
	/** @var ImprovedNoise[] Creation order equals getOctaveNoise(i) order. */
	private array $minLimitNoise = [];
	/** @var ImprovedNoise[] */
	private array $maxLimitNoise = [];
	/** @var ImprovedNoise[] */
	private array $mainNoise = [];
	private const MULTIPLIER = 684.412 * 0.25;

	public function __construct(JavaRandom $random){
		// PerlinNoise.createLegacyForBlendedNoise(-15..0), twice, then -7..0.
		// Its zero octave is constructed first; getOctaveNoise reverses storage.
		for($i = 0; $i < 16; ++$i){
			$this->minLimitNoise[] = new ImprovedNoise($random);
		}
		for($i = 0; $i < 16; ++$i){
			$this->maxLimitNoise[] = new ImprovedNoise($random);
		}
		for($i = 0; $i < 8; ++$i){
			$this->mainNoise[] = new ImprovedNoise($random);
		}
	}

	private static function wrap(float $x) : float{
		return $x - floor($x / 33554432.0 + 0.5) * 33554432.0;
	}

	public function compute(int $x, int $y, int $z) : float{
		$limitX = $x * self::MULTIPLIER;
		$limitY = $y * self::MULTIPLIER;
		$limitZ = $z * self::MULTIPLIER;
		$mainX = $limitX / 80.0;
		$mainY = $limitY / 160.0;
		$mainZ = $limitZ / 80.0;
		$limitSmear = self::MULTIPLIER * 4.0;
		$mainSmear = $limitSmear / 160.0;
		$main = 0.0;
		$pow = 1.0;
		for($i = 0; $i < 8; ++$i){
			$main += $this->mainNoise[$i]->noise(self::wrap($mainX * $pow), self::wrap($mainY * $pow), self::wrap($mainZ * $pow), $mainSmear * $pow, $mainY * $pow) / $pow;
			$pow /= 2.0;
		}
		$factor = ($main / 10.0 + 1.0) / 2.0;
		$isMax = $factor >= 1.0;
		$isMin = $factor <= 0.0;
		$min = 0.0;
		$max = 0.0;
		$pow = 1.0;
		for($i = 0; $i < 16; ++$i){
			$wx = self::wrap($limitX * $pow);
			$wy = self::wrap($limitY * $pow);
			$wz = self::wrap($limitZ * $pow);
			$smear = $limitSmear * $pow;
			if(!$isMax){
				$min += $this->minLimitNoise[$i]->noise($wx, $wy, $wz, $smear, $limitY * $pow) / $pow;
			}
			if(!$isMin){
				$max += $this->maxLimitNoise[$i]->noise($wx, $wy, $wz, $smear, $limitY * $pow) / $pow;
			}
			$pow /= 2.0;
		}
		$min /= 512.0;
		$max /= 512.0;
		return ($factor < 0.0 ? $min : ($factor > 1.0 ? $max : $min + $factor * ($max - $min))) / 128.0;
	}
}
