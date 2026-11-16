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

namespace pocketmine\item;


class Bow extends Tool{
	public function __construct($meta = 0, $count = 1){
		parent::__construct(self::BOW, $meta, $count, "Bow");
	}

	public static function drawPower(int $ticks) : float{
		$p = max(0, $ticks) / 20.0;
		return min(1.0, ($p * $p + 2 * $p) / 3);
	}

	public function applyEnchantments(\pocketmine\entity\Arrow $arrow, bool $creative = false){
		$arrow->setBaseDamage(2.0 + EnchantmentEffects26::powerBonus($this->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_BOW_POWER)));
		$arrow->setPunch($this->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_BOW_KNOCKBACK));
		if($this->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_BOW_FLAME) > 0){ $arrow->setOnFire(100); }
		$arrow->setPickupAllowed(!$creative and $this->consumesArrow(false));
	}

	public function consumesArrow(bool $creative) : bool{
		return !$creative && $this->getEnchantmentLevel(\pocketmine\item\enchantment\Enchantment::TYPE_BOW_INFINITY) <= 0;
	}

	public function useOn($object, $type = 1){
		EnchantmentEffects26::damageItem($this, 1);
		return true;
	}
}
