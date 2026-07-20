<?php

namespace pocketmine\entity\ai;

use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\math\Vector2;
use pocketmine\entity\Entity;
use pocketmine\entity\Sheep;
use pocketmine\entity\Monster;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\scheduler\CallbackTask;
use pocketmine\network\protocol\SetEntityMotionPacket;
use pocketmine\event\entity\EntityDamageEvent;

/*
 * SheepAI - 复用 CowAI 已验证正常的随机行走逻辑, 仅限定 Sheep 实体
 * (原 SheepAI 的 SheepRandomWalkCalc 用 IsChasing 包裹 + SheepRandomWalk 又独立搞坠落逻辑,
 *  两套坐标更新互相打架导致羊飘/穿地, 这里直接用牛的逻辑)
 *
 * 新增 (2026-07-20): 原版 0.14.3 EatBlockGoal 等价行为
 *  - 对应 libminecraftpe.so 导出符号 EatBlockGoal (逆向确认存在)
 *  - 生命周期: canUse(随机间隔+站立) -> start(低头计时) -> tick(吃草: 脚下草方块变泥土) -> stop(恢复)
 */
class SheepAI{

	private $AIHolder;

	public $width = 0.3;
	private $dif = 0;


	public function __construct(AIHolder $AIHolder){
		$this->AIHolder = $AIHolder;
		if($this->AIHolder->getServer()->aiConfig["sheep"]){
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"SheepRandomWalkCalc"
			]), 5);

			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"SheepRandomWalk"
			]), 10);
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"array_clear"
			]), 20 * 5);
			// 原版 0.14.3 EatBlockGoal: 羊低头吃草
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"SheepEatGrass"
			]), 20);
			// 原版 0.14.3 TemptGoal: 手持小麦时羊跟随玩家
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"SheepTempt"
		]), 8);
		// 原版 0.14.3 FloatGoal / AvoidMobGoal / BreedGoal
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"SheepFloat"
		]), 4);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"SheepAvoid"
		]), 6);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"SheepBreed"
		]), 20);
		}
	}

	public function SheepRandomWalkCalc(){
		$this->dif = $this->AIHolder->getServer()->getDifficulty();
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if($zo instanceof Sheep){
					if($this->AIHolder->willMove($zo)){
						if(!isset($this->AIHolder->Sheep[$zo->getId()])){
							$this->AIHolder->Sheep[$zo->getId()] = array(
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
								// --- EatBlockGoal 状态 ---
								'eating' => false,
								'eatTimer' => 0,
								'eatCooldown' => mt_rand(10, 40),
							);
							$zom = &$this->AIHolder->Sheep[$zo->getId()];
							$zom['x'] = $zo->getX();
							$zom['y'] = $zo->getY();
							$zom['z'] = $zo->getZ();
						}
						$zom = &$this->AIHolder->Sheep[$zo->getId()];

						if($zom['gotimer'] == 0 or $zom['gotimer'] == 10){
							//限制转动幅度
							$newmx = mt_rand(-5, 5) / 10;
							while(abs($newmx - $zom['motionx']) >= 0.7){
								$newmx = mt_rand(-5, 5) / 10;
							}
							$zom['motionx'] = $newmx;

							$newmz = mt_rand(-5, 5) / 10;
							while(abs($newmz - $zom['motionz']) >= 0.7){
								$newmz = mt_rand(-5, 5) / 10;
							}
							$zom['motionz'] = $newmz;
						}elseif($zom['gotimer'] >= 20 and $zom['gotimer'] <= 24){
							$zom['motionx'] = 0;
							$zom['motionz'] = 0;
							//羊停止
						}

						$zom['gotimer'] += 0.5;
						if($zom['gotimer'] >= 22) $zom['gotimer'] = 0;  //重置走路计时器

						$zom['yup'] = 0;
						$zom['up'] = 0;

						//boybook的y轴判断法
						$pos = new Vector3 ($zom['x'] + $zom['motionx'], floor($zo->getY()) + 1, $zom['z'] + $zom['motionz']);  //目标坐标
						$zy = $this->AIHolder->ifjump($zo->getLevel(), $pos);
						if($zy === false){  //前方不可前进
							$pos2 = new Vector3 ($zom['x'], $zom['y'], $zom['z']);  //目标坐标
							if($this->AIHolder->ifjump($zo->getLevel(), $pos2) === false){ //原坐标依然是悬空
								$pos2 = new Vector3 ($zom['x'], $zom['y'] - 1, $zom['z']);  //下降
								$zom['up'] = 1;
								$zom['yup'] = 0;
							}else{
								$zom['motionx'] = -$zom['motionx'];
								$zom['motionz'] = -$zom['motionz'];
								//转向180度，向身后走
								$zom['up'] = 0;
							}
						}else{
							$pos2 = new Vector3 ($zom['x'] + $zom['motionx'], $zy - 1, $zom['z'] + $zom['motionz']);  //目标坐标
							if($pos2->y - $zom['y'] < 0){
								$zom['up'] = 1;
							}else{
								$zom['up'] = 0;
							}
						}

						if($zom['motionx'] == 0 and $zom['motionz'] == 0){  //羊停止
										$yaw = $zo->yaw;  //默认保留当前朝向, 避免停步时 Undefined variable
						}else{
							//转向计算
							$yaw = $this->AIHolder->getyaw($zom['motionx'], $zom['motionz']);
							$zom['yaw'] = $yaw;
							$zom['pitch'] = 0;
						}

						//更新坐标
						if(!$zom['knockBack']){
							$zom['x'] = $pos2->getX();
							$zom['z'] = $pos2->getZ();
							$zom['y'] = $pos2->getY();
						}

						$zom['motiony'] = $pos2->getY() - $zo->getY();
							$zo->setRotation($yaw, 0);
						$zo->setPosition($pos2);
					}
				}
			}
		}
	}

	public function SheepRandomWalk(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if($zo instanceof Sheep){
					if(isset($this->AIHolder->Sheep[$zo->getId()])){
						$zom = &$this->AIHolder->Sheep[$zo->getId()];
						if($zom['canAttack'] != 0){
							$zom['canAttack'] -= 1;
						}

						//真正的自由落体 by boybook
						$downly = $zo->onGround;

						if(abs($zo->getY() - $zom['oldv3']->y) == 1 and $zom['canjump'] === true){
							$zom['canjump'] = false;
							$zom['jump'] = 0.3;
						}else{
							if($zom['jump'] > 0.01){
								$zom['jump'] -= 0.1;
							}else{
								$zom['jump'] = 0;
							}
						}

						//echo ".";
						$pk3 = new SetEntityMotionPacket;
						$pk3->entities = [
							[$zo->getID(), $zom['xxx'], $zom['jump'] - ($downly ? 0.04 : 0), $zom['zzz']]
						];
						foreach($zo->getViewers() as $pl){
							$pl->dataPacket($pk3);
						}
					}
				}
			}
		}
	}

	/*
	 * FloatGoal - 防沉底 (通用)
	 */
	public function SheepFloat(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Sheep)) continue;
				if(!isset($this->AIHolder->Sheep[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Sheep[$zo->getId()];
				$head = new \pocketmine\math\Vector3($zo->x, $zo->y + $zo->getEyeHeight(), $zo->z);
				if($level->isFullBlock($head) and $level->getBlock($head)->getId() === Block::WATER){
					$zom['yyy'] = 0.3;
				} else {
					$zom['yyy'] = 0;
				}
			}
		}
	}

	/*
	 * AvoidMobGoal - 避怪
	 */
	public function SheepAvoid(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Sheep)) continue;
				if(!isset($this->AIHolder->Sheep[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Sheep[$zo->getId()];
				if($zom['eating']) continue;
				foreach($level->getEntities() as $m){
					if($m instanceof Monster and $m->distance($zo) < 6){
						$dx = $zo->x - $m->x; $dz = $zo->z - $m->z;
						$len = sqrt($dx*$dx + $dz*$dz) ?: 1;
						$zom['motionx'] = $dx/$len*0.5; $zom['motionz'] = $dz/$len*0.5;
						$zom['IsChasing'] = true; break;
					}
				}
			}
		}
	}

	/*
	 * BreedGoal - 繁殖
	 */
	public function SheepBreed(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Sheep)) continue;
				if(!isset($this->AIHolder->Sheep[$zo->getId()])) continue;
				if($zo->inLove > 0){
					$zo->inLove -= 1;
					foreach($level->getEntities() as $other){
						if($other instanceof Sheep and $other !== $zo and $other->inLove > 0 and $other->distance($zo) < 2){
							if(mt_rand(0,100) < 15){
								$nbt = new \pocketmine\nbt\tag\CompoundTag("", [new \pocketmine\nbt\tag\ByteTag("Age", -24000)]);
								$baby = new Sheep($zo->getLevel()->getChunk($zo->x>>4, $zo->z>>4), $nbt);
									$baby->setPosition(new \pocketmine\math\Vector3($zo->x, $zo->y, $zo->z));
									$baby->setColor($zo->getColor());
									$baby->spawnToAll();
									$zo->inLove = 0; $other->inLove = 0;
								}
								break;
						}
					}
				}
			}
		}
	}
	/*
	 * TemptGoal - 原版 0.14.3: 手持小麦时羊跟随玩家
	 */
	public function SheepTempt(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Sheep)) continue;
				if(!isset($this->AIHolder->Sheep[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Sheep[$zo->getId()];
				if(!empty($zom['panic'])) continue;
				if($zom['eating']) continue;

				$target = null;
				foreach($level->getPlayers() as $p){
					$item = $p->getInventory()->getItemInHand();
					if($item->getId() === Item::WHEAT){
						if($p->distance($zo) < 8) { $target = $p; break; }
					}
				}
				if($target !== null){
					$dx = $target->x - $zo->x;
					$dz = $target->z - $zo->z;
					$len = sqrt($dx*$dx + $dz*$dz) ?: 1;
					if($len > 1.2){
						$zom['motionx'] = $dx / $len * 0.4;
						$zom['motionz'] = $dz / $len * 0.4;
						$zom['IsChasing'] = true;
					}
				}
			}
		}
	}
	public function array_clear(){
		if(count($this->AIHolder->Sheep) != 0){
			foreach($this->AIHolder->Sheep as $eid => $info){
				foreach($this->AIHolder->getServer()->getLevels() as $level){
					if(!($level->getEntity($eid) instanceof Entity)){
						unset($this->AIHolder->Sheep[$eid]);
						//echo "清除 $eid \n";
					}
				}
			}
		}
	}

	/*
	 * EatBlockGoal - 原版 0.14.3 Sheep 吃草行为 (逆向符号 EatBlockGoal 确认)
	 * 每 20 tick 跑一次, 状态机:
	 *   canUse : 站立 + 未在吃 + 冷却结束 + 随机概率 -> start
	 *   start  : eating=true, eatTimer=0 (低头)
	 *   tick   : eatTimer 累加, 到 40 tick 完成 -> 脚下草方块变泥土 -> stop
	 *   stop   : eating=false, 重置冷却
	 * 注: 原版低头用 DATA_FLAG_EATING, 本核心 Entity 无此常量,
	 *      故仅做方块变换 + 站立判定, 不引用未定义 flag (避免致命错误)
	 */
	public function SheepEatGrass(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Sheep)) continue;
				if(!isset($this->AIHolder->Sheep[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Sheep[$zo->getId()];

				if($zom['eating']){
					// --- tick: 吃草计时 ---
					$zom['eatTimer'] += 1;
					if($zom['eatTimer'] >= 40){
						// --- stop: 吃草完成, 脚下草方块变泥土 ---
						$pos = new Vector3(floor($zo->getX()), floor($zo->getY()) - 1, floor($zo->getZ()));
						$block = $level->getBlock($pos);
						if($block->getId() === Block::GRASS){
							$level->setBlock($pos, Block::get(Block::DIRT), true, true);
						}
						$zom['eating'] = false;
						$zom['eatTimer'] = 0;
						$zom['eatCooldown'] = mt_rand(15, 45);
					}
				}else{
					// --- canUse: 冷却递减, 到点按概率触发 ---
					$zom['eatCooldown'] -= 1;
					if($zom['eatCooldown'] <= 0 and mt_rand(0, 100) < 8){
						$zom['eating'] = true;
						$zom['eatTimer'] = 0;
					}
				}
			}
		}
	}

}
