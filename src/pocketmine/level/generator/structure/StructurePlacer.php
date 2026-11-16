<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\block\Block;
use pocketmine\level\format\FullChunk;

/** Only writes the requested chunk: async 3x3 generation never loses distant pieces. */
final class StructurePlacer{
	/** @return array{blocks:int,tiles:array} */
	public static function chunk(FullChunk $chunk, array $pieces, int $originX, int $originY, int $originZ, bool $foundations = false) : array{
		$minX = $chunk->getX() * 16;
		$minZ = $chunk->getZ() * 16;
		$count = 0;
		$clipped = 0;
		$tiles = [];
		$markers = [];
		$entities = [];
		foreach($pieces as $piece){
			$template = StructureRepository::template($piece['template']);
			$rotation = $piece['rotation'] & 3;
			$px = $originX + $piece['x'];
			$py = $originY + $piece['y'];
			$pz = $originZ + $piece['z'];
			[$sx, $sy, $sz] = $template['size'];
			$corners = [StructureRepository::rotate(0, 0, $rotation), StructureRepository::rotate($sx - 1, 0, $rotation), StructureRepository::rotate(0, $sz - 1, $rotation), StructureRepository::rotate($sx - 1, $sz - 1, $rotation)];
			$xs = array_column($corners, 0);
			$zs = array_column($corners, 1);
			if($px + max($xs) < $minX || $px + min($xs) > $minX + 15 || $pz + max($zs) < $minZ || $pz + min($zs) > $minZ + 15){
				continue;
			}
			$foundationColumns = [];
			$data = $template['blockData'];
			for($offset = 0, $len = strlen($data); $offset < $len; $offset += 8){
				$record = unpack('vx/vy/vz/vp', $data, $offset);
				[$x, $z] = StructureRepository::rotate($record['x'], $record['z'], $rotation);
				$x += $px - $minX;
				$z += $pz - $minZ;
				$y = $py + $record['y'];
				if($x < 0 || $x > 15 || $z < 0 || $z > 15){continue;}
				if($y < 0 || $y > 127){++$clipped;continue;}
				[$id, $meta] = $template['palette'][$record['p']][$rotation];
				if($id < 0 || ($id === 0 && ($piece['ignore_air'] ?? false))){ continue; }
				$chunk->setBlock($x, $y, $z, $id, $meta);
				$light = (int) (Block::$light[$id] ?? 0);
				$chunk->setBlockLight($x, $y, $z, $light);
				++$count;
				// Supports only the lowest solid block in each template column, never furniture.
				if($foundations && $piece['y'] <= 1 && $id !== 0 && !in_array($id, [8,9,10,11,18,30,31,37,38,50,51,78,85,106,171], true)){
					$key = ($x << 4) | $z;
					if(!isset($foundationColumns[$key]) || $y < $foundationColumns[$key][0]){
						$foundationColumns[$key] = [$y, $id];
					}
				}
			}
			foreach($foundationColumns as $key => [$y, $id]){
				if($y > $py + 3){ continue; }
				$x = $key >> 4; $z = $key & 15;
				for($below = $y - 1; $below >= max(1, $y - 18); --$below){
					$current = $chunk->getBlockId($x, $below, $z);
					if($current !== 0 && !in_array($current, [8,9,31,78], true)){ break; }
					$chunk->setBlock($x, $below, $z, $id === 121 ? 121 : 4, 0);
					++$count;
				}
			}
			foreach($template['tiles'] ?? [] as $tile){
				[$tx, $ty, $tz] = $tile['pos'];
				[$tx, $tz] = StructureRepository::rotate($tx, $tz, $rotation);
				$tx += $px; $tz += $pz; $ty += $py;
				if(($tx >> 4) === $chunk->getX() && ($tz >> 4) === $chunk->getZ() && $ty > 0 && $ty < 127){
					$tile['pos'] = [$tx, $ty, $tz];
					$tile['rotation'] = $rotation;
					$tiles[$tx . ':' . $ty . ':' . $tz] = $tile;
				}
			}
			foreach($template['entities'] ?? [] as $entity){
				[$ex,$ey,$ez]=array_map('floatval',$entity['pos']);
				// Entity coordinates are continuous about a block-centre pivot (.5,.5).
				[$rx,$rz]=match($rotation){1=>[1-$ez,$ex],2=>[1-$ex,1-$ez],3=>[$ez,1-$ex],default=>[$ex,$ez]};
				$entity['pos']=[$px+$rx,$py+$ey,$pz+$rz];
				$sourceRotation=$entity['nbt']['Rotation']??[0,0];
				$entity['yaw']=fmod((float)($sourceRotation[0]??0)+90*$rotation+360,360);
				$entity['nbt']['id']=$entity['nbt']['id']??($entity['id']??'');
				$chunkX=(int)floor($entity['pos'][0])>>4;$chunkZ=(int)floor($entity['pos'][2])>>4;
				if($chunkX===$chunk->getX()&&$chunkZ===$chunk->getZ()&&$entity['pos'][1]>1&&$entity['pos'][1]<126){$entities[]=$entity;}
			}
			foreach($template['markers'] ?? [] as $marker){
				[$mx, $my, $mz] = $marker['pos'];
				[$mx, $mz] = StructureRepository::rotate($mx, $mz, $rotation);
				$mx += $px; $mz += $pz; $my += $py;
				$metadata = (string) ($marker['metadata'] ?? '');
				$templateKey=$piece['template'];
				$isMansion=str_starts_with($templateKey,'woodland_mansion/');
				$isRuin=str_starts_with($templateKey,'underwater_ruin/');
				$isEndCity=str_starts_with($templateKey,'end_city/');
				$isIgloo=str_starts_with($templateKey,'igloo/');
				$isShipwreck=str_starts_with($templateKey,'shipwreck/');
				if($isEndCity && $metadata==='Elytra'){
					$tx=$mx;$ty=$my;$tz=$mz;
					if(($tx>>4)===$chunk->getX()&&($tz>>4)===$chunk->getZ()&&$ty>1&&$ty<126){
						$lx=$tx&15;$lz=$tz&15;$chunk->setBlock($lx,$ty,$lz,199,0);
						$tiles[$tx.':'.$ty.':'.$tz]=['name'=>'item_frame','pos'=>[$tx,$ty,$tz],'rotation'=>$rotation,'nbt'=>['Item'=>['id'=>311,'Damage'=>0,'Count'=>1],'ItemRotation'=>0]];
					}
					continue;
				}
				$chestMarker=$isMansion?preg_match('/^Chest(?:North|South|West)$/',$metadata)===1:(($isRuin||$isEndCity||$isIgloo||$isShipwreck)&&preg_match('/^(?:Chest|chest|(?:map|supply|treasure)_chest)$/',$metadata)===1);
				if(!$chestMarker){continue;}
				$below=!$isMansion&&!$isRuin;
				$tx=$mx; $ty=$my-($below?1:0); $tz=$mz;
				if(($tx>>4)!==$chunk->getX()||($tz>>4)!==$chunk->getZ()||$ty<1||$ty>126){continue;}
				$pxLocal=$tx&15; $pzLocal=$tz&15;
				$current=$chunk->getBlockId($pxLocal,$ty,$pzLocal);
				if($current!==0&&$current!==54){continue;}
				$facing=match($metadata){'ChestNorth'=>'north','ChestSouth'=>'south','ChestWest'=>'west',default=>'north'};
				$facing=match($rotation&3){1=>match($facing){'north'=>'east','east'=>'south','south'=>'west','west'=>'north',default=>$facing},2=>match($facing){'north'=>'south','south'=>'north','east'=>'west','west'=>'east',default=>$facing},3=>match($facing){'north'=>'west','west'=>'south','south'=>'east','east'=>'north',default=>$facing},default=>$facing};
				$meta=match($facing){'north'=>5,'south'=>4,'east'=>3,'west'=>2,default=>2};
				$chunk->setBlock($pxLocal,$ty,$pzLocal,54,$meta);
				$loot=match(true){
					$isMansion=>'minecraft:chests/woodland_mansion',
					$isEndCity=>'minecraft:chests/end_city_treasure',
					$isIgloo=>'minecraft:chests/igloo_chest',
					$isShipwreck=>match($metadata){'map_chest'=>'minecraft:chests/shipwreck_map','supply_chest'=>'minecraft:chests/shipwreck_supply','treasure_chest'=>'minecraft:chests/shipwreck_treasure',default=>''},
					$isRuin=>(str_contains($templateKey,'big_')||str_contains($templateKey,'large_'))?'minecraft:chests/underwater_ruin_big':'minecraft:chests/underwater_ruin_small',
					default=>''
				};
				if($loot===''){continue;}
				$tiles[$tx.':'.$ty.':'.$tz]=['name'=>'chest','pos'=>[$tx,$ty,$tz],'nbt'=>['LootTable'=>$loot],'rotation'=>$rotation];
				++$count;
			}
		}
		return ['blocks' => $count, 'clipped' => $clipped, 'tiles' => array_values($tiles), 'markers'=>array_values($markers), 'entities'=>array_values($entities)];
	}
}
