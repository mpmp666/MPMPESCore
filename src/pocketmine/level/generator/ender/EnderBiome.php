<?php

namespace pocketmine\level\generator\ender;

use pocketmine\level\generator\biome\Biome;

class EnderBiome extends Biome
{

	public function getName(): string
	{
		return "Ender";
	}

	public function getColor(): int
	{
		return 0x1a0b2e;
	}
}