<?php

namespace pocketmine\entity;

use pocketmine\entity\ai\Environment26;
use pocketmine\level\Explosion;
use pocketmine\level\Position;
use pocketmine\level\format\FullChunk;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;

/**
 * Legacy-compatible projectile for the shared Blaze/Ghast controller.
 * Register with force=true: NETWORK_ID=-1 intentionally preserves Snowball's
 * numeric factory ID. The client sees an on-fire snowball (81); native 26.2
 * fireball rendering, acceleration and player reflection are not implemented.
 */
class Fireball26 extends Projectile{
	const NETWORK_ID = -1;
	public $width = 0.3;
	public $height = 0.3;
	public $length = 0.3;
	protected $gravity = 0;
	protected $drag = 0;
	protected $fireProof = true;
	private $large26 = false;
	private $exploded26 = false;

	public function __construct(FullChunk $chunk, CompoundTag $nbt, ?Entity $shootingEntity = null){
		$this->large26 = isset($nbt->Large26) && (bool) $nbt['Large26'];
		$this->damage = $this->large26 ? 8.0 : 7.0;
		parent::__construct($chunk, $nbt, $shootingEntity);
		$this->setOnFire(30);
	}

	public function getName(){ return $this->large26 ? 'Large Fireball' : 'Small Fireball'; }
	public function isLarge26() : bool{ return $this->large26; }

	public function onUpdate($currentTick){
		if($this->closed){ return false; }
		// The legacy projectile collider inspects nearby chunks. Refuse its whole
		// query footprint first, including the next motion endpoint.
		$margin = 5.0;
		if($this->age > 200 || $this->level->getServer()->getDifficulty() === 0
			|| !Environment26::loadedBox($this->level,
				min($this->x, $this->x + $this->motionX) - $margin,
				min($this->z, $this->z + $this->motionZ) - $margin,
				max($this->x, $this->x + $this->motionX) + $margin,
				max($this->z, $this->z + $this->motionZ) + $margin)
			|| !Environment26::loadedEntityQuery($this->level,
				min($this->x, $this->x + $this->motionX) - 1.3,
				min($this->z, $this->z + $this->motionZ) - 1.3,
				max($this->x, $this->x + $this->motionX) + 1.3,
				max($this->z, $this->z + $this->motionZ) + 1.3)){
			$this->close();
			return false;
		}
		$result = parent::onUpdate($currentTick);
		if($this->hadCollision || !$this->isAlive()){
			if($this->large26 && !$this->exploded26){
				$this->exploded26 = true;
				if(Environment26::explosionLoaded($this, 1.0)){
					$explosion = new Explosion(new Position($this->x, $this->y, $this->z, $this->level), 1.0, $this);
					if(!empty($this->level->getServer()->aiConfig['creeperexplode'])){ $explosion->explodeA(); }
					$explosion->explodeB();
				}
			}
			$this->close();
			return false;
		}
		return $result;
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Large26 = new ByteTag('Large26', $this->large26 ? 1 : 0);
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->type = Snowball::NETWORK_ID;
		$pk->eid = $this->getId();
		$pk->x = $this->x; $pk->y = $this->y; $pk->z = $this->z;
		$pk->speedX = $this->motionX; $pk->speedY = $this->motionY; $pk->speedZ = $this->motionZ;
		$pk->yaw = $this->yaw; $pk->pitch = $this->pitch;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);
		parent::spawnTo($player);
	}
}
