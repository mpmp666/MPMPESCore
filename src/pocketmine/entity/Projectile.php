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


use pocketmine\event\entity\EntityCombustByEntityEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\level\format\FullChunk;
use pocketmine\level\MovingObjectPosition;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;

abstract class Projectile extends Entity{

	const DATA_SHOOTER_ID = 17;

	/** @var Entity */
	public $shootingEntity = null;
	protected $damage = 0;

	public $hadCollision = false;

	public function __construct(FullChunk $chunk, CompoundTag $nbt, ?Entity $shootingEntity = null){
		$this->shootingEntity = $shootingEntity;
		if($shootingEntity !== null){
			$this->setDataProperty(self::DATA_SHOOTER_ID, self::DATA_TYPE_LONG, $shootingEntity->getId());
		}
		parent::__construct($chunk, $nbt);
	}

	public function attack($damage, EntityDamageEvent $source){
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($damage, $source);
		}
	}

	protected function initEntity(){
		parent::initEntity();

		$this->setMaxHealth(1);
		$this->setHealth(1);
		if(isset($this->namedtag->Age)){
			$this->age = $this->namedtag["Age"];
		}

	}

	public function canCollideWith(Entity $entity){
		return $entity instanceof Living and !$this->onGround;
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Age = new ShortTag("Age", $this->age);
	}

	/** Never let mob projectiles load/generate chunks through collision queries. */
	public function hasLoadedFlightArea26() : bool{
		if(!($this->shootingEntity instanceof Creature) || $this->shootingEntity instanceof Human){ return true; }
		if(!is_finite($this->x) || !is_finite($this->z) || !is_finite($this->motionX) || !is_finite($this->motionZ) || abs($this->motionX) > 64 || abs($this->motionZ) > 64){ return false; }
		$padding = max(4.0, $this->width / 2 + 3.0);
		$minX = ((int) floor(min($this->x, $this->x + $this->motionX) - $padding)) >> 4;
		$maxX = ((int) floor(max($this->x, $this->x + $this->motionX) + $padding)) >> 4;
		$minZ = ((int) floor(min($this->z, $this->z + $this->motionZ) - $padding)) >> 4;
		$maxZ = ((int) floor(max($this->z, $this->z + $this->motionZ) + $padding)) >> 4;
		$chunks = $this->getLevel()->getChunks();
		for($cx = $minX; $cx <= $maxX; ++$cx){ for($cz = $minZ; $cz <= $maxZ; ++$cz){
			if(!isset($chunks[\pocketmine\level\Level::chunkHash($cx, $cz)])){ return false; }
		} }
		return true;
	}

	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}


		if(!$this->hasLoadedFlightArea26()){
			$this->kill();
			$this->close();
			return false;
		}
		$tickDiff = $currentTick - $this->lastUpdate;
		if($tickDiff <= 0 and !$this->justCreated){
			return true;
		}
		$this->lastUpdate = $currentTick;

		$hasUpdate = $this->entityBaseTick($tickDiff);

		if($this->isAlive()){

			$movingObjectPosition = null;

			if(!$this->isCollided){
				$this->motionY -= $this->gravity;
			}

			$moveVector = new Vector3($this->x + $this->motionX, $this->y + $this->motionY, $this->z + $this->motionZ);

			$list = $this->getLevel()->getCollidingEntities($this->boundingBox->addCoord($this->motionX, $this->motionY, $this->motionZ)->expand(1, 1, 1), $this);

			$nearDistance = PHP_INT_MAX;
			$nearEntity = null;

			foreach($list as $entity){
				if(/*!$entity->canCollideWith($this) or */
				($entity === $this->shootingEntity and $this->ticksLived < 5)
				){
					continue;
				}

				$axisalignedbb = $entity->boundingBox->grow(0.3, 0.3, 0.3);
				$ob = $axisalignedbb->calculateIntercept($this, $moveVector);

				if($ob === null){
					continue;
				}

				$distance = $this->distanceSquared($ob->hitVector);

				if($distance < $nearDistance){
					$nearDistance = $distance;
					$nearEntity = $entity;
				}
			}

			if($nearEntity !== null){
				$movingObjectPosition = MovingObjectPosition::fromEntity($nearEntity);
			}

			if($movingObjectPosition !== null){
				if($movingObjectPosition->entityHit !== null){

					$this->server->getPluginManager()->callEvent(new ProjectileHitEvent($this));

					$motion = sqrt($this->motionX ** 2 + $this->motionY ** 2 + $this->motionZ ** 2);
					$damage = $this instanceof Arrow ? $this->hitDamage($motion) : ceil($motion * $this->damage);

					if($this->shootingEntity === null){
						$ev = new EntityDamageByEntityEvent($this, $movingObjectPosition->entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage);
					}else{
						$ev = new EntityDamageByChildEntityEvent($this->shootingEntity, $this, $movingObjectPosition->entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage);
					}

					if($movingObjectPosition->entityHit->attack($ev->getFinalDamage(), $ev) === true){
						$ev->useArmors();
					}

					$this->hadCollision = true;

					if(!$ev->isCancelled() and $ev->getFinalDamage() > 0 and $this->fireTicks > 0){
						$ev = new EntityCombustByEntityEvent($this, $movingObjectPosition->entityHit, 5);
						$this->server->getPluginManager()->callEvent($ev);
						if(!$ev->isCancelled()){
							$movingObjectPosition->entityHit->setOnFire($ev->getDuration());
						}
					}

					$this->kill();
					return true;
				}
			}

			$this->move($this->motionX, $this->motionY, $this->motionZ);

			if($this->isCollided and !$this->hadCollision){
				$this->hadCollision = true;

				$this->motionX = 0;
				$this->motionY = 0;
				$this->motionZ = 0;

				$this->server->getPluginManager()->callEvent(new ProjectileHitEvent($this));
			}elseif(!$this->isCollided and $this->hadCollision){
				$this->hadCollision = false;
			}

			if(!$this->onGround or abs($this->motionX) > 0.00001 or abs($this->motionY) > 0.00001 or abs($this->motionZ) > 0.00001){
				$f = sqrt(($this->motionX ** 2) + ($this->motionZ ** 2));
				$this->yaw = (atan2($this->motionX, $this->motionZ) * 180 / M_PI);
				$this->pitch = (atan2($this->motionY, $f) * 180 / M_PI);
				$hasUpdate = true;
			}

			$this->updateMovement();

		}

		return $hasUpdate;
	}

}