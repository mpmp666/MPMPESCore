<?php
/*
 * This file is translated from the Nukkit Project
 * which is written by MagicDroidX
 * @link https://github.com/Nukkit/Nukkit
*/

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\entity\Painting as PaintingEntity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;

class Painting extends Item{
	public function __construct($meta = 0, $count = 1){
		parent::__construct(self::PAINTING, 0, $count, "Painting");
	}

	public function canBeActivated() : bool{
		return true;
	}

	public function onActivate(Level $level, Player $player, Block $block, Block $target, $face, $fx, $fy, $fz){
		if($target->isTransparent() === false and $face > 1 and $block->isSolid() === false){
			// 修复画朝向: 原表整体转了 180 度导致正反面颠倒("面不对")
			// 对齐 MCPE 0.14.3 原版 SO: 客户端 AddPaintingPacket.direction*90=画朝向, 画背贴被点墙面
			// 目标 face->direction: 北(2)->0,南(3)->2,西(4)->3,东(5)->1
			// 经 Entity::getDirection() (dir=(yaw/90+1)%4) 反推 face->yaw/90: 北->3,南->1,西->2,东->0
			$faces = [
				2 => 3,
				3 => 1,
				4 => 2,
				5 => 0,
			];
			$motives = [
				// Motive Width Height
				["Kebab", 1, 1],
				["Aztec", 1, 1],
				["Alban", 1, 1],
				["Aztec2", 1, 1],
				["Bomb", 1, 1],
				["Plant", 1, 1],
				["Wasteland", 1, 1],
				["Wanderer", 1, 2],
				["Graham", 1, 2],
				["Pool", 2, 1],
				["Courbet", 2, 1],
				["Sunset", 2, 1],
				["Sea", 2, 1],
				["Creebet", 2, 1],
				["Match", 2, 2],
				["Bust", 2, 2],
				["Stage", 2, 2],
				["VoidGenerator", 2, 2],
				["SkullAndRoses", 2, 2],
				//array("Wither", 2, 2),
				["Fighters", 4, 2],
				["Skeleton", 4, 3],
				["DonkeyKong", 4, 3],
				["Pointer", 4, 4],
				["Pigscene", 4, 4],
				["Flaming Skull", 4, 4],
			];

			$right = [4, 5, 3, 2];

			$validMotives = [];
			foreach($motives as $motive){
				$valid = true;
				for($x = 0; $x < $motive[1] && $valid; $x++){
					for($z = 0; $z < $motive[2] && $valid; $z++){
						if($target->getSide($right[$face - 2], $x)->isTransparent() ||
							$target->getSide(Vector3::SIDE_UP, $z)->isTransparent() ||
							$block->getSide($right[$face - 2], $x)->isSolid() ||
							$block->getSide(Vector3::SIDE_UP, $z)->isSolid()
						){
							$valid = false;
						}
					}
				}

				if($valid){
					$validMotives[] = $motive;
				}
			}

			$motive = $motives[mt_rand(0, count($validMotives) - 1)];
			$data = [
				"x" => $target->x,
				"y" => $target->y,
				"z" => $target->z,
				"yaw" => $faces[$face] * 90,
				"Motive" => $motive[0],
			];

			$nbt = new CompoundTag("", [
				"Motive" => new StringTag("Motive", $data["Motive"]),
				"Pos" => new ListTag("Pos", [
					new DoubleTag("", $data["x"]),
					new DoubleTag("", $data["y"]),
					new DoubleTag("", $data["z"])
				]),
				"Motion" => new ListTag("Motion", [
					new DoubleTag("", 0),
					new DoubleTag("", 0),
					new DoubleTag("", 0)
				]),
				"Rotation" => new ListTag("Rotation", [
					new FloatTag("", $data["yaw"]),
					new FloatTag("", 0)
				]),
			]);

			$painting = new PaintingEntity($player->getLevel()->getChunk($block->getX() >> 4, $block->getZ() >> 4), $nbt);
			$painting->spawnToAll();

			if($player->isSurvival()){
				$item = $player->getInventory()->getItemInHand();
				$count = $item->getCount();
				if(--$count <= 0){
					$player->getInventory()->setItemInHand(Item::get(Item::AIR));
					return;
				}

				$item->setCount($count);
				$player->getInventory()->setItemInHand($item);
			}
			//TODO
			//$e = $server->api->entity->add($level, ENTITY_OBJECT, OBJECT_PAINTING, $data);
			//$e->spawnToAll();
			/*if(($player->gamemode & 0x01) === 0x00){
				$player->removeItem(Item::get($this->getId(), $this->getDamage(), 1));
			}*/

			return true;
		}

		return false;
	}

}