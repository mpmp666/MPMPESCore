<?php

namespace pocketmine\level\generator\ender;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\noise\Simplex;
use pocketmine\level\generator\populator\Populator;
use pocketmine\math\Vector3 as Vector3;
use pocketmine\utils\Random;

class Ender extends Generator
{

	/** @var Populator[] */
	private $populators = [];
	/** @var ChunkManager */
	private $level;
	/** @var Random */
	private $random;
	private $waterHeight = 0;
	private $emptyHeight = 64;
	private $emptyAmplitude = 1;
	private $density = 0.28;

	/** @var Populator[] */
	private $generationPopulators = [];
	/** @var Simplex */
	private $noiseBase;

	/** @var array 末地柱布局: [worldX, worldZ, 高度] */
	private $pillars = [];

	/** 岛中心 (对齐生成区) */
	private $islandCX = 135;
	private $islandCZ = 135;
	private $islandR = 80;
	private $outerR = 400; // 外岛带外半径
	private $bedrockY = 20; // 封底基岩顶

	// TODO: 外岛生成功能待重新实现（网格分格法 + 柏林噪声岛面）

	public function __construct(array $options = [])
	{
	}

	public function getName(): string
	{
		return "Ender";
	}

	public function getWaterHeight(): int
	{
		return $this->waterHeight;
	}

	public function getSettings()
	{
		return [];
	}

	public function init(ChunkManager $level, Random $random)
	{
		$this->level = $level;
		$this->random = $random;
		$this->random->setSeed($this->level->getSeed());
		$this->noiseBase = new Simplex($this->random, 4, 1 / 4, 1 / 64);
		$this->random->setSeed($this->level->getSeed());

		// 末地柱: 只保留周围 10 根环形 (去掉中心柱), 对齐岛中心
		$cx0 = $this->islandCX; $cz0 = $this->islandCZ;
		$this->pillars = [];
		$ringR = 45;
		for ($i = 0; $i < 10; ++$i) {
			$ang = $i * (2 * M_PI / 10);
			$px = $cx0 + (int) round(cos($ang) * $ringR);
			$pz = $cz0 + (int) round(sin($ang) * $ringR);
			$this->pillars[] = [$px, $pz, 26 + ($i % 3) * 6];
		}
	}

	public function generateChunk($chunkX, $chunkZ)
	{
		$this->random->setSeed(0xa6fe78dc ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());


		$chunk = $this->level->getChunk($chunkX, $chunkZ);

		// 第一遍: 算每 column 岛顶高度 (主岛 + 外岛)
		$surfaceY = [];
		for ($x = 0; $x < 16; ++$x) {
			for ($z = 0; $z < 16; ++$z) {
				$worldX = $chunkX * 16 + $x;
				$worldZ = $chunkZ * 16 + $z;
				$dxw = $worldX - $this->islandCX;
				$dzw = $worldZ - $this->islandCZ;
				$distance = sqrt($dxw * $dxw + $dzw * $dzw);

				if ($distance <= $this->islandR) {
					// 主岛: 柏林噪声起伏 (凹陷5 突出4)
					$n = $this->noiseBase->noise3D($worldX, 0, $worldZ, true);
					$offset = (int)(($n + 1) * 4.5) - 5;
					if ($offset > 4) $offset = 4;
					if ($offset < -5) $offset = -5;
					$surf = $this->bedrockY + 30 + $offset; // 基准 y=50, 起伏 45~54
					$surfaceY[$x][$z] = $surf;
				} else {
					// TODO: 外岛带 (islandR ~ outerR) 暂为虚空，待重新实现网格分格法
					$surfaceY[$x][$z] = -1;
				}
			}
		}

		// 第二遍: 算每 column 柱顶 (贴该 column 岛顶, 不悬空)
		$pillarTop = [];
		foreach ($this->pillars as $p) {
			list($px, $pz, $h) = $p;
			$pr = 2 + ($h % 2);
			for ($dx = -$pr; $dx <= $pr; ++$dx) {
				for ($dz = -$pr; $dz <= $pr; ++$dz) {
					if ($dx * $dx + $dz * $dz > $pr * $pr + 1) {
						continue;
					}
					$wx = $px + $dx;
					$wz = $pz + $dz;
					if (intdiv($wx, 16) !== $chunkX || intdiv($wz, 16) !== $chunkZ) {
						continue;
					}
					$lx = $wx - $chunkX * 16;
					$lz = $wz - $chunkZ * 16;
					if (!isset($surfaceY[$lx][$lz]) || $surfaceY[$lx][$lz] < 0) {
						continue;
					}
					$top = $surfaceY[$lx][$lz] + $h; // 柱顶 = 岛顶 + 柱高
					if (!isset($pillarTop[$lx][$lz]) || $top > $pillarTop[$lx][$lz]) {
						$pillarTop[$lx][$lz] = $top;
					}
				}
			}
		}

		if ($chunkX === 8 && $chunkZ === 8) {
		}
		// 第三遍: 写块
		for ($x = 0; $x < 16; ++$x) {
			for ($z = 0; $z < 16; ++$z) {
				$biome = Biome::getBiome(Biome::END);
				$biome->setGroundCover([
					Block::get(Block::OBSIDIAN, 0)
				]);
				$chunk->setBiomeId($x, $z, $biome->getId());

				$surf = isset($surfaceY[$x][$z]) ? $surfaceY[$x][$z] : -1;
				$inPillar = isset($pillarTop[$x][$z]);
				$pTop = $inPillar ? $pillarTop[$x][$z] : -1;

				if ($surf < 0) {
					continue; // 岛外全虚空, 不写任何块
				}
				for ($y = 0; $y < 128; ++$y) {
					if ($y < $this->bedrockY) {
						$chunk->setBlockId($x, $y, $z, Block::BEDROCK); // 封底
						continue;
					}
					if ($inPillar && $y >= $surf && $y <= $pTop) {
						$chunk->setBlockId($x, $y, $z, Block::OBSIDIAN); // 柱子: 从岛顶立起
						continue;
					}
					if ($y <= $surf) {
						$chunk->setBlockId($x, $y, $z, Block::END_STONE); // 岛体
						continue;
					}
				}
			}
		}

		foreach ($this->generationPopulators as $populator) {
			$populator->populate($this->level, $chunkX, $chunkZ, $this->random);
		}
	}

	public function populateChunk($chunkX, $chunkZ)
	{
		$this->random->setSeed(0xa6fe78dc ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());
		foreach ($this->populators as $populator) {
			$populator->populate($this->level, $chunkX, $chunkZ, $this->random);
		}

		$chunk = $this->level->getChunk($chunkX, $chunkZ);
		$biome = Biome::getBiome($chunk->getBiomeId(7, 7));
		$biome->populateChunk($this->level, $chunkX, $chunkZ, $this->random);
	}

	// TODO: getOuterIsland() — 外岛网格分格法待重新实现

	public function getSpawn()
	{
		return new Vector3(135, 70, 135);
	}

}
