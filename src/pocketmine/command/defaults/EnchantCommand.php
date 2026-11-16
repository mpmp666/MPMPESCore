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

namespace pocketmine\command\defaults;


use pocketmine\command\CommandSender;
use pocketmine\entity\Effect;
use pocketmine\entity\InstantEffect;
use pocketmine\event\TranslationContainer;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\utils\TextFormat;

class EnchantCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"%pocketmine.command.enchant.description",
			"%commands.enchant.usage"
		);
		$this->setPermission("pocketmine.command.enchant");
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}

		if(count($args) < 2){
			$sender->sendMessage(new TranslationContainer("commands.generic.usage", [$this->usageMessage]));

			return true;
		}

		$player = $sender->getServer()->getPlayer($args[0]);

		if($player === null){
			$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.player.notFound"));
			return true;
		}

		if(count($args) > 3){
			$sender->sendMessage(new TranslationContainer("commands.generic.usage", [$this->usageMessage]));
			return true;
		}

		$enchantment = Enchantment::fromString($args[1]);
		if($enchantment->getId() === Enchantment::TYPE_INVALID){
			$sender->sendMessage(new TranslationContainer("commands.enchant.notFound", [$args[1]]));
			return true;
		}
		$levelArg = $args[2] ?? "1";
		if(!preg_match('/^[1-9][0-9]*$/D', (string) $levelArg) || (int) $levelArg > $enchantment->getMaxLevel()){
			$sender->sendMessage(TextFormat::RED . "Enchantment level must be between 1 and " . $enchantment->getMaxLevel() . ".");
			return true;
		}
		$enchantment->setLevel((int) $levelArg);

		$item = $player->getInventory()->getItemInHand();
		if($item->getId() <= 0){
			$sender->sendMessage(new TranslationContainer("commands.enchant.noItem"));
			return true;
		}
		if(!Enchantment::canEnchantItem($item, $enchantment, false)){
			$sender->sendMessage(TextFormat::RED . "That enchantment cannot be applied to this item.");
			return true;
		}
		foreach($item->getEnchantments() as $existing){
			if($existing->getId() !== $enchantment->getId() && !$enchantment->isCompatibleWith($existing)){
				$sender->sendMessage(TextFormat::RED . "That enchantment is incompatible with an enchantment on this item.");
				return true;
			}
		}

		if($item->getId() === \pocketmine\item\Item::BOOK){
			$item = \pocketmine\item\Item::get(\pocketmine\item\Item::ENCHANTED_BOOK, $item->getDamage(), $item->getCount(), $item->getCompoundTag());
		}
		$item->addEnchantment($enchantment);
		$player->getInventory()->setItemInHand($item);


		self::broadcastCommandMessage($sender, new TranslationContainer("%commands.enchant.success"));
		return true;
	}
}