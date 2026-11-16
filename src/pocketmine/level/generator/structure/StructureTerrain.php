<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\SimpleChunkManager;
use pocketmine\level\format\mcregion\Chunk;
use pocketmine\level\generator\Generator;
use pocketmine\utils\Random;

/** Samples untouched terrain in a private manager, so discovery and generation order agree. */
final class StructureTerrain{
	private SimpleChunkManager $manager;
	private Generator $generator;
	private array $samples = [];
	/** A small immutable terrain cache also supports Nether floor/headroom queries. */
	private array $chunks = [];

	public function __construct(string $generator, int $seed){
		$this->manager = new SimpleChunkManager($seed);
		$this->generator = new $generator(['structures' => false]);
		$this->generator->init($this->manager, new Random($seed));
	}

	private function terrainChunk(int $cx, int $cz) : Chunk{
		$key=$cx.':'.$cz;
		if(!isset($this->chunks[$key])){
			$this->manager->setChunk($cx,$cz,Chunk::getEmptyChunk($cx,$cz));
			$this->generator->generateChunk($cx,$cz);
			if(count($this->chunks)>=12){array_shift($this->chunks);}
			$this->chunks[$key]=$this->manager->getChunk($cx,$cz);
			$this->manager->cleanChunks();
		}
		return $this->chunks[$key];
	}

	/** A real lava-free floor, not the Nether's solid roof at y126. */
	public function netherFloor(int $x,int $z,int $maxY=80) : ?int{
		$chunk=$this->terrainChunk($x>>4,$z>>4); $lx=$x&15; $lz=$z&15;
		$best=null; $distance=PHP_INT_MAX;
		for($y=34;$y<=min(80,$maxY);++$y){
			$id=$chunk->getBlockId($lx,$y,$lz);
			if(in_array($id,[0,7,8,9,10,11,51],true)){continue;}
			if($chunk->getBlockId($lx,$y+1,$lz)!==0||$chunk->getBlockId($lx,$y+2,$lz)!==0||$chunk->getBlockId($lx,$y+3,$lz)!==0){continue;}
			$d=abs($y-48);
			if($d<$distance){$best=$y;$distance=$d;}
		}
		return $best;
	}

	/** [highest solid terrain y, biome id, highest water y] */
	public function sample(int $x, int $z) : array{
		$key = ($x >> 4) . ':' . ($z >> 4);
		if(!isset($this->samples[$key])){
			$cx = $x >> 4; $cz = $z >> 4;
			$chunk = $this->terrainChunk($cx,$cz);
			$columns = [];
			for($lx = 0; $lx < 16; ++$lx){
				for($lz = 0; $lz < 16; ++$lz){
					$water = -1; $ground = -1;
					for($y = 126; $y > 0; --$y){
						$id = $chunk->getBlockId($lx, $y, $lz);
						if($id === 8 || $id === 9){ $water = max($water, $y); continue; }
						if(!in_array($id, [0,18,31,32,37,38,78,106,161,175], true)){ $ground = $y; break; }
					}
					$columns[($lx << 4) | $lz] = [$ground, $chunk->getBiomeId($lx, $lz), $water];
				}
			}
			if(count($this->samples) >= 64){ array_shift($this->samples); }
			$this->samples[$key] = $columns;
			$this->manager->cleanChunks();
		}
		return $this->samples[$key][(($x & 15) << 4) | ($z & 15)];
	}
}
