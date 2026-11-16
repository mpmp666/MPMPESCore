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

namespace pocketmine\item\enchantment;


class EnchantmentEntry{

	/** @var Enchantment[] */
	private $enchantments;
	private $cost;
	private $randomName;
	private $resourceCost;

	/**
	 * @param Enchantment[] $enchantments
	 * @param $cost
	 * @param $randomName
	 */
	public function __construct(array $enchantments, $cost, $randomName, $resourceCost = 1){
		$this->enchantments = array_map(function(Enchantment $enchantment){ return clone $enchantment; }, $enchantments);
		$this->cost = (int) $cost;
		$this->randomName = (string) $randomName;
		$this->resourceCost = (int) $resourceCost;
	}

	public function getEnchantments(){
		return array_map(function(Enchantment $enchantment){ return clone $enchantment; }, $this->enchantments);
	}

	public function getCost(){
		return $this->cost;
	}

	/** Lapis and levels consumed (1, 2, 3), separate from the displayed level requirement. */
	public function getResourceCost(){ return $this->resourceCost; }

	public function getRandomName(){
		return $this->randomName;
	}

}