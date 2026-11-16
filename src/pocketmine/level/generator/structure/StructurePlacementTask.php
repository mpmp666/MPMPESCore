<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\level\Level;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

/** Generates missing terrain asynchronously, then edits one ready chunk per tick. */
final class StructurePlacementTask extends Task{
	private int $levelId;
	private array $pieces;
	private int $x;
	private int $y;
	private int $z;
	private array $chunks;
	private int $cursor=0;
	private int $blocks=0;
	private int $tileCount=0;
	private int $entityCount=0;
	private string $senderName;

	public function __construct(int $levelId,array $pieces,int $x,int $y,int $z,array $chunks,string $senderName){
		$this->levelId=$levelId;$this->pieces=$pieces;$this->x=$x;$this->y=$y;$this->z=$z;$this->chunks=array_values($chunks);$this->senderName=$senderName;
	}

	public function onRun($currentTick){
		$server=Server::getInstance();
		$level=$server->getLevel($this->levelId);
		if(!$level instanceof Level){$server->getScheduler()->cancelTask($this->getTaskId());return;}
		if($this->cursor>=count($this->chunks)){
			$message='建筑放置完成：'.count($this->pieces).' 个片段、'.$this->blocks.' 个方块、'.$this->tileCount.' 个方块实体、'.$this->entityCount.' 个旧版生物。';
			$player=$this->senderName!==''?$server->getPlayerExact($this->senderName):null;
			if($player!==null){$player->sendMessage($message);}else{$server->getLogger()->info($message);}
			$server->getScheduler()->cancelTask($this->getTaskId());
			return;
		}
		[$cx,$cz]=$this->chunks[$this->cursor];
		$chunk=$level->getChunk($cx,$cz,true);
		if(!$chunk->isGenerated()){$level->generateChunk($cx,$cz,true);return;}
		if(!$chunk->isPopulated()){$level->populateChunk($cx,$cz,true);return;}
		$oldTiles=[];
		foreach($chunk->getTiles() as $tile){
			$oldTiles[]=[$tile,$chunk->getBlockId($tile->x&15,$tile->y,$tile->z&15)];
		}
		$result=StructurePlacer::chunk($chunk,$this->pieces,$this->x,$this->y,$this->z,false);
		$this->blocks+=$result['blocks'];
		foreach($oldTiles as [$tile,$oldId]){
			if($chunk->getBlockId($tile->x&15,$tile->y,$tile->z&15)!==$oldId){$tile->close();}
		}
		// Replacing blocks must replace their old container tiles as well.
		foreach($result['tiles'] as $tile){
			$old=$level->getTile(new \pocketmine\math\Vector3(...$tile['pos']));
			if($old!==null){$old->close();}
		}
		$chunk->recalculateHeightMap();$chunk->populateSkyLight();
		$level->setChunk($cx,$cz,$chunk,false);
		$this->tileCount+=StructureTiles::apply($level,$result['tiles']);
		$this->entityCount+=StructureEntities::apply($level,$result['entities']);
		++$this->cursor;
	}
}
