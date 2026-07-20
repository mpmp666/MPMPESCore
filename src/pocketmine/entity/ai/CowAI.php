<?php

namespace pocketmine\entity\ai;

use pocketmine\entity\Ocelot;
use pocketmine\entity\Pig;
use pocketmine\entity\Sheep;
use pocketmine\entity\Wolf;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\Cow;
use pocketmine\entity\Monster;
use pocketmine\entity\Mooshroom;
use pocketmine\scheduler\CallbackTask;
use pocketmine\network\protocol\SetEntityMotionPacket;
use pocketmine\item\Item;

class CowAI{

	private $AIHolder;

	public $width = 0.3;
	private $dif = 0;


	public function __construct(AIHolder $AIHolder){
		$this->AIHolder = $AIHolder;
		if($this->AIHolder->getServer()->aiConfig["cow"]){
			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"CowRandomWalkCalc"
			]), 5);

			$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
				$this,
				"CowRandomWalk"
			]), 10);
			/*	$this->plugin->getServer()->getScheduler ()->scheduleRepeatingTask ( new CallbackTask ( [
				$this,
				"array_clear"
			] ), 20 * 5);*/
		// 原版 0.14.3 TemptGoal / PanicGoal 等价行为
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"CowTempt"
		]), 8);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"CowPanic"
		]), 4);
		// 原版 0.14.3 FloatGoal (防沉底) / AvoidMobGoal (避怪) / BreedGoal (繁殖)
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"CowFloat"
		]), 4);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"CowAvoid"
		]), 6);
		$this->AIHolder->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask ([
			$this,
			"CowBreed"
		]), 20);
			/*	$this->plugin->getServer()->getScheduler ()->scheduleRepeatingTask ( new CallbackTask ( [
					$this,
					"array_clear"
				] ), 20 * 5);*/

		}
	}

	public function CowRandomWalkCalc(){
		$this->dif = $this->AIHolder->getServer()->getDifficulty();
		//$this->getLogger()->info("牛数量：".count($this->plugin->Cow));
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(($zo::NETWORK_ID == Cow::NETWORK_ID) or ($zo::NETWORK_ID == Mooshroom::NETWORK_ID)){
					if($this->AIHolder->willMove($zo)){
						if(!isset($this->AIHolder->Cow[$zo->getId()])){
							$this->AIHolder->Cow[$zo->getId()] = array(
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
							$zom = &$this->AIHolder->Cow[$zo->getId()];
							$zom['x'] = $zo->getX();
							$zom['y'] = $zo->getY();
							$zom['z'] = $zo->getZ();
						}
						$zom = &$this->AIHolder->Cow[$zo->getId()];

						//if ($zom['IsChasing'] === false) {  //自由行走模式

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
							//牛停止
						}

						$zom['gotimer'] += 0.5;
						if($zom['gotimer'] >= 22) $zom['gotimer'] = 0;  //重置走路计时器

						//$zom['motionx'] = mt_rand(-10,10)/10;
						//$zom['motionz'] = mt_rand(-10,10)/10;
						$zom['yup'] = 0;
						$zom['up'] = 0;

						//boybook的y轴判断法
						//$width = $this->width;
						$pos = new Vector3 ($zom['x'] + $zom['motionx'], floor($zo->getY()) + 1, $zom['z'] + $zom['motionz']);  //目标坐标
						$zy = $this->AIHolder->ifjump($zo->getLevel(), $pos);

						if($zy === false){  //前方不可前进
							$pos2 = new Vector3 ($zom['x'], $zom['y'], $zom['z']);  //目标坐标
							if($this->AIHolder->ifjump($zo->getLevel(), $pos2) === false){ //原坐标依然是悬空
								//	$pos2 = new Vector3 ($zom['x'], $zom['y'],$zom['z']);  //下降
								//	$zom['up'] = 1;
								$zom['yup'] = 0;
							}else{
								//	print($zy-$pos->y);
								$zom['motionx'] = -$zom['motionx'];
								$zom['motionz'] = -$zom['motionz'];
								//$zom['motiony'] = 0.01;
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

						if($zom['motionx'] == 0 and $zom['motionz'] == 0){  //牛停止
						}else{
							//转向计算
							$yaw = $this->AIHolder->getyaw($zom['motionx'], $zom['motionz']);
							//$zo->setRotation($yaw,0);
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
						//echo($zo->getY()."\n");
						//var_dump($pos2);
						//var_dump($zom['motiony']);
						$zo->setPosition($pos2);
						//echo "SetPosition \n";
					}
					//}

				}
			}
		}
	}

	public function CowRandomWalk(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(($zo::NETWORK_ID == Cow::NETWORK_ID) or ($zo::NETWORK_ID == Mooshroom::NETWORK_ID)){
					if(isset($this->AIHolder->Cow[$zo->getId()])){
						$zom = &$this->AIHolder->Cow[$zo->getId()];
						if($zom['canAttack'] != 0){
							$zom['canAttack'] -= 1;
						}
						//echo ($zom['IsChasing']."\n");

						//真正的自由落体 by boybook
						$downly = $zo->onGround;

						/*	if ($zo->onGround != false) {
								$downly=true;

								//$zom['motionY']=-0.04;
								//zom['drop'] += 0.01;
							} else {
								$drop = 0;

							}*/
						if(abs($zo->getY() - $zom['oldv3']->y) == 1 and $zom['canjump'] === true){
							//var_dump("跳");
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
							[$zo->getID(), $zom['xxx'], $zom['jump'] - $downly ? 0.04 : 0, $zom['zzz']]
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
	 * TemptGoal - 原版 0.14.3: 手持小麦时牛跟随玩家
	 * canUse: 附近玩家手持 wheat 且未在 panic -> start
	 * tick  : 朝玩家移动 (覆盖随机走 motion), 距离过近则停下
	 */
	public function CowTempt(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Cow)) continue;
				if(!isset($this->AIHolder->Cow[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Cow[$zo->getId()];
				if(!empty($zom['panic'])) continue; // PanicGoal 优先

				// canUse: 找手持小麦的玩家
				$target = null;
				foreach($level->getPlayers() as $p){
					$item = $p->getInventory()->getItemInHand();
					if($item->getId() === Item::WHEAT){
						$dist = $zo->distance($p);
						if($dist < 8) { $target = $p; break; }
					}
				}
				if($target !== null){
					// start/tick: 朝玩家走
					$dx = $target->x - $zo->x;
					$dz = $target->z - $zo->z;
					$len = sqrt($dx*$dx + $dz*$dz) ?: 1;
					if($len > 1.2){ // 太近就停, 避免贴脸
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
	 * canUse: 实体 hurt 计时 < 10 (受伤后倒计时) -> start
	 * tick  : 随机高速 motion, 计时递减
	 * stop   : 计时归零, 恢复
	 */
	public function CowPanic(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Cow)) continue;
				if(!isset($this->AIHolder->Cow[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Cow[$zo->getId()];

				if(!isset($zom['panic'])) $zom['panic'] = 0;
				if($zom['hurt'] < 10){ // hurt 在 RandomWalkCalc 里被设为受伤后倒计时
					$zom['panic'] = 60; // 受伤触发 60 tick 恐慌
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
	 * FloatGoal - 原版 0.14.3: 水中自动上浮防淹死 (所有 Mob 通用)
	 */
	public function CowFloat(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Cow)) continue;
				if(!isset($this->AIHolder->Cow[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Cow[$zo->getId()];
				// 若头在水里, 给向上 motion
				$head = new \pocketmine\math\Vector3($zo->x, $zo->y + $zo->getEyeHeight(), $zo->z);
				if($level->isFullBlock($head) and $level->getBlock($head)->getId() === Block::WATER){
					$zom['yyy'] = 0.3; // 上浮
				} else {
					$zom['yyy'] = 0;
				}
			}
		}
	}

	/*
	 * AvoidMobGoal - 原版 0.14.3: 被动动物躲避附近怪物
	 */
	public function CowAvoid(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Cow)) continue;
				if(!isset($this->AIHolder->Cow[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Cow[$zo->getId()];
				if(!empty($zom['panic'])) continue;
				foreach($level->getEntities() as $m){
					if($m instanceof Monster and $m->distance($zo) < 6){
						$dx = $zo->x - $m->x;
						$dz = $zo->z - $m->z;
						$len = sqrt($dx*$dx + $dz*$dz) ?: 1;
						$zom['motionx'] = $dx/$len*0.5;
						$zom['motionz'] = $dz/$len*0.5;
						$zom['IsChasing'] = true;
						break;
					}
				}
			}
		}
	}

	/*
	 * BreedGoal / MakeLoveGoal - 原版 0.14.3: 两只 inLove 动物靠近 -> 生成幼崽
	 * 简化: 双方 inLove 且邻近 -> 概率 spawn  baby + 清空 inLove
	 */
	public function CowBreed(){
		foreach($this->AIHolder->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $zo){
				if(!($zo instanceof Cow)) continue;
				if(!isset($this->AIHolder->Cow[$zo->getId()])) continue;
				$zom = &$this->AIHolder->Cow[$zo->getId()];
				if($zo->inLove > 0){
					$zo->inLove -= 1;
					foreach($level->getEntities() as $other){
						if($other instanceof Cow and $other !== $zo and $other->inLove > 0 and $other->distance($zo) < 2){
							if(mt_rand(0,100) < 15){
								// 生成幼崽
								$nbt = new \pocketmine\nbt\tag\CompoundTag("", [
									new \pocketmine\nbt\tag\ByteTag("Age", -24000) // baby
								]);
								$baby = new Cow($zo->getLevel()->getChunk($zo->x>>4, $zo->z>>4), $nbt);
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
		if(count($this->AIHolder->Cow) != 0){
			foreach($this->AIHolder->Cow as $eid => $info){
				foreach($this->AIHolder->getServer()->getLevels() as $level){
					if(!($level->getEntity($eid) instanceof Entity)){
						unset($this->AIHolder->Cow[$eid]);
						//echo "清除 $eid \n";
					}
				}
			}
		}
	}


}
