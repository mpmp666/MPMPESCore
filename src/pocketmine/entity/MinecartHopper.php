<?php

/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Sunch233#3226 QQ2125696621 And KKK
 * @link https://github.com/ScaxeTeam/Scaxe/
 *
 */

namespace pocketmine\entity;

use pocketmine\inventory\HopperInventory;
use pocketmine\inventory\InventoryHolder;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;

class MinecartHopper extends Minecart implements InventoryHolder{
	const NETWORK_ID = 96;

	/** @var HopperInventory */
	protected $inventory;

	/** @var int */
	protected $transferCooldown = 0;

	public function initEntity(){
		parent::initEntity();
		$this->inventory = new HopperInventory($this);
		if(isset($this->namedtag->Items) and $this->namedtag->Items instanceof ListTag){
			for($i = 0; $i < $this->getSize(); ++$i){
				$this->inventory->setItem($i, $this->getItem($i));
			}
		}
	}

	public function getName() : string{
		return "Minecart Hopper";
	}

	public function getType() : int{
		return self::TYPE_HOPPER;
	}

	public function getSize(){
		return 5;
	}

	public function getItem($index){
		$i = $this->getSlotIndex($index);
		if($i < 0){
			return Item::get(Item::AIR, 0, 0);
		}else{
			return $this->namedtag->Items[$i];
		}
	}

	public function setItem($index, Item $item){
		$i = $this->getSlotIndex($index);

		if($item->getId() === Item::AIR or $item->getCount() <= 0){
			if($i >= 0){
				unset($this->namedtag->Items[$i]);
			}
		}elseif($i < 0){
			for($i = 0; $i <= $this->getSize(); ++$i){
				if(!isset($this->namedtag->Items[$i])){
					break;
				}
			}
			$this->namedtag->Items[$i] = $item;
		}else{
			$this->namedtag->Items[$i] = $item;
		}
		return true;
	}

	protected function getSlotIndex($index){
		if(!isset($this->namedtag->Items) or !($this->namedtag->Items instanceof ListTag)){
			$this->namedtag->Items = new ListTag("Items", []);
			$this->namedtag->Items->setTagType(\pocketmine\nbt\NBT::TAG_Compound);
		}
		foreach($this->namedtag->Items as $i => $slot){
			if((int) $slot["Slot"] === (int) $index){
				return (int) $i;
			}
		}
		return -1;
	}

	public function getInventory(){
		return $this->inventory;
	}

	public function canUpdate(){
		return $this->transferCooldown === 0;
	}

	public function resetCooldownTicks(){
		$this->transferCooldown = 8;
	}

	public function onUpdate($currentTick){
		$hasUpdate = parent::onUpdate($currentTick);

		if($this->closed !== false){
			return $hasUpdate;
		}

		// Decrease transfer cooldown
		if($this->transferCooldown > 0){
			--$this->transferCooldown;
		}

		// Hopper logic runs every 8 ticks when not on cooldown
		if($this->canUpdate()){
			// Suck items from above (chest, hopper, etc.)
			$blockAbove = $this->getLevel()->getBlock($this->add(0, 1, 0));
			$tileAbove = $this->getLevel()->getTile($blockAbove);
			if($tileAbove instanceof \pocketmine\tile\Tile and $tileAbove instanceof InventoryHolder){
				$inv = $tileAbove->getInventory();
				$item = clone $inv->getItem($inv->firstOccupied());
				$item->setCount(1);
				if($this->inventory->canAddItem($item)){
					$this->inventory->addItem($item);
					$inv->removeItem($item);
					$this->resetCooldownTicks();
				}
			}

			// Also suck dropped items above the minecart
			$area = $this->getBoundingBox()->expandedCopy(0.5, 1.5, 0.5);
			foreach($this->getLevel()->getChunkEntities($this->chunk->x, $this->chunk->z) as $entity){
				if(!($entity instanceof \pocketmine\entity\Item)){
					continue;
				}
				if(!$entity->boundingBox->intersectsWith($area)){
					continue;
				}
				$item = $entity->getItem();
				if(!$item instanceof Item or $item->getCount() < 1){
					$entity->kill();
					continue;
				}
				if($this->inventory->canAddItem($item)){
					$this->inventory->addItem($item);
					$entity->kill();
				}
			}

			// Push items to container below (chest, hopper, furnace, etc.)
			$blockBelow = $this->getLevel()->getBlock($this->add(0, -1, 0));
			$tileBelow = $this->getLevel()->getTile($blockBelow);
			if($tileBelow instanceof \pocketmine\tile\Tile and $tileBelow instanceof InventoryHolder and !($tileBelow instanceof \pocketmine\tile\Hopper)){
				$inv = $tileBelow->getInventory();
				foreach($this->inventory->getContents() as $item){
					if($item->getId() === Item::AIR or $item->getCount() < 1){
						continue;
					}
					$targetItem = clone $item;
					$targetItem->setCount(1);
					if($inv->canAddItem($targetItem)){
						$inv->addItem($targetItem);
						$this->inventory->removeItem($targetItem);
						$this->resetCooldownTicks();
						break;
					}
				}
			}
		}

		return $hasUpdate or !$this->onGround or abs($this->motionX) > 0.00001 or abs($this->motionY) > 0.00001 or abs($this->motionZ) > 0.00001;
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Items = new ListTag("Items", []);
		$this->namedtag->Items->setTagType(\pocketmine\nbt\NBT::TAG_Compound);
		for($index = 0; $index < $this->getSize(); ++$index){
			$item = $this->inventory->getItem($index);
			if($item->getId() !== Item::AIR){
				$this->setItem($index, $item);
			}
		}
		if($this->transferCooldown > 0){
			$this->namedtag->TransferCooldown = new \pocketmine\nbt\tag\IntTag("TransferCooldown", $this->transferCooldown);
		}
	}

	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = MinecartHopper::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = 0;
		$pk->speedY = 0;
		$pk->speedZ = 0;
		$pk->yaw = 0;
		$pk->pitch = 0;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);
	}
}