<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/**
 * Minecraft LegacyRandomSource / java.util.Random, using a 48-bit state.
 * The split multiplication keeps PHP from promoting overflowing integers to doubles.
 * This class intentionally does not use PocketMine's unrelated random generator.
 */
final class JavaRandom{
	private const MASK = 0xffffffffffff;
	private const MULTIPLIER = 0x5deece66d;
	private int $state;

	public function __construct(int $seed){
		$this->setSeed($seed);
	}

	public function setSeed(int $seed) : void{
		$this->state = ($seed ^ self::MULTIPLIER) & self::MASK;
	}

	/** Java's signed int result, including next(32). */
	public function next(int $bits) : int{
		if($bits < 1 || $bits > 32){
			throw new \InvalidArgumentException('bits must be between 1 and 32');
		}
		// a = a0 + 2^24*a1, multiplier = m0 + 2^24*m1 (mod 2^48).
		$lo = $this->state & 0xffffff;
		$hi = $this->state >> 24;
		$product = $lo * 0xece66d + 11;
		$upper = (($product >> 24) + $hi * 0xece66d + $lo * 0x5de) & 0xffffff;
		$this->state = ($upper << 24) | ($product & 0xffffff);
		$value = $this->state >> (48 - $bits);
		return $bits === 32 && $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	public function nextInt(?int $bound = null) : int{
		if($bound === null){
			return $this->next(32);
		}
		if($bound <= 0 || $bound > 0x7fffffff){
			throw new \InvalidArgumentException('bound must be a positive Java int');
		}
		if(($bound & ($bound - 1)) === 0){
			return ($bound * $this->next(31)) >> 31;
		}
		do{
			$sample = $this->next(31);
			$modulo = $sample % $bound;
			// Java evaluates this addition as a signed, wrapping 32-bit int.
		}while($sample - $modulo + ($bound - 1) >= 0x80000000);
		return $modulo;
	}

	public function nextLong() : int{
		$upper = $this->next(32);
		$lower = $this->next(32);
		// Add the sign-extended lower half using bit operations to avoid int64 overflow.
		if($lower < 0){
			$upper = ($upper - 1) & 0xffffffff;
		}
		return ($upper << 32) | ($lower & 0xffffffff);
	}

	/** Java long addition modulo 2^64, without conversion to a PHP double. */
	public static function addLong(int $a, int $b) : int{
		$lo = ($a & 0xffffffff) + ($b & 0xffffffff);
		$hi = ((($a >> 32) & 0xffffffff) + (($b >> 32) & 0xffffffff) + ($lo >> 32)) & 0xffffffff;
		return ($hi << 32) | ($lo & 0xffffffff);
	}

	/** Java long multiplication modulo 2^64. */
	public static function multiplyLong(int $a, int $b) : int{
		$aa = [$a & 0xffff, ($a >> 16) & 0xffff, ($a >> 32) & 0xffff, ($a >> 48) & 0xffff];
		$bb = [$b & 0xffff, ($b >> 16) & 0xffff, ($b >> 32) & 0xffff, ($b >> 48) & 0xffff];
		$result = [];
		$carry = 0;
		for($k = 0; $k < 4; ++$k){
			$sum = $carry;
			for($i = 0; $i <= $k; ++$i){
				$sum += $aa[$i] * $bb[$k - $i];
			}
			$result[$k] = $sum & 0xffff;
			$carry = $sum >> 16;
		}
		return ($result[3] << 48) | ($result[2] << 32) | ($result[1] << 16) | $result[0];
	}

	/** WorldgenRandom.setLargeFeatureSeed, used for structure starts. */
	public function setLargeFeatureSeed(int $seed, int $chunkX, int $chunkZ) : void{
		$this->setSeed($seed);
		$xScale = $this->nextLong();
		$zScale = $this->nextLong();
		$this->setSeed(self::multiplyLong($chunkX, $xScale) ^ self::multiplyLong($chunkZ, $zScale) ^ $seed);
	}

	/** WorldgenRandom.setLargeFeatureWithSalt, used for random spread placement. */
	public function setLargeFeatureWithSalt(int $seed, int $x, int $z, int $salt) : void{
		$result = self::addLong(self::multiplyLong($x, 341873128712), self::multiplyLong($z, 132897987541));
		$this->setSeed(self::addLong(self::addLong($result, $seed), $salt));
	}

	/** WorldgenRandom.setDecorationSeed; coordinates are block coordinates. */
	public function setDecorationSeed(int $seed, int $blockX, int $blockZ) : int{
		$this->setSeed($seed);
		$xScale = $this->nextLong() | 1;
		$zScale = $this->nextLong() | 1;
		$result = self::addLong(self::multiplyLong($blockX, $xScale), self::multiplyLong($blockZ, $zScale)) ^ $seed;
		$this->setSeed($result);
		return $result;
	}

	public function setFeatureSeed(int $decorationSeed, int $index, int $step) : void{
		$scaledStep = (10000 * $step) & 0xffffffff;
		if($scaledStep >= 0x80000000){
			$scaledStep -= 0x100000000;
		}
		$this->setSeed(self::addLong(self::addLong($decorationSeed, $index), $scaledStep));
	}

	public function nextBoolean() : bool{
		return $this->next(1) !== 0;
	}

	public function nextFloat() : float{
		return $this->next(24) * 5.960464477539063E-8;
	}

	public function nextDouble() : float{
		$upper = $this->next(26);
		$lower = $this->next(27);
		return (($upper << 27) + $lower) * 1.1102230246251565E-16;
	}

	public function consumeCount(int $count) : void{
		if($count < 0){
			throw new \InvalidArgumentException('count must not be negative');
		}
		for($i = 0; $i < $count; ++$i){
			$this->next(32);
		}
	}
}
