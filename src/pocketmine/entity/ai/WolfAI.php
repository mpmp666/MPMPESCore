<?php

namespace pocketmine\entity\ai;

use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Wolf;
use pocketmine\entity\Monster;
use pocketmine\scheduler\CallbackTask;
use pocketmine\network\protocol\SetEntityMotionPacket;
use pocketmine\item\Item;

/*
 * WolfAI - 原版 0.14.3 Wolf 行为 (逆向符号 SitGoal / BegGoal / PlayGoal 确认)
 *  - SitGoal  : 玩家持骨右键/坐下指令 -> 原地不动, sitting=true
 *  - BegGoal  : 玩家手持物品时狼抬头乞食
 *  - PlayGoal : 幼狼随机蹦跳玩耍
 * 注: 坐下状态用 Animal 基类新增的 $sitting 属性 (Entity 无 DATA_FLAG_SITTING 常量)
 */
class WolfAI{

	private $AIHolder;

	public function __construct(AIHolder $AIHolder){
		$this->AIHolder = $AIHolder;
		if($this->AIHolder->getServer()->aiConfig["wolf"] ?? true){
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"WolfRandomWalkCalc"
			]), 10);
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"WolfRandomWalk"
			]), 1);
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"WolfSit"
			]), 10);
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"WolfBeg"
			]), 8);
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"WolfPlay"
			]), 10);
		}
	}

	public function WolfRandomWalkCalc(){
		$this->dif = $this->AIHolder->getServer()->getDifficulty();
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Wolf)) continue;
				if($zo->isSitting()) continue;
				if(!isset($this->AIHolder->Wolf[$zo->getId()])){
					$this->AIHolder->Wolf[$zo->getId()] = array(
						'ID' => $zo->getId(),
						'IsChasing' => false,
						'motionx' => 0,
						'motiony' => 0,
						'motionz' => 0,
						'hurt' => 10,
						'time' => 10,
						'x' => 0,
						'y' => 0,
						'z' => 0,
						'oldv3' => $zo->getLocation(),
						'yup' => 20,
						'up' => 0,
						'yaw' => $zo->yaw,
						'pitch' => 0,
						'level' => $zo->getLevel()->getName(),
						'xxx' => 0,
						'zzz' => 0,
						'gotimer' => 10,
						'swim' => 0,
						'jump' => 0.01,
						'canjump' => true,
						'drop' => false,
						'canAttack' => 0,
						'knockBack' => false,
					);
					$zom = &$this->AIHolder->Wolf[$zo->getId()];
					$zom['x'] = $zo->getX();
					$zom['y'] = $zo->getY();
					$zom['z'] = $zo->getZ();
				}
				$zom = &$this->AIHolder->Wolf[$zo->getId()];
				if(($zom['gotimer'] ?? 0) == 0 or ($zom['gotimer'] ?? 0) == 10){
					$newmx = mt_rand(-5, 5) / 10;
					while(abs($newmx - ($zom['motionx'] ?? 0)) >= 0.7){
						$newmx = mt_rand(-5, 5) / 10;
					}
					$zom['motionx'] = $newmx;
					$newmz = mt_rand(-5, 5) / 10;
					while(abs($newmz - ($zom['motionz'] ?? 0)) >= 0.7){
						$newmz = mt_rand(-5, 5) / 10;
					}
					$zom['motionz'] = $newmz;
				}elseif(($zom['gotimer'] ?? 0) >= 20 and ($zom['gotimer'] ?? 0) <= 24){
					$zom['motionx'] = 0;
					$zom['motionz'] = 0;
				}
				$zom['gotimer'] = ($zom['gotimer'] ?? 0) + 0.5;
				if(($zom['gotimer'] ?? 0) >= 22) $zom['gotimer'] = 0;
				$zom['yup'] = 0;
				$zom['up'] = 0;
				$pos = new Vector3 ($zom['x'] + $zom['motionx'], floor($zo->getY()) + 1, $zom['z'] + $zom['motionz']);
				$zy = $this->AIHolder->ifjump($zo->getLevel(), $pos);
				if($zy === false){
					$pos2 = new Vector3 ($zom['x'], $zom['y'], $zom['z']);
					if($this->AIHolder->ifjump($zo->getLevel(), $pos2) === false){
						$zom['yup'] = 0;
					}else{
						$zom['motionx'] = -$zom['motionx'];
						$zom['motionz'] = -$zom['motionz'];
						$zom['up'] = 0;
					}
				}else{
					$pos2 = new Vector3 ($zom['x'] + $zom['motionx'], $zy - 1, $zom['z'] + $zom['motionz']);
					if($pos2->y - $zom['y'] < 0){
						$zom['up'] = 1;
					}else{
						$zom['up'] = 0;
					}
				}
				if(!(($zom['motionx'] ?? 0) == 0 and ($zom['motionz'] ?? 0) == 0)){
					$yaw = $this->AIHolder->getyaw($zom['motionx'], $zom['motionz']);
					$zom['yaw'] = $yaw;
					$zom['pitch'] = 0;
				}
				if(!($zom['knockBack'] ?? false)){
					$zom['x'] = $pos2->getX();
					$zom['z'] = $pos2->getZ();
					$zom['y'] = $pos2->getY();
				}
				$zom['motiony'] = $pos2->getY() - $zo->getY();
				$zo->setPosition($pos2);
				$zo->setRotation($zom['yaw'], 0);
			}
		}
	}

	public function WolfRandomWalk(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Wolf)) continue;
				if($zo->isSitting()) continue;
				if(!isset($this->AIHolder->Wolf[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Wolf[$zo->getId()];
				$downly = $zo->onGround;
				$oldv3 = $zom['oldv3'] ?? null;
					$oldY = ($oldv3 !== null) ? $oldv3->y : $zo->getY();
					if(abs($zo->getY() - $oldY) == 1 and $zom['canjump'] === true){
					$zom['canjump'] = false;
					$zom['jump'] = 0.3;
				}else{
					if($zom['jump'] > 0.01){
						$zom['jump'] -= 0.1;
					}else{
						$zom['jump'] = 0;
					}
				}
				$pk3 = new SetEntityMotionPacket;
				$pk3->entities = [
					[$zo->getID(), ($zom['xxx'] ?? 0), $zom['jump'] - $downly ? 0.04 : 0, ($zom['zzz'] ?? 0)]
				];
				foreach($zo->getViewers() as $pl){
					$pl->dataPacket($pk3);
				}
			}
		}
	}

	/*
	 * SitGoal - 玩家持骨且距离近时, 狼坐下 (canUse: 持骨+近距 -> start: sitting=true)
	 * 简化: 玩家手持骨头且距离<3 -> 切换 sitting 状态 (每隔一段时间检测)
	 */
	public function WolfSit(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Wolf)) continue;
				if(!isset($this->AIHolder->Wolf[$zo->getId()])){
					continue; // 数组尚未由 Calc 初始化, 跳过 (避免建残缺数组导致后续 key undefined)
				}
				$zom = &$this->AIHolder->Wolf[$zo->getId()];

				// canUse: 找手持骨头的近距玩家
				$sitNow = false;
				foreach($level->getPlayers() as $p){
					$item = $p->getInventory()->getItemInHand();
					if($item->getId() === Item::BONE and $p->distance($zo) < 3){
						$sitNow = true;
						break;
					}
				}
				// 状态翻转: 持骨则坐下, 否则站起 (原版是右键切换, 这里简化为持骨即坐)
				if($sitNow !== $zo->sitting){
					$zo->setSitting($sitNow);
					$zom['sitting'] = $sitNow;
				}
				// 坐下时清除移动意图
				if($zo->sitting and isset($this->AIHolder->Wolf[$zo->getId()])){
					$zom['motionx'] = 0;
					$zom['motionz'] = 0;
				}
			}
		}
	}

	/*
	 * BegGoal - 玩家手持任何物品时狼抬头乞食 (仅置标志, 实际抬头动画用 DATA_FLAG_ 近似)
	 */
	public function WolfBeg(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Wolf)) continue;
				if($zo->sitting) continue;
				$begging = false;
				foreach($level->getPlayers() as $p){
					$item = $p->getInventory()->getItemInHand();
					if($item->getId() !== Item::AIR and $p->distance($zo) < 6){
						$begging = true;
						break;
					}
				}
				// 用现有可用的 DATA_FLAG_ACTION(4) 近似"乞食动作" (Entity 无 INLOVE/SITTING)
				$zo->setDataFlag(Entity::DATA_FLAGS, Entity::DATA_FLAG_ACTION, $begging);
			}
		}
	}

	/*
	 * PlayGoal - 幼狼随机蹦跳 (原版 PlayGoal, 仅 baby)
	 */
	public function WolfPlay(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Wolf)) continue;
				if(!$zo->isBaby()) continue;
				if($zo->sitting) continue;
				if(mt_rand(0, 100) < 10){
					// 蹦跳: 给一个向上的 motion 包
					$pk = new \pocketmine\network\protocol\SetEntityMotionPacket;
					$pk->entities = [[$zo->getID(), 0, 0.4, 0]];
					foreach($zo->getViewers() as $pl){
						$pl->dataPacket($pk);
					}
				}
			}
		}
	}

}
