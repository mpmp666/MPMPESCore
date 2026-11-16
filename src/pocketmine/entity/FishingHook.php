<?php

namespace pocketmine\entity;

use pocketmine\event\player\PlayerFishEvent;
use pocketmine\level\format\FullChunk;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\item\Item as ItemItem;
use pocketmine\network\protocol\EntityEventPacket;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Server;

class FishingHook extends Projectile{
	const NETWORK_ID = 77;

	public $width = 0.25;
	public $length = 0.25;
	public $height = 0.25;

	protected $gravity = 0.1;
	protected $drag = 0.05;

	public $data = 0;
	public $attractTimer = 100;
	public $coughtTimer = 0;
	public $damageRod = false;
	private $lure = 0;
	private $luck = 0;

	public function initEntity(){
		parent::initEntity();

		if(isset($this->namedtag->Data)){
			$this->data = $this->namedtag["Data"];
		}

		// $this->setDataProperty(FallingSand::DATA_BLOCK_INFO, self::DATA_TYPE_INT, $this->getData());
	}

	public function __construct(FullChunk $chunk, CompoundTag $nbt, ?Entity $shootingEntity = null){
		parent::__construct($chunk, $nbt, $shootingEntity);
		if($shootingEntity instanceof Player){
			$rod = $shootingEntity->getInventory()->getItemInHand();
			$this->lure = $rod->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_FISHING_LURE);
			$this->luck = $rod->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_FISHING_FORTUNE);
		}
		$this->attractTimer = \pocketmine\item\EnchantmentEffects26::fishingWait(mt_rand(100, 600), $this->lure);
	}

	public function setData($id){
		$this->data = $id;
	}

	public function getData(){
		return $this->data;
	}

	public function onUpdate($currentTick){
		if($this->closed){
			return false;
		}

		$this->timings->startTiming();

		$hasUpdate = parent::onUpdate($currentTick);

		if($this->isCollidedVertically && $this->isInsideOfWater()){
			$this->motionX = 0;
			$this->motionY += 0.01;
			$this->motionZ = 0;
			$this->motionChanged = true;
			$hasUpdate = true;
		}elseif($this->isCollided && $this->keepMovement === true){
			$this->motionX = 0;
			$this->motionY = 0;
			$this->motionZ = 0;
			$this->motionChanged = true;
			$this->keepMovement = false;
			$hasUpdate = true;
		}
		if($this->isInsideOfWater() && $this->attractTimer === 0 && mt_rand(0, 100) <= 30){ // chance, that a fish bites
			$this->coughtTimer = mt_rand(5, 10) * 20; // random delay to catch fish
			$this->attractTimer = \pocketmine\item\EnchantmentEffects26::fishingWait(mt_rand(100, 600), $this->lure); // reset timer
			$this->attractFish();
			if($this->shootingEntity instanceof Player) $this->shootingEntity->sendTip("A fish bites!");
		}elseif($this->isInsideOfWater() && $this->attractTimer > 0){
			$this->attractTimer--;
		}
		if($this->coughtTimer > 0){
			$this->coughtTimer--;
			$this->fishBites();
		}

		$this->timings->stopTiming();

		return $hasUpdate;
	}

	public function fishBites(){
		if($this->shootingEntity instanceof Player){
			$pk = new EntityEventPacket();
			$pk->eid = $this->shootingEntity->getId();//$this or $this->shootingEntity
			$pk->event = EntityEventPacket::FISH_HOOK_HOOK;
			Server::broadcastPacket($this->shootingEntity->hasSpawned, $pk);
		}
	}

	public function attractFish(){
		if($this->shootingEntity instanceof Player){
			$pk = new EntityEventPacket();
			$pk->eid = $this->shootingEntity->getId();//$this or $this->shootingEntity
			$pk->event = EntityEventPacket::FISH_HOOK_BUBBLE;
			Server::broadcastPacket($this->shootingEntity->hasSpawned, $pk);
		}
	}

	/** Modern category weights over the items available to this legacy protocol. */
	public function catchItem(){
		$open = true;
		for($x = -2; $x <= 2; ++$x){ for($z = -2; $z <= 2; ++$z){
			if(!$this->getLevel()->isChunkLoaded(((int) floor($this->x + $x)) >> 4, ((int) floor($this->z + $z)) >> 4)){ $open = false; continue; }
			$water = $this->getLevel()->getBlock($this->add($x, 0, $z))->getId();
			$above = $this->getLevel()->getBlock($this->add($x, 1, $z))->getId();
			if(!in_array($water, [8, 9], true) or !in_array($above, [0, 111], true)){ $open = false; }
		} }
		$weights = \pocketmine\item\EnchantmentEffects26::fishingWeights($this->luck, $open);
		$roll = mt_rand(1, array_sum($weights));
		if($roll <= $weights[0]){
			$junk = [111, 301, 334, 352, 373, 287, 346, 281, 280, 351, 131, 367];
			$junkWeights = [17, 10, 10, 10, 10, 5, 2, 10, 5, 1, 10, 10];
			$pick = mt_rand(1, array_sum($junkWeights));
			foreach($junk as $i => $id){ if(($pick -= $junkWeights[$i]) <= 0){ return ItemItem::get($id, 0, $id === 351 ? 10 : 1); } }
		}elseif($roll <= $weights[0] + $weights[1]){
			// Name tags and nautilus shells have no registered item in this core.
			$treasures = [ItemItem::SADDLE, ItemItem::BOW, ItemItem::FISHING_ROD, ItemItem::BOOK];
			$item = ItemItem::get($treasures[mt_rand(0, 3)]);
			if($item->getId() !== ItemItem::SADDLE){
				$offers = \pocketmine\item\enchantment\EnchantmentLevelTable::generateOffers($item, 15, mt_rand(0, 0x7fffffff));
				if($item->getId() === ItemItem::BOOK){ $item = ItemItem::get(ItemItem::ENCHANTED_BOOK); }
				if(isset($offers[2])){ foreach($offers[2]->getEnchantments() as $enchantment){ $item->addEnchantment($enchantment); } }
			}
			return $item;
		}
		$fish = mt_rand(1, 100);
		return ItemItem::get($fish <= 60 ? ItemItem::RAW_FISH : ($fish <= 85 ? ItemItem::RAW_SALMON : ($fish <= 87 ? ItemItem::CLOWN_FISH : ItemItem::PUFFER_FISH)));
	}

	public function reelLine(){
		$this->damageRod = false;

		if($this->shootingEntity instanceof Player && $this->coughtTimer > 0){
			$item = $this->catchItem();
			$this->getLevel()->getServer()->getPluginManager()->callEvent($ev = new PlayerFishEvent($this->shootingEntity, $item, $this));
			if(!$ev->isCancelled()){
				$overflow = $this->shootingEntity->getInventory()->addItem($item);
				foreach($overflow as $extra){ $this->getLevel()->dropItem($this->shootingEntity, $extra); }
				if($this->shootingEntity->isSurvival()){
					$rod = $this->shootingEntity->getInventory()->getItemInHand();
					if($rod->getId() === ItemItem::FISHING_ROD){
						\pocketmine\item\EnchantmentEffects26::damageItem($rod, 1);
						$this->shootingEntity->getInventory()->setItemInHand($rod->getDamage() >= 65 ? ItemItem::get(ItemItem::AIR) : $rod);
					}
				}
				$this->shootingEntity->addExperience(mt_rand(1, 6));
				$this->damageRod = true;
			}
		}

		if($this->shootingEntity instanceof Player){
			$this->shootingEntity->unlinkHookFromPlayer();
		}

		if(!$this->closed){
			$this->kill();
			$this->close();
		}

		return $this->damageRod;
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = FishingHook::NETWORK_ID;
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
}
