<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\ender\noise\EndTerrainNoise;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;

/**
 * Minecraft Java 26.2 End base terrain and structure integration.
 * The density evaluator and interpolation use the supplied MCP source. Visible
 * biome IDs collapse to the old client's END ID; internal biome selection does not.
 * No artificial bedrock floor or shifted/grid-island terrain is added.
 */
class Ender extends Generator{
	private ChunkManager $level;
	private EndTerrainNoise $terrain;
	private ?EndFeatures $features = null;

	public function __construct(array $options = []){
		$this->structureOptions = $options;
	}

	public function getName() : string{ return 'Ender'; }
	public function getWaterHeight() : int{ return 0; }
	public function getSettings(){ return $this->structureOptions; }

	public function init(ChunkManager $level, Random $random){
		$this->level = $level;
		$this->terrain = new EndTerrainNoise((int)$level->getSeed());
	}

	public function terrainNoise() : EndTerrainNoise{ return $this->terrain; }

	/** First air above raw base terrain, exactly the source WORLD_SURFACE_WG query. */
	public function surfaceHeight(int $x, int $z) : int{ return $this->terrain->surfaceHeight($x, $z); }
	public function noiseBiome(int $x, int $z) : string{ return $this->terrain->biome($x, $z); }

	public function generateChunk($chunkX, $chunkZ){
		$chunkX=(int)$chunkX; $chunkZ=(int)$chunkZ;
		$chunk=$this->level->getChunk($chunkX,$chunkZ);
		if($chunk===null){throw new \RuntimeException('End generation requires a registered chunk');}
		$grid=$this->terrain->chunkGrid($chunkX,$chunkZ);
		// Generation is called only for ungenerated chunks. Explicitly clear cells to
		// keep repeat/reference runs deterministic even when given a recycled object.
		for($x=0;$x<16;++$x){
			for($z=0;$z<16;++$z){
				$chunk->setBiomeId($x,$z,Biome::END);
				$chunk->setBiomeColor($x,$z,26,11,46);
				for($y=0;$y<128;++$y){$chunk->setBlock($x,$y,$z,0,0);}
			}
		}
		$grid->forEachSolid(static function(int $x,int $y,int $z) use($chunk):void{
			$chunk->setBlock($x,$y,$z,Block::END_STONE,0);
		});
	}

	public function populateChunk($chunkX, $chunkZ){
		// End features run after structure pieces in populateStructures below.
		// Never call the legacy END biome's grass/flower populators here.
	}

	public function populateStructures(ChunkManager $level,int $x,int $z) : array{
		$metadata=parent::populateStructures($level,$x,$z);
		$metadata=['tiles'=>$metadata['tiles']??[],'entities'=>$metadata['entities']??[]];
		$chunk=$level->getChunk($x,$z);
		if($chunk!==null){
			$this->features ??= new EndFeatures((int)$level->getSeed(),$this->terrain);
			$this->features->populate($chunk);
		}
		return $metadata;
	}

	public function getSpawn(){ return new Vector3(100.5,49,0.5); }
}
