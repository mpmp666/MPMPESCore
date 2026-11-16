<?php

namespace pocketmine\inventory;

use pocketmine\item\Dye;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentEntry;
use pocketmine\item\enchantment\EnchantmentLevelTable;
use pocketmine\item\enchantment\EnchantmentList;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\network\protocol\CraftingDataPacket;
use pocketmine\Player;
use pocketmine\tile\EnchantTable;

/** The old client submits the result stack; only the server's seeded offer is accepted. */
class EnchantInventory extends ContainerInventory{
	private $bookshelfAmount = 0;
	private $levels = [];
	/** @var EnchantmentEntry[]|null */
	private $entries = null;
	/** @var Item|null */
	private $offerItem = null;
	private $enchantmentSeed = null;
	private $enchanting = false;
	private $owner = null;
	/** Player seeds survive closing/reopening a menu while the player is connected. */
	private static $playerSeeds = null;

	public function __construct(EnchantTable $tile){ parent::__construct($tile, InventoryType::get(InventoryType::ENCHANT_TABLE)); }
	public function getHolder(){ return $this->holder; }

	public function open(Player $who){
		if($this->owner !== null && $this->owner !== $who){ return false; }
		return parent::open($who);
	}

	public function onOpen(Player $who){
		$this->owner = $who;
		if(self::$playerSeeds === null){ self::$playerSeeds = new \WeakMap(); }
		if(!isset(self::$playerSeeds[$who])){ self::$playerSeeds[$who] = mt_rand(0, 0x7fffffff); }
		$this->enchantmentSeed = self::$playerSeeds[$who];
		parent::onOpen($who);
		$this->refreshOffers();
	}

	public function onSlotChange($index, $before){
		parent::onSlotChange($index, $before);
		if((int) $index === 0 && !$this->enchanting){ $this->refreshOffers(); }
	}

	private function ensureSeed(){ if($this->enchantmentSeed === null){ $this->enchantmentSeed = mt_rand(0, 0x7fffffff); } }
	public function getEnchantmentSeed() : int{ $this->ensureSeed(); return $this->enchantmentSeed; }

	private function refreshOffers(){
		$this->ensureSeed();
		$this->bookshelfAmount = max(0, min(15, $this->countBookshelf()));
		$item = $this->getItem(0);
		$this->offerItem = self::copyItem($item);
		$this->entries = EnchantmentLevelTable::generateOffers($item, $this->bookshelfAmount, $this->enchantmentSeed);
		$this->levels = [];
		foreach($this->entries as $slot => $entry){ $this->levels[$slot] = $entry->getCost(); }
		$this->sendEnchantmentList();
	}

	/** Exposed as immutable snapshots for integrations and deterministic fixture tests. */
	public function getEnchantmentEntries() : array{
		$result = [];
		foreach($this->entries ?? [] as $slot => $entry){ $result[$slot] = new EnchantmentEntry($entry->getEnchantments(), $entry->getCost(), $entry->getRandomName(), $entry->getResourceCost()); }
		return $result;
	}

	/** Exact multiset comparison, rejecting missing, extra, and duplicate enchantments. */
	public function checkEnts(array $ent1, array $ent2){
		if(count($ent1) !== count($ent2)){ return false; }
		$left = []; $right = [];
		foreach($ent1 as $e){ if(!$e instanceof Enchantment || isset($left[$e->getId()])){ return false; } $left[$e->getId()] = $e->getLevel(); }
		foreach($ent2 as $e){ if(!$e instanceof Enchantment || isset($right[$e->getId()])){ return false; } $right[$e->getId()] = $e->getLevel(); }
		ksort($left); ksort($right);
		return $left === $right;
	}

	/** Returns true only after applying one exact offer and paying its option cost. */
	public function onEnchant(Player $who, Item $before, Item $after){
		$current = $this->getItem(0);
		if($who->isSpectator() || ($this->owner !== null && $this->owner !== $who) || $this->enchanting || $current->getCount() !== 1 || $current->hasEnchantments() || !self::sameItem($before, $current) || $this->offerItem === null || !self::sameItem($current, $this->offerItem)){
			return $this->reject($who);
		}
		if($this->bookshelfAmount !== max(0, min(15, $this->countBookshelf()))){ $this->refreshOffers(); return $this->reject($who); }
		// Recompute, so plugins cannot mutate the issued entry objects into a different result.
		$offers = EnchantmentLevelTable::generateOffers($current, $this->bookshelfAmount, $this->getEnchantmentSeed());
		foreach($offers as $option => $entry){
			$enchants = $entry->getEnchantments();
			if($entry->getCost() <= 0 || count($enchants) === 0 || !$this->checkEnts($after->getEnchantments(), $enchants)){ continue; }
			$result = $current->getId() === Item::BOOK ? Item::get(Item::ENCHANTED_BOOK, 0, 1, $current->getCompoundTag()) : self::copyItem($current);
			foreach($enchants as $enchantment){ $result->addEnchantment($enchantment); }
			if(!self::sameItem($result, $after)){ continue; }
			$lapis = $this->getItem(1);
			$level = $who->getExpLevel();
			$cost = $option + 1;
			if(!$who->isCreative() && ($level < $entry->getCost() || $level < $cost || $lapis->getId() !== Item::DYE || $lapis->getDamage() !== Dye::BLUE || $lapis->getCount() < $cost)){ return $this->reject($who); }
			$this->enchanting = true;
			try{
				if(!$who->isCreative() && !$who->setExpLevel($level - $cost)){ return $this->reject($who); }
				// Experience callbacks may alter the container. Do not spend stale inputs.
				if(!self::sameItem($current, $this->getItem(0)) || !self::sameItem($lapis, $this->getItem(1))){
					if(!$who->isCreative()){ $who->setExpLevel($level); }
					return $this->reject($who);
				}
				if(!$who->isCreative()){
					$lapis->setCount($lapis->getCount() - $cost);
					$this->setItem(1, $lapis);
				}
				$this->setItem(0, $result);
				$this->enchantmentSeed = mt_rand(0, 0x7fffffff);
				if(self::$playerSeeds === null){ self::$playerSeeds = new \WeakMap(); }
				self::$playerSeeds[$who] = $this->enchantmentSeed;
				$this->entries = null; $this->offerItem = null; $this->levels = [];
			}finally{ $this->enchanting = false; }
			$this->refreshOffers();
			$this->sendContents($who);
			return true;
		}
		return $this->reject($who);
	}

	private function reject(Player $who){ $this->sendContents($who); $this->sendEnchantmentList(); $who->updateExperience(); return false; }

	public function countBookshelf(){
		$holder = $this->getHolder(); $level = $holder->getLevel();
		if(!$level instanceof Level){ return 0; }
		$count = 0; $x = (int) floor($holder->x); $y = (int) floor($holder->y); $z = (int) floor($holder->z);
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				if(abs($dx) !== 2 && abs($dz) !== 2){ continue; }
				for($dy = 0; $dy <= 1; ++$dy){
					if($level->getBlockIdAt($x + $dx, $y + $dy, $z + $dz) === Item::BOOKSHELF && $level->getBlockIdAt($x + intdiv($dx, 2), $y + $dy, $z + intdiv($dz, 2)) === Item::AIR && ++$count >= 15){ return 15; }
				}
			}
		}
		return $count;
	}

	public function sendEnchantmentList(){
		$pk = new CraftingDataPacket();
		$list = new EnchantmentList(count($this->entries ?? []));
		foreach($this->entries ?? [] as $slot => $entry){ $list->setSlot($slot, $entry); }
		$pk->addEnchantList($list);
		foreach($this->getViewers() as $viewer){ $viewer->dataPacket($pk); }
	}

	public function removeConflictEnchantment(Enchantment $enchantment, array $enchantments){
		return array_values(array_filter($enchantments, function(Enchantment $other) use ($enchantment){ return $enchantment->isCompatibleWith($other); }));
	}

	public function onClose(Player $who){
		parent::onClose($who);
		if($this->owner !== null && $this->owner !== $who){ return; }
		$level = $this->getHolder()->getLevel();
		$this->enchanting = true;
		try{
			for($slot = 0; $slot < 2; ++$slot){
				$item = $this->getItem($slot);
				if($item->getId() !== Item::AIR && $item->getCount() > 0){
					$this->clear($slot);
					if($level instanceof Level){ $level->dropItem($this->getHolder()->add(0.5, 0.5, 0.5), $item); }
				}
			}
		}finally{ $this->enchanting = false; }
		$this->entries = null; $this->offerItem = null; $this->levels = []; $this->bookshelfAmount = 0; $this->enchantmentSeed = null; $this->owner = null;
	}

	/** Item::deepEquals historically ignores ID/damage on its NBT fallback, so check them explicitly. */
	private static function sameItem(Item $a, Item $b) : bool{
		return $a->getId() === $b->getId() && $a->getDamage() === $b->getDamage() && $a->getCount() === $b->getCount() && $a->deepEquals($b);
	}
	private static function copyItem(Item $item) : Item{ return Item::get($item->getId(), $item->getDamage(), $item->getCount(), $item->getCompoundTag()); }
}
