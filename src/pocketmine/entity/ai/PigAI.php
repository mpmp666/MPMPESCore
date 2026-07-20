<?php

namespace pocketmine\entity\ai;

use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Pig;
use pocketmine\entity\Monster;
use pocketmine\block\Block;
use pocketmine\scheduler\CallbackTask;
use pocketmine\network\protocol\SetEntityMotionPacket;
use pocketmine\item\Item;

/*
 * PigAI - 复用 CowAI 已验证正常的随机行走逻辑, 仅限定 Pig 实体
 * (原 PigAI 的 PigRandomWalk 发包存在运算符优先级 bug, 导致猪抽搐/瞬移, 这里直接用牛的逻辑)
 */
class PigAI{

	private $AIHolder;

	public $width = 0.3;
	private $dif = 0;


	public function __construct(AIHolder $AIHolder){
		$this->AIHolder = $AIHolder;
		if($this->AIHolder->getServer()->aiConfig["pig"]){
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"PigRandomWalkCalc"
			]), 5);

			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"PigRandomWalk"
			]), 10);
		// 原版 0.14.3 TemptGoal / PanicGoal 等价行为
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"PigTempt"
		]), 8);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"PigPanic"
		]), 4);
		// 原版 0.14.3 FloatGoal / AvoidMobGoal / BreedGoal
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"PigFloat"
		]), 4);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"PigAvoid"
		]), 6);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"PigBreed"
		]), 20);
		}
	}

	public function PigRandomWalkCalc(){
		$this->dif = $this->AIHolder->getServer()->getDifficulty();
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if($zo::NETWORK_ID == Pig::NETWORK_ID){
					if($this->AIHolder->willMove($zo)){
						if(!isset($this->AIHolder->Pig[$zo->getId()])){
							$this->AIHolder->Pig[$zo->getId()] = array(
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
							$zom = &$this->AIHolder->Pig[$zo->getId()];
							$zom['x'] = $zo->getX();
							$zom['y'] = $zo->getY();
							$zom['z'] = $zo->getZ();
						}
						$zom = &$this->AIHolder->Pig[$zo->getId()];

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
							//猪停止
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

						if($zom['motionx'] == 0 and $zom['motionz'] == 0){  //猪停止
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

	public function PigRandomWalk(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if($zo::NETWORK_ID == Pig::NETWORK_ID){
					if(isset($this->AIHolder->Pig[$zo->getId()])){
						$zom = &$this->AIHolder->Pig[$zo->getId()];
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
	 * TemptGoal - 原版 0.14.3: 手持胡萝卜时猪跟随玩家 (猪用 CARROT, 牛用 WHEAT)
	 */
	public function PigTempt(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Pig)) continue;
				if(!isset($this->AIHolder->Pig[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Pig[$zo->getId()];
				if(!empty($zom['panic'])) continue;

				$target = null;
				foreach($level->getPlayers() as $p){
					$item = $p->getInventory()->getItemInHand();
					if($item->getId() === Item::CARROT or $item->getId() === Item::POTATO or $item->getId() === Item::BEETROOT){
						$dist = $zo->distance($p);
						if($dist < 8) { $target = $p; break; }
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

	/*
	 * PanicGoal - 原版 0.14.3: 受伤后短暂乱跑
	 */
	public function PigPanic(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Pig)) continue;
				if(!isset($this->AIHolder->Pig[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Pig[$zo->getId()];

				if(!isset($zom['panic'])) $zom['panic'] = 0;
				if($zom['hurt'] < 10){
					$zom['panic'] = 60;
				}
				if($zom['panic'] > 0){
					$zom['panic'] -= 1;
					$zom['motionx'] = mt_rand(-10, 10) / 10 * 0.6;
					$zom['motionz'] = mt_rand(-10, 10) / 10 * 0.6;
					$zom['IsChasing'] = true;
				} else {
					$zom['IsChasing'] = false;
				}
			}
		}
	}
	/*
	 * FloatGoal - 防沉底 (通用)
	 */
	public function PigFloat(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Pig)) continue;
				if(!isset($this->AIHolder->Pig[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Pig[$zo->getId()];
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
	public function PigAvoid(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Pig)) continue;
				if(!isset($this->AIHolder->Pig[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Pig[$zo->getId()];
				if(!empty($zom['panic'])) continue;
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
	public function PigBreed(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Pig)) continue;
				if(!isset($this->AIHolder->Pig[$zo->getId()])) continue;
				if($zo->inLove > 0){
					$zo->inLove -= 1;
					foreach($level->getEntities() as $other){
						if($other instanceof Pig and $other !== $zo and $other->inLove > 0 and $other->distance($zo) < 2){
							if(mt_rand(0,100) < 15){
								$nbt = new \pocketmine\nbt\tag\CompoundTag("", [new \pocketmine\nbt\tag\ByteTag("Age", -24000)]);
								$baby = new Pig($zo->getLevel()->getChunk($zo->x>>4, $zo->z>>4), $nbt);
									$baby->setPosition(new \pocketmine\math\Vector3($zo->x, $zo->y, $zo->z));
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
	public function array_clear(){
		if(count($this->AIHolder->Pig) != 0){
			foreach($this->AIHolder->Pig as $eid => $info){
				foreach($this->AIHolder->getServer()->getLevels() as $level){
					if(!($level->getEntity($eid) instanceof Entity)){
						unset($this->AIHolder->Pig[$eid]);
					}
				}
			}
		}
	}


}
