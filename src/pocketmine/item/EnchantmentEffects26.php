<?php

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\entity\Arrow;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityCombustByEntityEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\Player;

/**
 * Gameplay formulas for the existing Bedrock enchantment IDs 0..24.
 *
 * Reference: MCP-Reborn 26.2, Enchantments.bootstrap(), EnchantmentHelper,
 * LivingEntity.decreaseAirSupply(), Player.getDestroySpeed(), AbstractArrow,
 * ApplyBonusCount and EnchantedCountIncreaseFunction. No modern IDs are added.
 * Damage event modifiers in this core are MULTIPLIERS, not additive reductions.
 */
final class EnchantmentEffects26{
	public static function sharpnessBonus(int $level) : float{
		return $level > 0 ? 0.5 * ($level + 1) : 0.0;
	}

	public static function powerBonus(int $level) : float{
		return self::sharpnessBonus($level);
	}

	public static function family(Entity $entity) : string{
		if($entity instanceof \pocketmine\entity\Zombie or $entity instanceof \pocketmine\entity\ZombieVillager or
			$entity instanceof \pocketmine\entity\PigZombie or $entity instanceof \pocketmine\entity\Skeleton){
			return "undead";
		}
		if($entity instanceof \pocketmine\entity\Spider or $entity instanceof \pocketmine\entity\CaveSpider or
			$entity instanceof \pocketmine\entity\Silverfish){
			return "arthropod";
		}
		return "other";
	}

	public static function meleeBonus(Item $weapon, string $family) : float{
		$bonus = self::sharpnessBonus($weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_SHARPNESS));
		if($family === "undead"){
			$bonus += 2.5 * $weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_SMITE);
		}elseif($family === "arthropod"){
			$bonus += 2.5 * $weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_ARTHROPODS);
		}
		return $bonus;
	}

	/** Only Humans have an armor inventory in this legacy entity model. */
	public static function armor(Entity $entity) : array{
		return $entity instanceof Human && $entity->getInventory() !== null ? $entity->getInventory()->getArmorContents() : [];
	}

	public static function helmetLevel(Entity $entity, int $id) : int{
		if($entity instanceof Human and $entity->getInventory() !== null){
			$helmet = $entity->getInventory()->getHelmet();
			if($helmet->isArmor() and $helmet->isHelmet()){
				return $helmet->getEnchantmentLevel($id);
			}
		}
		return 0;
	}

	public static function armorLevel(Entity $entity, int $id) : int{
		$level = 0;
		foreach(self::armor($entity) as $item){
			if($item->isArmor()){
				$level += $item->getEnchantmentLevel($id);
			}
		}
		return $level;
	}

	public static function protectionPoints(array $armor, int $cause) : int{
		// The legacy causes corresponding to bypass-invulnerability damage.
		if($cause === EntityDamageEvent::CAUSE_VOID or $cause === EntityDamageEvent::CAUSE_SUICIDE or $cause === EntityDamageEvent::CAUSE_STARVATION){
			return 0;
		}
		$points = 0;
		foreach($armor as $item){
			if(!$item->isArmor()){
				continue;
			}
			$points += $item->getEnchantmentLevel(Enchantment::TYPE_ARMOR_PROTECTION);
			switch($cause){
				case EntityDamageEvent::CAUSE_FIRE:
				case EntityDamageEvent::CAUSE_FIRE_TICK:
				case EntityDamageEvent::CAUSE_LAVA:
					$points += 2 * $item->getEnchantmentLevel(Enchantment::TYPE_ARMOR_FIRE_PROTECTION);
					break;
				case EntityDamageEvent::CAUSE_FALL:
					$points += 3 * $item->getEnchantmentLevel(Enchantment::TYPE_ARMOR_FALL_PROTECTION);
					break;
				case EntityDamageEvent::CAUSE_BLOCK_EXPLOSION:
				case EntityDamageEvent::CAUSE_ENTITY_EXPLOSION:
					$points += 2 * $item->getEnchantmentLevel(Enchantment::TYPE_ARMOR_EXPLOSION_PROTECTION);
					break;
				case EntityDamageEvent::CAUSE_PROJECTILE:
					$points += 2 * $item->getEnchantmentLevel(Enchantment::TYPE_ARMOR_PROJECTILE_PROTECTION);
					break;
			}
		}
		return min(20, max(0, $points));
	}

	public static function protectionMultiplier(int $points) : float{
		return 1.0 - min(20, max(0, $points)) * 0.04;
	}

	public static function durabilityDamageChance(int $level, bool $armor = false) : float{
		$chance = 1.0 / (max(0, $level) + 1);
		return $armor ? 0.6 + 0.4 * $chance : $chance;
	}

	/** One independent roll per durability point (including two-point tool uses). */
	public static function shouldDamageDurability(int $level, bool $armor = false, float $roll = -1.0) : bool{
		if($roll < 0){
			$roll = mt_rand() / (mt_getrandmax() + 1.0);
		}
		return $roll < self::durabilityDamageChance($level, $armor);
	}

	public static function damageItem(Item $item, int $amount) : int{
		if(method_exists($item, "isUnbreakable") and $item->isUnbreakable()){
			return 0;
		}
		$lost = 0;
		$level = $item->getEnchantmentLevel(Enchantment::TYPE_MINING_DURABILITY);
		for($i = 0; $i < $amount; ++$i){
			if(self::shouldDamageDurability($level, $item->isArmor())){
				++$lost;
			}
		}
		$item->setDamage($item->getDamage() + $lost);
		return $lost;
	}

	public static function consumeAir(int $level, float $roll = -1.0) : bool{
		return self::shouldDamageDurability($level, false, $roll);
	}

	public static function efficiencySpeed(float $baseSpeed, int $level) : float{
		return $baseSpeed > 1 && $level > 0 ? $baseSpeed + $level * $level + 1 : $baseSpeed;
	}

	public static function miningPenalty(bool $underwater, bool $onGround, bool $aquaAffinity) : float{
		return ($underwater && !$aquaAffinity ? 5.0 : 1.0) * ($onGround ? 1.0 : 5.0);
	}

	/** Legacy movement-attribute adaptation; airborne contribution is halved. */
	public static function waterMovementScale(int $level, bool $onGround) : float{
		return 1.0 + min(3, max(0, $level)) / 3.0 * ($onGround ? 1.0 : 0.5);
	}

	public static function toolSpeed(Block $block, Item $item) : float{
		$type = $block->getToolType();
		if($item->isShears() and ($type === Tool::TYPE_SHEARS or $block->getId() === Block::COBWEB)){
			return 15.0;
		}
		$tier = false;
		if($type === Tool::TYPE_PICKAXE){ $tier = $item->isPickaxe(); }
		elseif($type === Tool::TYPE_AXE){ $tier = $item->isAxe(); }
		elseif($type === Tool::TYPE_SHOVEL){ $tier = $item->isShovel(); }
		if($tier !== false){
			$speeds = [Tool::TIER_WOODEN => 2, Tool::TIER_STONE => 4, Tool::TIER_IRON => 6, Tool::TIER_DIAMOND => 8, Tool::TIER_GOLD => 12];
			return isset($speeds[$tier]) ? $speeds[$tier] : 1.0;
		}
		if($item->isSword() and $block->getId() === Block::COBWEB){ return 15.0; }
		return 1.0;
	}

	public static function breakTime(Block $block, Item $item, ?Player $player = null) : float{
		$hardness = $block->getHardness();
		if($hardness < 0){ return INF; }
		$speed = self::efficiencySpeed(self::toolSpeed($block, $item), $item->getEnchantmentLevel(Enchantment::TYPE_MINING_EFFICIENCY));
		if($player !== null){
			if($player->hasEffect(Effect::HASTE)){ $speed *= 1 + 0.2 * ($player->getEffect(Effect::HASTE)->getAmplifier() + 1); }
			if($player->hasEffect(Effect::MINING_FATIGUE)){
				$fatigue = [0.3, 0.09, 0.0027, 0.00081];
				$speed *= $fatigue[min(3, max(0, $player->getEffect(Effect::MINING_FATIGUE)->getAmplifier()))];
			}
			$speed /= self::miningPenalty($player->isInsideOfWater(), $player->isOnGround(), self::helmetLevel($player, Enchantment::TYPE_WATER_AFFINITY) > 0);
		}
		return $hardness * (self::correctHarvestTool($block, $item) ? 1.5 : 5.0) / max(0.000001, $speed);
	}

	public static function heldWeapon(Entity $entity){
		return $entity instanceof Human && $entity->getInventory() !== null ? $entity->getInventory()->getItemInHand() : null;
	}

	/** Called before the single cancellable damage event dispatch in Entity::attack. */
	public static function prepareDamage(Living $victim, EntityDamageEvent $event){
		if($event instanceof EntityDamageByEntityEvent and !($event instanceof EntityDamageByChildEntityEvent) and $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
			$weapon = self::heldWeapon($event->getDamager());
			if($weapon !== null){
				$event->setDamage($event->getDamage() + self::meleeBonus($weapon, self::family($victim)));
				$event->setKnockBack($event->getKnockBack() + 0.5 * $weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_KNOCKBACK));
			}
		}
	}

	/** Effects must never occur for cancelled/zero-damage/invulnerability-rejected hits. */
	public static function afterDamage(Living $victim, EntityDamageEvent $event){
		if($event->isCancelled() or $event->getFinalDamage() <= 0 or !($event instanceof EntityDamageByEntityEvent)){
			return;
		}
		$attacker = $event->getDamager();
		if(!($event instanceof EntityDamageByChildEntityEvent) and $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
			$weapon = self::heldWeapon($attacker);
			if($weapon !== null){
				$fire = $weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_FIRE_ASPECT);
				if($fire > 0 and $victim->isAlive()){
					$combust = new EntityCombustByEntityEvent($attacker, $victim, 4 * $fire);
					$victim->getLevel()->getServer()->getPluginManager()->callEvent($combust);
					if(!$combust->isCancelled()){ $victim->setOnFire($combust->getDuration()); }
				}
				$bane = $weapon->getEnchantmentLevel(Enchantment::TYPE_WEAPON_ARTHROPODS);
				if($bane > 0 and self::family($victim) === "arthropod" and $victim->isAlive()){
					$effect = Effect::getEffect(Effect::SLOWNESS);
					$victim->addEffect($effect->setAmplifier(3)->setDuration(mt_rand(30, 30 + 10 * ($bane - 1))));
				}
			}
		}elseif($event instanceof EntityDamageByChildEntityEvent and $event->getChild() instanceof Arrow){
			$event->getChild()->applyPunch($victim);
		}
		// Reflected magic uses a plain event to prevent recursive thorns retaliation.
		foreach(self::armor($victim) as $slot => $armor){
			$thorns = $armor->getEnchantmentLevel(Enchantment::TYPE_ARMOR_THORNS);
			if($armor->isArmor() and $thorns > 0 and $attacker->isAlive() and mt_rand() / (mt_getrandmax() + 1.0) < min(1.0, 0.15 * $thorns)){
				$retaliation = new EntityDamageEvent($attacker, EntityDamageEvent::CAUSE_MAGIC, mt_rand(1, 4));
				$attacker->attack($retaliation->getFinalDamage(), $retaliation);
				if(!($victim instanceof Player) or $victim->isSurvival()){
					self::damageItem($armor, 2);
					if($armor->getDamage() >= $armor->getMaxDurability()){ $armor->setCount(0); }
					$victim->getInventory()->setArmorItem($slot, $armor);
				}
			}
		}
	}

	public static function oreCount(int $count, int $level, int $roll = -1) : int{
		if($level <= 0){ return $count; }
		if($roll < 0){ $roll = mt_rand(0, $level + 1); }
		return $count * (max(0, $roll - 1) + 1);
	}

	/** The source rounds a continuous uniform sample, rather than uniform integers. */
	public static function lootingBonus(int $level, float $roll = -1.0) : int{
		if($roll < 0){ $roll = mt_rand() / (mt_getrandmax() + 1.0); }
		return (int) floor(max(0, $level) * $roll + 0.5);
	}

	public static function lootingDrops(Living $victim, array $drops, int $level) : array{
		if($level <= 0 or $victim instanceof Human){ return $drops; }
		$supported = [];
		if($victim instanceof \pocketmine\entity\Zombie or $victim instanceof \pocketmine\entity\ZombieVillager){ $supported = [Item::ROTTEN_FLESH]; }
		elseif($victim instanceof \pocketmine\entity\PigZombie){ $supported = [Item::ROTTEN_FLESH, Item::GOLD_NUGGET]; }
		elseif($victim instanceof \pocketmine\entity\Skeleton){ $supported = [Item::BONE, Item::ARROW]; }
		elseif($victim instanceof \pocketmine\entity\Spider or $victim instanceof \pocketmine\entity\CaveSpider){ $supported = [Item::STRING]; }
		elseif($victim instanceof \pocketmine\entity\Cow or $victim instanceof \pocketmine\entity\Mooshroom){ $supported = [Item::LEATHER, Item::RAW_BEEF, Item::COOKED_BEEF]; }
		elseif($victim instanceof \pocketmine\entity\Pig){ $supported = [Item::RAW_PORKCHOP, Item::COOKED_PORKCHOP]; }
		elseif($victim instanceof \pocketmine\entity\Chicken){ $supported = [Item::FEATHER, Item::RAW_CHICKEN, Item::COOKED_CHICKEN]; }
		elseif($victim instanceof \pocketmine\entity\Sheep){ $supported = [423, 424]; }
		elseif($victim instanceof \pocketmine\entity\Rabbit){ $supported = [Item::RABBIT_HIDE, Item::RAW_RABBIT, Item::COOKED_RABBIT]; }
		elseif($victim instanceof \pocketmine\entity\Squid){ $supported = [Item::DYE]; }
		elseif($victim instanceof \pocketmine\entity\LavaSlime){ $supported = [Item::MAGMA_CREAM]; }
		elseif($victim instanceof \pocketmine\entity\Slime){ $supported = [Item::SLIMEBALL]; }
		elseif($victim instanceof \pocketmine\entity\Creeper){ $supported = [Item::GUNPOWDER]; }
		elseif($victim instanceof \pocketmine\entity\Enderman){ $supported = [368]; }
		elseif($victim instanceof \pocketmine\entity\Blaze){ $supported = [Item::BLAZE_ROD]; }
		elseif($victim instanceof \pocketmine\entity\Ghast){ $supported = [Item::GHAST_TEAR, Item::GUNPOWDER]; }
		foreach($drops as $key => $drop){
			if($drop instanceof Item and in_array($drop->getId(), $supported, true) and !$drop->hasCompoundTag()){
				$drops[$key] = clone $drop;
				$drops[$key]->setCount($drop->getCount() + self::lootingBonus($level));
			}
		}
		return $drops;
	}

	public static function correctHarvestTool(Block $block, Item $item) : bool{
		if($block->getToolType() !== Tool::TYPE_PICKAXE){ return true; }
		$required = 1;
		if(in_array($block->getId(), [Block::DIAMOND_ORE, Block::EMERALD_ORE, Block::GOLD_ORE, Block::REDSTONE_ORE, Block::GLOWING_REDSTONE_ORE], true)){ $required = Tool::TIER_IRON; }
		elseif(in_array($block->getId(), [Block::IRON_ORE, Block::LAPIS_ORE], true)){ $required = Tool::TIER_STONE; }
		elseif($block->getId() === Block::OBSIDIAN){ $required = Tool::TIER_DIAMOND; }
		return $item->isPickaxe() !== false && $item->isPickaxe() >= $required;
	}

	/** Apply loot-table formulas once, before BlockBreakEvent allows plugin overrides. */
	public static function harvestDrops(Block $block, Item $tool) : array{
		$id = $block->getId();
		$level = $tool->getEnchantmentLevel(Enchantment::TYPE_MINING_FORTUNE);
		$silk = $tool->getEnchantmentLevel(Enchantment::TYPE_MINING_SILK_TOUCH) > 0;
		$supported = [16, 21, 56, 129, 153, 73, 74, 89, 103, 59, 141, 142];
		if(!in_array($id, $supported, true) or $silk){
			$raw = $block->getDrops($tool);
		}else{
			// Strip ONLY fortune on a copy; existing block formulas must not run twice.
			$plain = Item::get($tool->getId(), $tool->getDamage(), $tool->getCount(), $tool->getCompoundTag());
			$tag = $plain->getNamedTag();
			if($tag !== null and isset($tag->ench) and $tag->ench instanceof \pocketmine\nbt\tag\ListTag){
				$entries = [];
				foreach($tag->ench as $entry){ if($entry instanceof \pocketmine\nbt\tag\CompoundTag and (int) $entry['id'] !== Enchantment::TYPE_MINING_FORTUNE){ $entries[] = $entry; } }
				$tag->ench = new \pocketmine\nbt\tag\ListTag('ench', $entries);
				$tag->ench->setTagType(\pocketmine\nbt\NBT::TAG_Compound);
				$plain->setNamedTag($tag);
			}
			$raw = $block->getDrops($plain);
			foreach($raw as &$drop){
				if(in_array($id, [16, 21, 56, 129, 153], true)){ $drop[2] = self::oreCount((int) $drop[2], $level); }
				elseif(in_array($id, [73, 74], true)){ $drop[2] += mt_rand(0, max(0, $level)); }
				elseif($id === 89){ $drop[2] = min(4, $drop[2] + mt_rand(0, max(0, $level))); }
				elseif($id === 103){ $drop[2] = min(9, $drop[2] + mt_rand(0, max(0, $level))); }
			}
			unset($drop);
			if($block->getDamage() >= 7 and in_array($id, [59, 141, 142], true)){
				$count = $id === 59 ? 0 : 2;
				for($i = 0; $i < 3 + $level; ++$i){ if(mt_rand() / (mt_getrandmax() + 1.0) < 4.0 / 7.0){ ++$count; } }
				if($id === 59){ $raw = [[Item::WHEAT, 0, 1], [Item::WHEAT_SEEDS, 0, $count]]; }
				else{ $raw = [[$id === 141 ? Item::CARROT : Item::POTATO, 0, $count]]; }
			}
		}
		$result = [];
		foreach($raw as $drop){ $result[] = $drop instanceof Item ? $drop : Item::get($drop[0], $drop[1], $drop[2]); }
		return $result;
	}

	public static function fishingWait(int $baseTicks, int $lure) : int{
		return max(1, $baseTicks - 100 * max(0, $lure));
	}

	public static function fishingWeights(int $luck, bool $openWater) : array{
		return [max(0, 10 - 2 * $luck), $openWater ? max(0, 5 + 2 * $luck) : 0, max(0, 85 - $luck)];
	}
}
