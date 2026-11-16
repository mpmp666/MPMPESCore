<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/** The Minecraft ImprovedNoise path used by legacy End BlendedNoise. */
final class ImprovedNoise{
	private array $p;
	public readonly float $xo;
	public readonly float $yo;
	public readonly float $zo;

	public function __construct(JavaRandom $random){
		$this->xo = $random->nextDouble() * 256.0;
		$this->yo = $random->nextDouble() * 256.0;
		$this->zo = $random->nextDouble() * 256.0;
		$this->p = range(0, 255);
		for($i = 0; $i < 256; ++$i){
			$j = $i + $random->nextInt(256 - $i);
			$tmp = $this->p[$i];
			$this->p[$i] = $this->p[$j];
			$this->p[$j] = $tmp;
		}
	}

	private static function gradDot(int $hash, float $x, float $y, float $z) : float{
		$g = SimplexNoise::GRADIENT[$hash & 15];
		return $g[0] * $x + $g[1] * $y + $g[2] * $z;
	}

	public function noise(float $x, float $y, float $z, float $yScale = 0.0, float $yFudge = 0.0) : float{
		$x += $this->xo;
		$y += $this->yo;
		$z += $this->zo;
		$xf = (int) floor($x);
		$yf = (int) floor($y);
		$zf = (int) floor($z);
		$xr = $x - $xf;
		$yrOriginal = $y - $yf;
		$zr = $z - $zf;
		$yrFudge = 0.0;
		if($yScale != 0.0){
			$fudgeLimit = $yFudge >= 0.0 && $yFudge < $yrOriginal ? $yFudge : $yrOriginal;
			// Java's 1.0E-7F literal is widened to this exact double value.
			$yrFudge = floor($fudgeLimit / $yScale + 1.0000000116860974E-7) * $yScale;
		}
		$yr = $yrOriginal - $yrFudge;
		$p = $this->p;
		$x0 = $p[$xf & 255];
		$x1 = $p[($xf + 1) & 255];
		$xy00 = $p[($x0 + $yf) & 255];
		$xy01 = $p[($x0 + $yf + 1) & 255];
		$xy10 = $p[($x1 + $yf) & 255];
		$xy11 = $p[($x1 + $yf + 1) & 255];
		$d000 = self::gradDot($p[($xy00 + $zf) & 255], $xr, $yr, $zr);
		$d100 = self::gradDot($p[($xy10 + $zf) & 255], $xr - 1.0, $yr, $zr);
		$d010 = self::gradDot($p[($xy01 + $zf) & 255], $xr, $yr - 1.0, $zr);
		$d110 = self::gradDot($p[($xy11 + $zf) & 255], $xr - 1.0, $yr - 1.0, $zr);
		$d001 = self::gradDot($p[($xy00 + $zf + 1) & 255], $xr, $yr, $zr - 1.0);
		$d101 = self::gradDot($p[($xy10 + $zf + 1) & 255], $xr - 1.0, $yr, $zr - 1.0);
		$d011 = self::gradDot($p[($xy01 + $zf + 1) & 255], $xr, $yr - 1.0, $zr - 1.0);
		$d111 = self::gradDot($p[($xy11 + $zf + 1) & 255], $xr - 1.0, $yr - 1.0, $zr - 1.0);
		$xa = $xr * $xr * $xr * ($xr * ($xr * 6.0 - 15.0) + 10.0);
		$ya = $yrOriginal * $yrOriginal * $yrOriginal * ($yrOriginal * ($yrOriginal * 6.0 - 15.0) + 10.0);
		$za = $zr * $zr * $zr * ($zr * ($zr * 6.0 - 15.0) + 10.0);
		// Preserve Mth.lerp3's x, then y, then z arithmetic order.
		$x00 = $d000 + $xa * ($d100 - $d000);
		$x10 = $d010 + $xa * ($d110 - $d010);
		$x01 = $d001 + $xa * ($d101 - $d001);
		$x11 = $d011 + $xa * ($d111 - $d011);
		$z0 = $x00 + $ya * ($x10 - $x00);
		$z1 = $x01 + $ya * ($x11 - $x01);
		return $z0 + $za * ($z1 - $z0);
	}
}
