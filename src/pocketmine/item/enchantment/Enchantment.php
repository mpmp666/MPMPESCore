<?php

/*
 * PocketMine legacy enchantment IDs, with Minecraft 26.2 rules.
 * The numeric IDs are part of the old client protocol and must not be renumbered.
 */
namespace pocketmine\item\enchantment;

use pocketmine\item\Armor;
use pocketmine\item\Item;
use pocketmine\item\Tool;

class Enchantment{
	const TYPE_INVALID = -1;
	const TYPE_ARMOR_PROTECTION = 0;
	const TYPE_ARMOR_FIRE_PROTECTION = 1;
	const TYPE_ARMOR_FALL_PROTECTION = 2;
	const TYPE_ARMOR_EXPLOSION_PROTECTION = 3;
	const TYPE_ARMOR_PROJECTILE_PROTECTION = 4;
	const TYPE_ARMOR_THORNS = 5;
	const TYPE_WATER_BREATHING = 6;
	const TYPE_WATER_SPEED = 7;
	const TYPE_WATER_AFFINITY = 8;
	const TYPE_WEAPON_SHARPNESS = 9;
	const TYPE_WEAPON_SMITE = 10;
	const TYPE_WEAPON_ARTHROPODS = 11;
	const TYPE_WEAPON_KNOCKBACK = 12;
	const TYPE_WEAPON_FIRE_ASPECT = 13;
	const TYPE_WEAPON_LOOTING = 14;
	const TYPE_MINING_EFFICIENCY = 15;
	const TYPE_MINING_SILK_TOUCH = 16;
	const TYPE_MINING_DURABILITY = 17;
	const TYPE_MINING_FORTUNE = 18;
	const TYPE_BOW_POWER = 19;
	const TYPE_BOW_KNOCKBACK = 20;
	const TYPE_BOW_FLAME = 21;
	const TYPE_BOW_INFINITY = 22;
	const TYPE_FISHING_FORTUNE = 23;
	const TYPE_FISHING_LURE = 24;

	const RARITY_COMMON = 0;
	const RARITY_UNCOMMON = 1;
	const RARITY_RARE = 2;
	const RARITY_MYTHIC = 3;
	const ACTIVATION_EQUIP = 0;
	const ACTIVATION_HELD = 1;
	const ACTIVATION_SELF = 2;

	const SLOT_NONE = 0;
	const SLOT_ALL = 0b11111111111111;
	const SLOT_ARMOR = 0b1111;
	const SLOT_HEAD = 0b1;
	const SLOT_TORSO = 0b10;
	const SLOT_LEGS = 0b100;
	const SLOT_FEET = 0b1000;
	const SLOT_SWORD = 0b10000;
	const SLOT_BOW = 0b100000;
	const SLOT_HOE = 0b1000000;
	const SLOT_SHEARS = 0b10000000;
	const SLOT_FLINT_AND_STEEL = 0b100000000;
	const SLOT_AXE = 0b1000000000;
	const SLOT_PICKAXE = 0b10000000000;
	const SLOT_SHOVEL = 0b100000000000;
	const SLOT_FISHING_ROD = 0b1000000000000;
	const SLOT_CARROT_STICK = 0b10000000000000;
	const SLOT_DIG = self::SLOT_AXE | self::SLOT_PICKAXE | self::SLOT_SHOVEL;
	const SLOT_TOOL = self::SLOT_DIG | self::SLOT_HOE | self::SLOT_SHEARS | self::SLOT_FLINT_AND_STEEL;

	public static $words = ["the", "elder", "scrolls", "klaatu", "berata", "niktu", "xyzzy", "bless", "curse", "light", "darkness", "fire", "air", "earth", "water", "hot", "dry", "cold", "wet", "ignite", "snuff", "embiggen", "twist", "shorten", "stretch", "fiddle", "destroy", "imbue", "galvanize", "enchant", "free", "limited", "range", "of", "towards", "inside", "sphere", "cube", "self", "other", "ball", "mental", "physical", "grow", "shrink", "demon", "elemental", "spirit", "animal", "creature", "beast", "humanoid", "undead", "fresh", "stale"];

	/** name, max level, weight, min base, min increment, max base, max increment, anvil cost, supported slots, primary slots. */
	private const DATA = [
		0 => ['protection', 4, 10, 1, 11, 12, 11, 1, self::SLOT_ARMOR, self::SLOT_ARMOR],
		1 => ['fire_protection', 4, 5, 10, 8, 18, 8, 2, self::SLOT_ARMOR, self::SLOT_ARMOR],
		2 => ['feather_falling', 4, 5, 5, 6, 11, 6, 2, self::SLOT_FEET, self::SLOT_FEET],
		3 => ['blast_protection', 4, 2, 5, 8, 13, 8, 4, self::SLOT_ARMOR, self::SLOT_ARMOR],
		4 => ['projectile_protection', 4, 5, 3, 6, 9, 6, 2, self::SLOT_ARMOR, self::SLOT_ARMOR],
		5 => ['thorns', 3, 1, 10, 20, 60, 20, 8, self::SLOT_ARMOR, self::SLOT_TORSO],
		6 => ['respiration', 3, 2, 10, 10, 40, 10, 4, self::SLOT_HEAD, self::SLOT_HEAD],
		7 => ['depth_strider', 3, 2, 10, 10, 25, 10, 4, self::SLOT_FEET, self::SLOT_FEET],
		8 => ['aqua_affinity', 1, 2, 1, 0, 41, 0, 4, self::SLOT_HEAD, self::SLOT_HEAD],
		9 => ['sharpness', 5, 10, 1, 11, 21, 11, 1, self::SLOT_SWORD | self::SLOT_AXE, self::SLOT_SWORD],
		10 => ['smite', 5, 5, 5, 8, 25, 8, 2, self::SLOT_SWORD | self::SLOT_AXE, self::SLOT_SWORD],
		11 => ['bane_of_arthropods', 5, 5, 5, 8, 25, 8, 2, self::SLOT_SWORD | self::SLOT_AXE, self::SLOT_SWORD],
		12 => ['knockback', 2, 5, 5, 20, 55, 20, 2, self::SLOT_SWORD, self::SLOT_SWORD],
		13 => ['fire_aspect', 2, 2, 10, 20, 60, 20, 4, self::SLOT_SWORD, self::SLOT_SWORD],
		14 => ['looting', 3, 2, 15, 9, 65, 9, 4, self::SLOT_SWORD, self::SLOT_SWORD],
		15 => ['efficiency', 5, 10, 1, 10, 51, 10, 1, self::SLOT_DIG | self::SLOT_HOE | self::SLOT_SHEARS, self::SLOT_DIG | self::SLOT_HOE | self::SLOT_SHEARS],
		16 => ['silk_touch', 1, 1, 15, 0, 65, 0, 8, self::SLOT_DIG | self::SLOT_HOE, self::SLOT_DIG | self::SLOT_HOE],
		17 => ['unbreaking', 3, 5, 5, 8, 55, 8, 2, self::SLOT_ALL, self::SLOT_ALL],
		18 => ['fortune', 3, 2, 15, 9, 65, 9, 4, self::SLOT_DIG | self::SLOT_HOE, self::SLOT_DIG | self::SLOT_HOE],
		19 => ['power', 5, 10, 1, 10, 16, 10, 1, self::SLOT_BOW, self::SLOT_BOW],
		20 => ['punch', 2, 2, 12, 20, 37, 20, 4, self::SLOT_BOW, self::SLOT_BOW],
		21 => ['flame', 1, 2, 20, 0, 50, 0, 4, self::SLOT_BOW, self::SLOT_BOW],
		22 => ['infinity', 1, 1, 20, 0, 50, 0, 8, self::SLOT_BOW, self::SLOT_BOW],
		23 => ['luck_of_the_sea', 3, 2, 15, 9, 65, 9, 4, self::SLOT_FISHING_ROD, self::SLOT_FISHING_ROD],
		24 => ['lure', 3, 2, 15, 9, 65, 9, 4, self::SLOT_FISHING_ROD, self::SLOT_FISHING_ROD]
	];

	/** @var Enchantment[]|\SplFixedArray */
	protected static $enchantments;

	public static function init(){
		self::$enchantments = new \SplFixedArray(256);
		$oldNames = ['protect.all', 'protect.fire', 'protect.fall', 'protect.explosion', 'protect.projectile', 'protect.thorns', 'protect.waterbrething', 'waterspeed', 'protect.wateraffinity', 'weapon.sharpness', 'weapon.smite', 'weapon.arthropods', 'weapon.knockback', 'weapon.fireaspect', 'weapon.looting', 'mining.efficiency', 'mining.silktouch', 'mining.durability', 'mining.fortune', 'bow.power', 'bow.knockback', 'bow.flame', 'bow.infinity', 'fishing.fortune', 'fishing.lure'];
		foreach(self::DATA as $id => $data){
			$rarities = [10 => self::RARITY_COMMON, 5 => self::RARITY_UNCOMMON, 2 => self::RARITY_RARE, 1 => self::RARITY_MYTHIC];
			self::$enchantments[$id] = new Enchantment($id, '%enchantment.' . $oldNames[$id], $rarities[$data[2]], $id <= 8 ? self::ACTIVATION_EQUIP : self::ACTIVATION_HELD, $data[8]);
		}
	}

	/** Legacy numeric lookup. Malformed selectors never become protection (ID 0). */
	public static function getEnchantment($id){
		if(self::$enchantments === null){ self::init(); }
		if((is_int($id) || (is_string($id) && preg_match('/^[0-9]+$/D', $id))) && (int) $id >= 0 && (int) $id < 256 && isset(self::$enchantments[(int) $id])){
			return clone self::$enchantments[(int) $id];
		}
		return new Enchantment(self::TYPE_INVALID, 'unknown', 0, 0, self::SLOT_NONE);
	}

	/** Modern names, minecraft: names, legacy TYPE_ suffixes, and exact numeric IDs. */
	public static function fromString($name){
		if(is_int($name) || (is_string($name) && preg_match('/^[0-9]+$/D', $name))){ return self::getEnchantment($name); }
		if(!is_string($name)){ return self::getEnchantment(self::TYPE_INVALID); }
		$name = strtolower(trim($name));
		if(strpos($name, 'minecraft:') === 0){ $name = substr($name, 10); }
		foreach(self::DATA as $id => $data){ if($name === $data[0]){ return self::getEnchantment($id); } }
		$legacy = self::class . '::TYPE_' . strtoupper($name);
		return defined($legacy) ? self::getEnchantment(constant($legacy)) : self::getEnchantment(self::TYPE_INVALID);
	}

	public static function getEffectByName($name){
		$result = self::fromString($name);
		return $result->getId() === self::TYPE_INVALID ? null : $result;
	}

	/** Enchanting-table ENCHANTABLE component. Shears/flint-and-steel only accept books/anvils. */
	public static function getEnchantAbility(Item $item){
		if(in_array($item->getId(), [Item::BOOK, Item::BOW, Item::FISHING_ROD], true)){ return 1; }
		if($item instanceof Armor){
			$values = [Armor::TIER_LEATHER => 15, Armor::TIER_GOLD => 25, Armor::TIER_CHAIN => 12, Armor::TIER_IRON => 9, Armor::TIER_DIAMOND => 10];
			return $values[$item->getArmorTier()] ?? 0;
		}
		foreach(['isSword', 'isAxe', 'isPickaxe', 'isShovel', 'isHoe'] as $method){
			$tier = $item->$method();
			if($tier !== false){
				$values = [Tool::TIER_WOODEN => 15, Tool::TIER_STONE => 5, Tool::TIER_IRON => 14, Tool::TIER_DIAMOND => 10, Tool::TIER_GOLD => 22];
				return $values[$tier] ?? 0;
			}
		}
		return 0;
	}

	public static function getEnchantWeight(int $enchantmentId){ return self::DATA[$enchantmentId][2] ?? 0; }
	public static function getEnchantMaxLevel(int $enchantmentId){ return self::DATA[$enchantmentId][1] ?? 0; }

	/** Inclusive [minimum, maximum] enchantability, or [0, 0] for an invalid level/ID. */
	public static function getEnchantMinMaxAbility(int $enchantmentId, int $level = 1) : array{
		if(!isset(self::DATA[$enchantmentId]) || $level < 1 || $level > self::getEnchantMaxLevel($enchantmentId)){ return [0, 0]; }
		$data = self::DATA[$enchantmentId];
		return [$data[3] + ($level - 1) * $data[4], $data[5] + ($level - 1) * $data[6]];
	}

	/** $primary=true applies enchanting-table primary tags; false applies command/anvil supported tags. */
	public static function canEnchantItem(Item $item, $enchantment, bool $primary = false) : bool{
		$id = $enchantment instanceof self ? $enchantment->getId() : self::getEnchantment($enchantment)->getId();
		if(!isset(self::DATA[$id]) || $item->getId() === Item::AIR || $item->getCount() <= 0){ return false; }
		if($item->getId() === Item::BOOK || $item->getId() === Item::ENCHANTED_BOOK){ return true; }
		return (self::DATA[$id][$primary ? 9 : 8] & self::getItemSlot($item)) !== 0;
	}

	private static function getItemSlot(Item $item) : int{
		if($item->isArmor()){
			if($item->isHelmet()){ return self::SLOT_HEAD; }
			if($item->isChestplate()){ return self::SLOT_TORSO; }
			if($item->isBoots()){ return self::SLOT_FEET; }
			return self::SLOT_LEGS;
		}
		foreach(['isSword' => self::SLOT_SWORD, 'isAxe' => self::SLOT_AXE, 'isPickaxe' => self::SLOT_PICKAXE, 'isShovel' => self::SLOT_SHOVEL, 'isHoe' => self::SLOT_HOE, 'isShears' => self::SLOT_SHEARS] as $method => $slot){
			if($item->$method() !== false){ return $slot; }
		}
		$slots = [Item::BOW => self::SLOT_BOW, Item::FISHING_ROD => self::SLOT_FISHING_ROD, Item::FLINT_STEEL => self::SLOT_FLINT_AND_STEEL, 398 => self::SLOT_CARROT_STICK];
		return $slots[$item->getId()] ?? self::SLOT_NONE;
	}

	public function isCompatibleWith(Enchantment $other) : bool{
		$a = $this->id; $b = $other->getId();
		if(!isset(self::DATA[$a], self::DATA[$b]) || $a === $b){ return false; }
		foreach([[0, 1, 3, 4], [9, 10, 11], [16, 18]] as $exclusive){
			if(in_array($a, $exclusive, true) && in_array($b, $exclusive, true)){ return false; }
		}
		return true;
	}

	private $id;
	private $level = 1;
	private $name;
	private $rarity;
	private $activationType;
	private $slot;

	private function __construct($id, $name, $rarity, $activationType, $slot){
		$this->id = (int) $id; $this->name = (string) $name; $this->rarity = (int) $rarity;
		$this->activationType = (int) $activationType; $this->slot = (int) $slot;
	}
	public function getId(){ return $this->id; }
	public function getName() : string{ return $this->name; }
	public function getRarity(){ return $this->rarity; }
	public function getActivationType(){ return $this->activationType; }
	public function getSlot(){ return $this->slot; }
	public function hasSlot($slot){ return ($this->slot & $slot) > 0; }
	public function getLevel(){ return $this->level; }
	public function getMaxLevel(){ return self::getEnchantMaxLevel($this->id); }
	public function getAnvilCost(){ return self::DATA[$this->id][7] ?? 0; }
	public function getIdentifier() : string{ return isset(self::DATA[$this->id]) ? 'minecraft:' . self::DATA[$this->id][0] : 'unknown'; }

	/** Intentionally unrestricted for plugin/admin NBT compatibility. Normal acquisition validates separately. */
	public function setLevel(int $level){ $this->level = $level; return $this; }
	public function equals(Enchantment $ent){ return $ent->getId() === $this->id && $ent->getLevel() === $this->level && $ent->getActivationType() === $this->activationType && $ent->getRarity() === $this->rarity; }
	public static function generateName(){
		$set = [];
		for($i = mt_rand(3, 6); $i > 0; --$i){ $set[] = self::$words[mt_rand(0, count(self::$words) - 1)]; }
		return implode(' ', $set);
	}
}
