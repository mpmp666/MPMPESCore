<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/**
 * Java 26.2 WorldgenRandom(new XoroshiroRandomSource(seed)).
 *
 * These public draws use WorldgenRandom/BitRandomSource's high-bit interface.
 * In particular nextLong() consumes TWO xoroshiro128++ outputs, unlike calling
 * XoroshiroRandomSource.nextLong() directly. nextRawLong() exposes the latter.
 * All long arithmetic wraps at 64 bits without PHP floating-point conversion.
 */
final class XoroshiroRandom{
	private int $lo;
	private int $hi;

	public function __construct(int $seed){
		$this->setSeed($seed);
	}

	public static function unsignedRightShift(int $value, int $bits) : int{
		if($bits === 0){
			return $value;
		}
		return ($value >> $bits) & (PHP_INT_MAX >> ($bits - 1));
	}

	private static function rotateLeft(int $value, int $bits) : int{
		return ($value << $bits) | self::unsignedRightShift($value, 64 - $bits);
	}

	private static function mixStafford13(int $value) : int{
		$value = JavaRandom::multiplyLong($value ^ self::unsignedRightShift($value, 30), -4658895280553007687);
		$value = JavaRandom::multiplyLong($value ^ self::unsignedRightShift($value, 27), -7723592293110705685);
		return $value ^ self::unsignedRightShift($value, 31);
	}

	public function setSeed(int $seed) : void{
		$lo = $seed ^ 7640891576956012809;
		$hi = JavaRandom::addLong($lo, -7046029254386353131);
		$this->lo = self::mixStafford13($lo);
		$this->hi = self::mixStafford13($hi);
		if(($this->lo | $this->hi) === 0){
			$this->lo = -7046029254386353131;
			$this->hi = 7640891576956012809;
		}
	}

	/** Xoroshiro128PlusPlus.nextLong(), before the WorldgenRandom adapter. */
	public function nextRawLong() : int{
		$s0 = $this->lo;
		$s1 = $this->hi;
		$result = JavaRandom::addLong(self::rotateLeft(JavaRandom::addLong($s0, $s1), 17), $s0);
		$s1 ^= $s0;
		$this->lo = self::rotateLeft($s0, 49) ^ $s1 ^ ($s1 << 21);
		$this->hi = self::rotateLeft($s1, 28);
		return $result;
	}

	/** WorldgenRandom.next(bits), including Java's signed next(32). */
	public function next(int $bits) : int{
		if($bits < 1 || $bits > 32){
			throw new \InvalidArgumentException('bits must be between 1 and 32');
		}
		$value = self::unsignedRightShift($this->nextRawLong(), 64 - $bits);
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
		}while($sample - $modulo + ($bound - 1) >= 0x80000000);
		return $modulo;
	}

	public function nextLong() : int{
		$upper = $this->next(32);
		$lower = $this->next(32);
		if($lower < 0){
			$upper = ($upper - 1) & 0xffffffff;
		}
		return ($upper << 32) | ($lower & 0xffffffff);
	}

	public function nextBoolean() : bool{
		return $this->next(1) !== 0;
	}

	public function nextFloat() : float{
		return $this->next(24) * 5.960464477539063E-8;
	}

	public function nextDouble() : float{
		// Vineflower misprints this as a float literal; the existing terrain
		// oracle verifies the shipped BitRandomSource bytecode's l2d/dmul.
		return (($this->next(26) << 27) + $this->next(27)) * 1.1102230246251565E-16;
	}

	/** ChunkGenerator.applyBiomeDecoration passes block, not chunk, coordinates. */
	public function setDecorationSeed(int $seed, int $blockX, int $blockZ) : int{
		$this->setSeed($seed);
		$xScale = $this->nextLong() | 1;
		$zScale = $this->nextLong() | 1;
		$result = JavaRandom::addLong(JavaRandom::multiplyLong($blockX, $xScale), JavaRandom::multiplyLong($blockZ, $zScale)) ^ $seed;
		$this->setSeed($result);
		return $result;
	}

	public function setFeatureSeed(int $decorationSeed, int $index, int $step) : void{
		$this->setSeed(JavaRandom::addLong($decorationSeed, $index + 10000 * $step));
	}
}
