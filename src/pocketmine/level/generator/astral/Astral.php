<?php

/*
 * Astral world generator (MPMPESCore)
 * 末地石浮岛 + 按种子生成的小荧石岛 (无黑曜石柱)
 */

namespace pocketmine\level\generator\astral;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\noise\Simplex;
use pocketmine\level\generator\populator\Populator;
use pocketmine\math\Vector3 as Vector3;
use pocketmine\utils\Random;

class Astral extends Generator{

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
	private $bedrockDepth = 5;

	/** @var Populator[] */
	private $generationPopulators = [];
	/** @var Simplex */
	private $noiseBase;

	/** @var int[][] 小荧石岛: [worldX, worldZ, radius] */
	private $glowIslands = [];

	public function __construct(array $options = []){
	}

	public function getName() : string{
		return "Astral";
	}

	public function getWaterHeight() : int{
		return $this->waterHeight;
	}

	public function getSettings(){
		return [];
	}

	/**
	 * 逐 chunk 确定性判定: 该 chunk 内是否有小荧石团
	 * 返回 null 或 [本地x, 本地z, y, 半径]
	 */
	private function glowForChunk($chunkX, $chunkZ){
		$seed = ($this->level->getSeed() ^ 0x9e3779b9) + $chunkX * 73856093 + $chunkZ * 19349663;
		$rng = new Random($seed);
		if($rng->nextRange(0, 3) !== 0) return null; // 约 1/4 chunk 有荧石岛 -> 少而小
		$lx = 3 + $rng->nextRange(0, 9);   // 本地 x 3~11
		$lz = 3 + $rng->nextRange(0, 9);   // 本地 z 3~11
		$gy = 36 + $rng->nextRange(0, 8);  // y 36~43
		$radius = 1 + $rng->nextRange(0, 2); // 半径 1~2 (小团)
		return [$lx, $lz, $gy, $radius];
	}

	public function init(ChunkManager $level, Random $random){
		$this->level = $level;
		$this->random = $random;
		$this->random->setSeed($this->level->getSeed());
		$this->noiseBase = new Simplex($this->random, 4, 1 / 4, 1 / 64);
		$this->random->setSeed($this->level->getSeed());
	}

	public function generateChunk($chunkX, $chunkZ){
		$this->random->setSeed(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());

		$noise = Generator::getFastNoise3D($this->noiseBase, 16, 128, 16, 4, 8, 4, $chunkX * 16, 0, $chunkZ * 16);

		$chunk = $this->level->getChunk($chunkX, $chunkZ);

		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biome = Biome::getBiome(Biome::HELL);
				$chunk->setBiomeId($x, $z, $biome->getId());
				$color = [0, 0, 0];
				$bColor = $biome->getColor();
				$color[0] += (($bColor >> 16) ** 2);
				$color[1] += ((($bColor >> 8) & 0xff) ** 2);
				$color[2] += (($bColor & 0xff) ** 2);
				$chunk->setBiomeColor($x, $z, $color[0], $color[1], $color[2]);

				// 本 chunk 是否有小荧石团 (逐 chunk 确定性)
				$glow = $this->glowForChunk($chunkX, $chunkZ);
				$glowY = -1;
				if($glow !== null){
					list($glx, $glz, $gY, $gr) = $glow;
					if(abs($x - $glx) <= $gr && abs($z - $glz) <= $gr){
						$glowY = $gY;
					}
				}

				for($y = 0; $y < 128; ++$y){
					if($y === 0){
						$chunk->setBlockId($x, $y, $z, Block::BEDROCK);
						continue;
					}
					$noiseValue = (abs($this->emptyHeight - $y) / $this->emptyHeight) * $this->emptyAmplitude - $noise[$x][$z][$y];
					$noiseValue -= 1 - $this->density;

					if($noiseValue > 0){
						// 主末地石浮岛
						$chunk->setBlockId($x, $y, $z, Block::END_STONE);
					}elseif($glowY >= 0 && $y >= $glowY && $y <= $glowY + 1){
						// 小荧石岛: 只在岛中心一小团 (2 格高)
						$chunk->setBlockId($x, $y, $z, Block::GLOWSTONE);
						$chunk->setBlockLight($x, $y, $z, 15);
					}
				}
			}
		}

		foreach($this->generationPopulators as $populator){
			$populator->populate($this->level, $chunkX, $chunkZ, $this->random);
		}
	}

	public function populateChunk($chunkX, $chunkZ){
		$this->random->setSeed(0xdeadbeef ^ ($chunkX << 8) ^ $chunkZ ^ $this->level->getSeed());
		foreach($this->populators as $populator){
			$populator->populate($this->level, $chunkX, $chunkZ, $this->random);
		}

		$chunk = $this->level->getChunk($chunkX, $chunkZ);
		$biome = Biome::getBiome($chunk->getBiomeId(7, 7));
		$biome->populateChunk($this->level, $chunkX, $chunkZ, $this->random);
	}

	public function getSpawn(){
		return new Vector3(0, 70, 0);
	}

}
