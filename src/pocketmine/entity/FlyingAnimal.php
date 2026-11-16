<?php

/**
 * Author: boybook
 * OpenGenisys Project
 *
 * COPYRIGHT (C) FCCORE
*/

namespace pocketmine\entity;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;

abstract class FlyingAnimal extends Creature implements Ageable{

    protected $gravity = 0;
    protected $drag = 0.02;

    /** @var Vector3 */
    public $flyDirection = null;
    public $flySpeed = 0.5;
    public $highestY = 128;

    private $switchDirectionTicker = 0;
    public $switchDirectionTicks = 300;

    public function onUpdate($currentTick){
		return parent::onUpdate($currentTick);
	}

    private function generateRandomDirection(){
        return new Vector3(mt_rand(-1000, 1000) / 1000, mt_rand(-500, 500) / 1000, mt_rand(-1000, 1000) / 1000);
    }

	public function initEntity(){
		parent::initEntity();
		if($this->getDataProperty(self::DATA_AGEABLE_FLAGS) === null){
			$this->setDataProperty(self::DATA_AGEABLE_FLAGS, self::DATA_TYPE_BYTE, 0);
		}
	}

	public function isBaby(){
		return $this->getDataFlag(self::DATA_AGEABLE_FLAGS, self::DATA_FLAG_BABY);
	}

    public function attack($damage, EntityDamageEvent $source){
        if($source->isCancelled()){
            return;
        }
        if ($source->getCause() == EntityDamageEvent::CAUSE_FALL) {
            $source->setCancelled();
            return;
        }
        parent::attack($damage, $source);
    }

}
