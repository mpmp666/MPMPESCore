<?php

namespace pocketmine\entity\ai;

use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Wolf;
use pocketmine\entity\Monster;
use pocketmine\scheduler\CallbackTask;
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
				if($zo->isSitting()) continue; // 坐下不随机走
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
				if($this->AIHolder->willMove($zo)){
					if($zom['gotimer'] == 0 or $zom['gotimer'] == 10){
						$newmx = mt_rand(-5, 5) / 10;
						while(abs($newmx - $zom['motionx']) >= 0.7){
							$newmx = mt_rand(-5, 5) / 10;
						}
						$newmz = mt_rand(-5, 5) / 10;
						while(abs($newmz - $zom['motionz']) >= 0.7){
							$newmz = mt_rand(-5, 5) / 10;
						}
						$zom['motionx'] = $newmx;
						$zom['motionz'] = $newmz;
						$zom['gotimer'] = 0;
					}
					$zom['gotimer'] += 0.5;
				}
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
				if(!$this->AIHolder->willMove($zo)) continue;
				$mx = $zom['motionx'] ?? 0;
				$mz = $zom['motionz'] ?? 0;
				$pos = new Vector3($zo->getX(), $zo->getY(), $zo->getZ());
				$pos->x += $mx;
				$pos->z += $mz;
				$zo->setMotion(new Vector3($mx / 10, 0, $mz / 10));
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
					$this->AIHolder->Wolf[$zo->getId()] = ['sitting' => false];
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
