<?php

namespace pocketmine\item\enchantment;

use pocketmine\item\Item;
use pocketmine\level\generator\ender\noise\JavaRandom;

/** Minecraft 26.2 EnchantmentHelper restricted to the 25 legacy wire IDs. */
class EnchantmentLevelTable{
	// Preserve the source non_treasure tag's order, rather than the different old wire order.
	private const TABLE_IDS = [0, 1, 2, 3, 4, 6, 8, 5, 7, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24];

	public static function init(){ /* Metadata is kept in Enchantment, avoiding two divergent tables. */ }

	/** At most one (highest applicable) level per enchantment. All bounds are inclusive. */
	public static function getPossibleEnchantments(Item $item, int $modifiedLevel){
		$result = [];
		foreach(self::TABLE_IDS as $id){
			if(!Enchantment::canEnchantItem($item, $id, true)){ continue; }
			for($level = Enchantment::getEnchantMaxLevel($id); $level >= 1; --$level){
				[$min, $max] = Enchantment::getEnchantMinMaxAbility($id, $level);
				if($modifiedLevel >= $min && $modifiedLevel <= $max){
					$result[] = Enchantment::getEnchantment($id)->setLevel($level);
					break;
				}
			}
		}
		return $result;
	}

	/** Source EnchantmentHelper.getEnchantmentCost; each option draws its own base. */
	public static function getEnchantmentCost(JavaRandom $random, int $slot, int $bookshelves, Item $item) : int{
		if($slot < 0 || $slot > 2 || Enchantment::getEnchantAbility($item) <= 0){ return 0; }
		$bookshelves = max(0, min(15, $bookshelves));
		$selected = $random->nextInt(8) + 1 + ($bookshelves >> 1) + $random->nextInt($bookshelves + 1);
		if($slot === 0){ return max(intdiv($selected, 3), 1); }
		return $slot === 1 ? intdiv($selected * 2, 3) + 1 : max($selected, $bookshelves * 2);
	}

	/** Optional explicit Java random source makes source-derived fixtures reproducible. */
	public static function selectEnchantments(Item $item, int $cost, ?JavaRandom $random = null) : array{
		$ability = Enchantment::getEnchantAbility($item);
		if($ability <= 0 || $cost <= 0){ return []; }
		$random = $random ?? new JavaRandom(mt_rand(0, 0x7fffffff));
		$cost += 1 + $random->nextInt(intdiv($ability, 4) + 1) + $random->nextInt(intdiv($ability, 4) + 1);
		// Java rounds each float arithmetic step to float32.
		$span = self::f32(self::f32(self::f32($random->nextFloat() + $random->nextFloat()) - 1.0) * self::f32(0.15));
		$cost = max(1, (int) floor(self::f32($cost + self::f32($cost * $span)) + 0.5));
		$possible = self::getPossibleEnchantments($item, $cost);
		if(count($possible) === 0){ return []; }
		$result = [self::chooseWeighted($possible, $random)];
		while($random->nextInt(50) <= $cost){
			$last = $result[count($result) - 1];
			$possible = array_values(array_filter($possible, function(Enchantment $candidate) use ($last){ return $last->isCompatibleWith($candidate); }));
			if(count($possible) === 0){ break; }
			$result[] = self::chooseWeighted($possible, $random);
			$cost = intdiv($cost, 2);
		}
		return $result;
	}

	/** Three stable offers for a seed/item/bookshelf snapshot, as EnchantmentMenu does. */
	public static function generateOffers(Item $item, int $bookshelves, int $seed) : array{
		if($item->getCount() !== 1 || $item->hasEnchantments() || Enchantment::getEnchantAbility($item) <= 0){ return []; }
		$random = new JavaRandom($seed);
		$costs = [];
		for($slot = 0; $slot < 3; ++$slot){
			$cost = self::getEnchantmentCost($random, $slot, $bookshelves, $item);
			$costs[$slot] = $cost >= $slot + 1 ? $cost : 0;
		}
		$entries = [];
		for($slot = 0; $slot < 3; ++$slot){
			$random->setSeed($seed + $slot);
			$list = $costs[$slot] > 0 ? self::selectEnchantments($item, $costs[$slot], $random) : [];
			if($item->getId() === Item::BOOK && count($list) > 1){ array_splice($list, $random->nextInt(count($list)), 1); }
			$entries[$slot] = new EnchantmentEntry($list, count($list) > 0 ? $costs[$slot] : 0, 'bless', $slot + 1);
		}
		return $entries;
	}

	private static function chooseWeighted(array $candidates, JavaRandom $random) : Enchantment{
		$total = 0;
		foreach($candidates as $candidate){ $total += Enchantment::getEnchantWeight($candidate->getId()); }
		$choice = $random->nextInt($total);
		foreach($candidates as $candidate){
			$choice -= Enchantment::getEnchantWeight($candidate->getId());
			if($choice < 0){ return clone $candidate; }
		}
		throw new \LogicException('Invalid enchantment weights');
	}

	private static function f32(float $value) : float{ return unpack('fvalue', pack('f', $value))['value']; }
}
