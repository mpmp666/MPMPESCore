<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender\noise;

/** Minecraft's SimplexNoise 2D path, including the exact permutation shuffle. */
final class SimplexNoise{
	public const GRADIENT = [
		[1, 1, 0], [-1, 1, 0], [1, -1, 0], [-1, -1, 0],
		[1, 0, 1], [-1, 0, 1], [1, 0, -1], [-1, 0, -1],
		[0, 1, 1], [0, -1, 1], [0, 1, -1], [0, -1, -1],
		[1, 1, 0], [0, -1, 1], [-1, 1, 0], [0, -1, -1]
	];
	private array $p;
	public readonly float $xo;
	public readonly float $yo;
	public readonly float $zo;
	private readonly float $f2;
	private readonly float $g2;

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
		$this->f2 = 0.5 * (sqrt(3.0) - 1.0);
		$this->g2 = (3.0 - sqrt(3.0)) / 6.0;
	}

	private static function corner(int $index, float $x, float $y) : float{
		$t = 0.5 - $x * $x - $y * $y;
		if($t < 0.0){
			return 0.0;
		}
		$t *= $t;
		$g = self::GRADIENT[$index];
		return $t * $t * ($g[0] * $x + $g[1] * $y + $g[2] * 0.0);
	}

	public function getValue(float $x, float $y) : float{
		$s = ($x + $y) * $this->f2;
		$i = (int) floor($x + $s);
		$j = (int) floor($y + $s);
		$t = ($i + $j) * $this->g2;
		$x0 = $x - ($i - $t);
		$y0 = $y - ($j - $t);
		$i1 = $x0 > $y0 ? 1 : 0;
		$j1 = $x0 > $y0 ? 0 : 1;
		$x1 = $x0 - $i1 + $this->g2;
		$y1 = $y0 - $j1 + $this->g2;
		$x2 = $x0 - 1.0 + 2.0 * $this->g2;
		$y2 = $y0 - 1.0 + 2.0 * $this->g2;
		$ii = $i & 255;
		$jj = $j & 255;
		$gi0 = $this->p[($ii + $this->p[$jj]) & 255] % 12;
		$gi1 = $this->p[($ii + $i1 + $this->p[($jj + $j1) & 255]) & 255] % 12;
		$gi2 = $this->p[($ii + 1 + $this->p[($jj + 1) & 255]) & 255] % 12;
		$n0 = self::corner($gi0, $x0, $y0);
		$n1 = self::corner($gi1, $x1, $y1);
		$n2 = self::corner($gi2, $x2, $y2);
		return 70.0 * ($n0 + $n1 + $n2);
	}
}
