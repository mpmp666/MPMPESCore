<?php

namespace pocketmine\inventory;

use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\item\Tool;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\level\Position;
use pocketmine\Player;

/** Server-owned, paid output for the legacy container protocol. */
class AnvilInventory extends ContainerInventory{
	private $resultPaid = false;
	private $outputMutation = false;
	private $processing = false;
	private $closing = false;
	private $deferredClose = null;

	public function __construct(Position $pos){ parent::__construct(new FakeBlockMenu($this, $pos), InventoryType::get(InventoryType::ANVIL)); }
	public function getHolder(){ return $this->holder; }
	public function setItem($index, Item $item){
		if((int) $index === 2 && !$this->outputMutation){ return false; }
		return parent::setItem($index, $item);
	}
	public function clear($index){
		if((int) $index === 2 && !$this->outputMutation){ return false; }
		return parent::clear($index);
	}

	/** Only the name is client input; every other output field must match the calculation. */
	public function onAnvil(Player $who, Item $requested){
		if($this->closing || $this->processing || $this->resultPaid || $requested->getId() === Item::AIR || $who->isSpectator() || $this->getItem(2)->getId() !== Item::AIR){ return $this->reject($who); }
		$input = $this->getItem(0); $addition = $this->getItem(1);
		$name = $requested->hasCustomName() ? $requested->getCustomName() : ($input->hasCustomName() ? '' : null);
		$calculated = self::calculateResult($input, $addition, $name, $who->isCreative());
		if($calculated === null || !self::matchesClientResult($calculated['item'], $requested)){ return $this->reject($who); }
		$cost = $calculated['cost']; $level = $who->getExpLevel();
		if(!$who->isCreative() && $level < $cost){ return $this->reject($who); }
		$this->processing = true;
		try{
			if(!$who->isCreative() && !$who->setExpLevel($level - $cost)){ return $this->reject($who); }
			if(!self::sameItem($input, $this->getItem(0)) || !self::sameItem($addition, $this->getItem(1))){
				if(!$who->isCreative()){ $who->setExpLevel($level); }
				return $this->reject($who);
			}
			$remaining = self::copyItem($addition);
			$remaining->setCount(max(0, $addition->getCount() - $calculated['consumed']));
			$this->outputMutation = true;
			try{
				$this->clear(0); $this->setItem(1, $remaining); $this->setItem(2, $calculated['item']);
				$this->resultPaid = true;
			}finally{ $this->outputMutation = false; }
		}finally{
			$this->processing=false;
			if($this->deferredClose!==null){$closingPlayer=$this->deferredClose;$this->deferredClose=null;$this->onClose($closingPlayer);}
		}
		$this->sendContents($who); $who->updateExperience();
		return true;
	}

	/** Slot 2 -> AIR transfers the already-paid output exactly once, server-side. */
	public function takeResult(Player $who){
		if($this->closing || $this->processing || !$this->resultPaid || $this->getItem(2)->getId() === Item::AIR){ return $this->reject($who); }
		$result = $this->getItem(2); $inventory = $who->getInventory();
		// Results are one item. Deliver to one slot and honor its cancellable
		// setItem(), instead of BaseInventory::addItem() which can report an empty
		// remainder even when an inventory-change plugin rejected the write.
		$destination = null; $replacement = null;
		for($slot=0,$limit=$inventory->getSize()-$inventory->getHotbarSize();$slot<$limit;++$slot){
			$current=$inventory->getItem($slot);
			if($current->getId()===Item::AIR){$destination=$slot;$replacement=self::copyItem($result);break;}
			if($current->equals($result,true,true)&&$current->getCount()+$result->getCount()<=min($current->getMaxStackSize(),$inventory->getMaxStackSize())){
				$destination=$slot;$replacement=self::copyItem($current);$replacement->setCount($current->getCount()+$result->getCount());break;
			}
		}
		if($destination===null){return $this->reject($who);}
		$this->processing = true;
		try{
			// Clear first so inventory callbacks cannot take the same result recursively.
			$this->outputMutation = true;
			try{ $this->clear(2); $this->resultPaid = false; }finally{ $this->outputMutation = false; }
			try{$delivered=$inventory->setItem($destination,$replacement);}
			catch(\Throwable $error){$this->restorePaidResult($result);throw $error;}
			if(!$delivered){$this->restorePaidResult($result);return $this->reject($who);}
		}finally{
			$this->processing=false;
			if($this->deferredClose!==null){$closingPlayer=$this->deferredClose;$this->deferredClose=null;$this->onClose($closingPlayer);}
		}
		$this->sendContents($who); $inventory->sendContents($who);
		return true;
	}

	private function restorePaidResult(Item $result){
		$this->outputMutation=true;
		try{$this->setItem(2,$result);$this->resultPaid=true;}finally{$this->outputMutation=false;}
	}

	/** Preview only: never creates an unpaid result slot. */
	public function getCalculatedResult(Player $who, ?string $customName = null){ return self::calculateResult($this->getItem(0), $this->getItem(1), $customName, $who->isCreative()); }

	/** 26.2 AnvilMenu.createResult restricted to available legacy items/enchantments. */
	public static function calculateResult(Item $input, Item $addition, ?string $customName = null, bool $creative = false){
		if($input->getId() === Item::AIR || $input->getCount() !== 1){ return null; }
		$result = self::copyItem($input); $price = 0; $consumed = 0; $renameCost = 0;
		$tax = self::getRepairCost($input) + self::getRepairCost($addition);
		$priorWork = max(self::getRepairCost($input), self::getRepairCost($addition));
		$usingBook = $addition->getId() === Item::ENCHANTED_BOOK;
		$enchants = $input->getEnchantments();
		if($addition->getId() !== Item::AIR && $addition->getCount() > 0){
			$maxDamage = (int) $input->getMaxDurability();
			if($maxDamage > 0 && self::isRepairMaterial($input, $addition)){
				$damage = max(0, (int) $input->getDamage()); $unit = intdiv($maxDamage, 4);
				if($damage <= 0 || $unit <= 0){ return null; }
				while($damage > 0 && $consumed < $addition->getCount()){ $damage = max(0, $damage - $unit); ++$consumed; ++$price; }
				$result->setDamage($damage);
			}else{
				if(!$usingBook && ($input->getId() !== $addition->getId() || $maxDamage <= 0)){ return null; }
				if($addition->getCount() !== 1){ return null; }
				if(!$usingBook && $maxDamage > 0){
					$remaining = $maxDamage - (int) $input->getDamage() + $maxDamage - (int) $addition->getDamage() + intdiv($maxDamage * 12, 100);
					$damage = max(0, $maxDamage - $remaining);
					if($damage < (int) $input->getDamage()){ $result->setDamage($damage); $price += 2; }
				}
				$incoming = $addition->getEnchantments(); $accepted = 0; $rejected = 0;
				foreach($incoming as $incomingEnchant){
					$id = $incomingEnchant->getId();
					if($id < 0 || $incomingEnchant->getLevel() <= 0){ ++$rejected; continue; }
					$current = 0; $index = null;
					foreach($enchants as $i => $existing){ if($existing->getId() === $id){ $current = $existing->getLevel(); $index = $i; break; } }
					$level = min(Enchantment::getEnchantMaxLevel($id), $current === $incomingEnchant->getLevel() ? $current + 1 : max($current, $incomingEnchant->getLevel()));
					$compatible = $creative || $input->getId() === Item::ENCHANTED_BOOK || Enchantment::canEnchantItem($input, $id);
					foreach($enchants as $existing){ if($existing->getId() !== $id && !$incomingEnchant->isCompatibleWith($existing)){ $compatible = false; ++$price; } }
					if(!$compatible){ ++$rejected; continue; }
					$updated = Enchantment::getEnchantment($id)->setLevel($level);
					if($index === null){ $enchants[] = $updated; }else{ $enchants[$index] = $updated; }
					$fee = $updated->getAnvilCost(); if($usingBook){ $fee = max(1, intdiv($fee, 2)); }
					$price += $fee * $level; ++$accepted;
				}
				if($rejected > 0 && $accepted === 0){ return null; }
				$consumed = 1;
			}
		}
		if($customName !== null){
			if(!preg_match('//u', $customName) || mb_strlen($customName, 'UTF-8') > 50 || preg_match('/[\x00-\x1f\x7f]/u', $customName)){ return null; }
			if(trim($customName) === ''){
				if($input->hasCustomName()){ $result->clearCustomName(); $renameCost = 1; ++$price; }
			}elseif($customName !== $input->getName()){
				$result->setCustomName($customName); $renameCost = 1; ++$price;
			}
		}
		if($price <= 0){ return null; }
		$onlyRename = $renameCost > 0 && $renameCost === $price;
		$cost = min(0x7fffffff, $tax + $price);
		if($onlyRename){ $cost = min(39, $cost); $consumed = 0; }
		if($cost >= 40 && !$creative){ return null; }
		if(!$onlyRename){ self::setRepairCost($result, self::calculateIncreasedRepairCost($priorWork)); }
		elseif($priorWork > 0 || $input->getNamedTagEntry('RepairCost') !== null){ self::setRepairCost($result, $priorWork); }
		if($result->getId() === Item::BOOK && count($enchants) > 0){ $result = Item::get(Item::ENCHANTED_BOOK, $result->getDamage(), 1, $result->getCompoundTag()); }
		foreach($enchants as $enchantment){ $result->addEnchantment($enchantment); }
		return ['item' => $result, 'cost' => (int) $cost, 'consumed' => $consumed, 'rename_only' => $onlyRename];
	}

	public static function calculateIncreasedRepairCost(int $cost) : int{ return (int) min(max(0, $cost) * 2 + 1, 0x7fffffff); }
	private static function isRepairMaterial(Item $input, Item $addition) : bool{
		if($input->isArmor()){
			$map = [1 => Item::LEATHER, 2 => Item::GOLD_INGOT, 3 => Item::IRON_INGOT, 4 => Item::IRON_INGOT, 5 => Item::DIAMOND];
			return isset($map[$input->getArmorTier()]) && $addition->getId() === $map[$input->getArmorTier()];
		}
		foreach(['isSword', 'isAxe', 'isPickaxe', 'isShovel', 'isHoe'] as $method){
			$tier = $input->$method();
			if($tier !== false){
				$map = [Tool::TIER_WOODEN => Item::PLANKS, Tool::TIER_STONE => Item::COBBLESTONE, Tool::TIER_GOLD => Item::GOLD_INGOT, Tool::TIER_IRON => Item::IRON_INGOT, Tool::TIER_DIAMOND => Item::DIAMOND];
				return isset($map[$tier]) && $addition->getId() === $map[$tier];
			}
		}
		return false;
	}
	private static function getRepairCost(Item $item) : int{ $tag = $item->getNamedTagEntry('RepairCost'); return $tag instanceof IntTag ? max(0, $tag->getValue()) : 0; }
	private static function setRepairCost(Item $item, int $cost){ $tag = $item->getNamedTag() ?? new CompoundTag('', []); $tag->RepairCost = new IntTag('RepairCost', $cost); $item->setNamedTag($tag); }
	/** Old clients may omit server-owned prior-work metadata, but may not forge it. */
	private static function matchesClientResult(Item $expected, Item $requested) : bool{
		$provided=$requested->getNamedTagEntry('RepairCost');
		$computed=$expected->getNamedTagEntry('RepairCost');
		if($provided===null&&$computed!==null){
			$requested=self::copyItem($requested);
			$tag=$requested->getNamedTag()??new CompoundTag('',[]);
			$tag->RepairCost=clone $computed;
			$requested->setNamedTag($tag);
		}
		return self::sameItem($expected,$requested);
	}
	private static function sameItem(Item $a, Item $b) : bool{ return $a->getId() === $b->getId() && $a->getDamage() === $b->getDamage() && $a->getCount() === $b->getCount() && $a->deepEquals($b); }
	private static function copyItem(Item $item) : Item{ return Item::get($item->getId(), $item->getDamage(), $item->getCount(), $item->getCompoundTag()); }
	private function reject(Player $who){ $this->sendContents($who); $who->getInventory()->sendContents($who); $who->updateExperience(); return false; }
	public function onClose(Player $who){
		// Inventory/XP/packet callbacks may close this menu synchronously. Drain
		// its contents only once the paid result and inputs are consistent.
		if($this->closing){return;}
		if($this->processing){$this->deferredClose=$who;return;}
		$this->closing=true;
		parent::onClose($who);
		$level = $this->getHolder()->getLevel(); $this->outputMutation = true;
		try{
			for($slot = 0; $slot < 3; ++$slot){
				$item = $this->getItem($slot); $this->clear($slot);
				if($item->getId() !== Item::AIR && ($slot !== 2 || $this->resultPaid) && $level instanceof \pocketmine\level\Level){ $level->dropItem($this->getHolder()->add(0.5, 0.5, 0.5), $item); }
			}
		}finally{ $this->outputMutation = false; $this->resultPaid = false; }
		$who->updateExperience();
	}
}
