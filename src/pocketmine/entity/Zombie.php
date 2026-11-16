<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace pocketmine\entity;


use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\Item as ItemItem;
use pocketmine\network\Network;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\event\entity\EntityDamageEvent;

class Zombie extends Monster{
	const NETWORK_ID = 32;

	public $width = 0.6;
	public $length = 0.6;
	public $height = 1.8;

	public $dropExp = [5, 5];
	
	public $drag = 0.2;
	public $gravity = 0.3;
	
	private $moveDirection = null; //移动方向
	private $moveSpeed = 0.2; //移动速度
	private $hated = false; //仇恨的玩家
	private $tempTicker = 0;
	private $tempTicking = false; //走出困境计时器
	private $moveTicker = 0; //运动计时器
	private $hate_r = 16; //仇恨半径
	private $attack_r = 1.5; //攻击半径
	private $fire_r = 1.3; //点燃半径
	private $hateTicker = 0; //仇恨计时器

	public function getName() : string{
		return "Zombie";
	}
	
	public function initEntity(){
		$this->setMaxHealth(20);
		parent::initEntity();
	}
	
	public function attack($damage, EntityDamageEvent $source){
		return parent::attack($damage,$source);
	}

	
	private function generateRandomDirection(){
		return new Vector3(mt_rand(-1000, 1000) / 1000, 0, mt_rand(-1000, 1000) / 1000);
	}
	
	/*
	 * 返回一个一位小数
	*/
	private function toFloat($num){
		while(((abs($num) > 1) or (abs($num) < 0.1)) and (abs($num) > 0)) {
			if(abs($num) > 1) $num /= 10;
			if(abs($num) < 0.1) $num *= 10;
		}
		return $num;
	}
	
	private function generateDirection(Vector3 $pos){
		return new Vector3($this->toFloat($pos->x - $this->x), 0, $this->toFloat($pos->z - $this->z));
	}
	
	private function getNearestPlayer(){
		$dis = PHP_INT_MAX;
		$player = false;
		foreach($this->getViewers() as $p){
			if($p->distance($this) < $dis){
				$dis = $p->distance($this);
				$player = $p;
			}
		}
		return (($dis <= $this->hate_r) ? $p : false);
	}
	
	private function getVelY(){
		$expectedPos = (new Vector3($this->x + $this->moveDirection->x * $this->moveSpeed, $this->y + $this->motionY, $this->z + $this->moveDirection->z * $this->moveSpeed))->round();
		$block0 = $this->getLevel()->getBlock($expectedPos);
		$block1 = $this->getLevel()->getBlock($expectedPos->add(0, 1, 0));
		if($block1->getId() != 0) return 1.2;
		return 0;
	}
	
	public function onUpdate($currentTick){
		return parent::onUpdate($currentTick);
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = Zombie::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);
	}

	public function getDrops(){
		$drops = [];
		if($this->lastDamageCause instanceof EntityDamageByEntityEvent and $this->lastDamageCause->getEntity() instanceof Player){
			if(mt_rand(0, 199) < 5){
				switch(mt_rand(0, 3)){
					case 0:
						$drops[] = ItemItem::get(ItemItem::IRON_INGOT, 0, 1);
						break;
					case 1:
						$drops[] = ItemItem::get(ItemItem::CARROT, 0, 1);
						break;
					case 2:
						$drops[] = ItemItem::get(ItemItem::POTATO, 0, 1);
						break;
				}
			}
			$count = mt_rand(0, 2);
			if($count > 0){
				$drops[] = ItemItem::get(ItemItem::ROTTEN_FLESH, 0, $count);
			}
		}

		return $drops;
	}
}
