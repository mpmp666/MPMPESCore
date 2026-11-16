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

use pocketmine\level\format\FullChunk;
use pocketmine\level\particle\CriticalParticle;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\Network;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;

class Arrow extends Projectile{
	const NETWORK_ID = 80;

	public $width = 0.5;
	public $length = 0.5;
	public $height = 0.5;

	protected $gravity = 0.05;
	protected $drag = 0.01;

	protected $damage = 2;

	protected $isCritical;
	protected $punch = 0;
	protected $pickupAllowed = true;

	public function setBaseDamage(float $damage){ $this->damage = max(0, $damage); }
	public function getBaseDamage() : float{ return (float) $this->damage; }
	public function setPunch(int $level){ $this->punch = max(0, $level); }
	public function getPunch() : int{ return $this->punch; }
	public function isCritical() : bool{ return $this->isCritical; }
	public function setPickupAllowed(bool $allowed){ $this->pickupAllowed = $allowed; }
	public function canBePickedUp(Player $player) : bool{ return $this->pickupAllowed || $player->isCreative(); }

	public function applyPunch(Living $victim){
		$length = sqrt($this->motionX * $this->motionX + $this->motionZ * $this->motionZ);
		if($this->punch > 0 and $length > 0){
			$victim->setMotion($victim->getMotion()->add($this->motionX / $length * $this->punch * 0.6, 0.1, $this->motionZ / $length * $this->punch * 0.6));
		}
	}

	/** Same speed * base damage and independent critical roll as AbstractArrow. */
	public function hitDamage(float $speed, int $criticalRoll = -1) : int{
		$damage = (int) ceil(max(0, $speed * $this->damage));
		if($this->isCritical){
			$damage += $criticalRoll >= 0 ? $criticalRoll : mt_rand(0, (int) ($damage / 2) + 1);
		}
		return $damage;
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->PickupAllowed = new \pocketmine\nbt\tag\ByteTag("PickupAllowed", $this->pickupAllowed ? 1 : 0);
		$this->namedtag->Punch = new \pocketmine\nbt\tag\ShortTag("Punch", $this->punch);
		$this->namedtag->ArrowDamage = new \pocketmine\nbt\tag\DoubleTag("ArrowDamage", $this->damage);
	}

	public function __construct(FullChunk $chunk, CompoundTag $nbt, ?Entity $shootingEntity = null, $critical = false){
		$this->isCritical = (bool) $critical;
		parent::__construct($chunk, $nbt, $shootingEntity);
		if(isset($nbt->PickupAllowed)){ $this->pickupAllowed = (bool) $nbt["PickupAllowed"]; }
		if(isset($nbt->Punch)){ $this->setPunch((int) $nbt["Punch"]); }
		if(isset($nbt->ArrowDamage)){ $this->setBaseDamage((float) $nbt["ArrowDamage"]); }
	}

	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$this->timings->startTiming();

		$hasUpdate = parent::onUpdate($currentTick);

		if(!$this->hadCollision and $this->isCritical){
			$this->level->addParticle(new CriticalParticle($this->add(
				$this->width / 2 + mt_rand(-100, 100) / 500,
				$this->height / 2 + mt_rand(-100, 100) / 500,
				$this->width / 2 + mt_rand(-100, 100) / 500)));
		}elseif($this->onGround){
			$this->isCritical = false;
		}

		if($this->age > 1200){
			$this->kill();
			$hasUpdate = true;
		}

		$this->timings->stopTiming();

		return $hasUpdate;
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->type = Arrow::NETWORK_ID;
		$pk->eid = $this->getId();
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);
	}
}