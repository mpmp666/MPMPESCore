<?php

namespace pocketmine\level\generator\ender\populator;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\populator\Populator;
use pocketmine\utils\Random;

class EnderPilar extends Populator
{
	/** @var ChunkManager */
	private $level;
	private $randomAmount;
	private $baseAmount;

	public function setRandomAmount($amount)
	{
		$this->randomAmount = $amount;
	}

	public function setBaseAmount($amount)
	{
		$this->baseAmount = $amount;
	}

	public function populate(ChunkManager $level, $chunkX, $chunkZ, Random $random)
	{
		$this->level = $level;
		// 末地柱布局: 中心 1 根高柱 + 周围 10 根环形柱子 (确定性, 不靠随机)
		$pillars = [[0, 0, 56]];
		$ringR = 40;
		for ($i = 0; $i < 10; ++$i) {
			$ang = $i * (2 * M_PI / 10);
			$px = (int) round(cos($ang) * $ringR);
			$pz = (int) round(sin($ang) * $ringR);
			$pillars[] = [$px, $pz, 28 + ($i % 3) * 8];
		}
		foreach ($pillars as $p) {
			list($px, $pz, $h) = $p;
			$cx = intdiv($px, 16);
			$cz = intdiv($pz, 16);
			if ($cx !== $chunkX || $cz !== $chunkZ) {
				continue;
			}
			$y = 4;
			for ($ny = $y; $ny < $y + $h; ++$ny) {
				$level->setBlockIdAt($px, $ny, $pz, Block::OBSIDIAN);
			}
			$level->setBlockIdAt($px, $y + $h, $pz, Block::OBSIDIAN);
		}
	}
}
