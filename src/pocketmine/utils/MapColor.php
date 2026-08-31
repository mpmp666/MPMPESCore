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

declare(strict_types=1);

namespace pocketmine\utils;

use function count;
use function intdiv;
use pocketmine\block\Block;
use pocketmine\block\Stone;
use pocketmine\block\Planks;
use pocketmine\block\Wood;

final class MapColor{
	
	const COLOR_BLOCK_WHITE = 0;
	const COLOR_BLOCK_ORANGE = 1;
	const COLOR_BLOCK_MAGENTA = 2;
	const COLOR_BLOCK_LIGHT_BLUE = 3;
	const COLOR_BLOCK_YELLOW = 4;
	const COLOR_BLOCK_LIME = 5;
	const COLOR_BLOCK_PINK = 6;
	const COLOR_BLOCK_GRAY = 7;
	const COLOR_BLOCK_LIGHT_GRAY = 8;
	const COLOR_BLOCK_CYAN = 9;
	const COLOR_BLOCK_PURPLE = 8;
	const COLOR_BLOCK_BLUE = 11;
	const COLOR_BLOCK_BROWN = 12;
	const COLOR_BLOCK_GREEN = 13;
	const COLOR_BLOCK_RED = 14;
	const COLOR_BLOCK_BLACK = 15;

	/** @var int */
	protected $a;
	/** @var int */
	protected $r;
	/** @var int */
	protected $g;
	/** @var int */
	protected $b;

	public function __construct($r, $g, $b, $a = 0xff){
		$this->r = round($r) & 0xff;
		$this->g = round($g) & 0xff;
		$this->b = round($b) & 0xff;
		$this->a = round($a) & 0xff;
	}

	/**
	 * Returns the alpha (opacity) value of this colour.
	 */
	public function getA() : int{
		return $this->a;
	}

	/**
	 * Retuns the red value of this colour.
	 */
	public function getR() : int{
		return $this->r;
	}

	/**
	 * Returns the green value of this colour.
	 */
	public function getG() : int{
		return $this->g;
	}

	/**
	 * Returns the blue value of this colour.
	 */
	public function getB() : int{
		return $this->b;
	}

	/**
	 * Mixes the supplied list of colours together to produce a result colour.
	 *
	 * @param Color ...$colors
	 */
	public static function mix(Color $color1, Color ...$colors) : Color{
		$colors[] = $color1;
		$count = count($colors);

		$a = $r = $g = $b = 0;

		foreach($colors as $color){
			$a += $color->a;
			$r += $color->r;
			$g += $color->g;
			$b += $color->b;
		}

		return new MapColor(intdiv($r, $count), intdiv($g, $count), intdiv($b, $count), intdiv($a, $count));
	}

	/**
	 * Returns a Color from the supplied RGB colour code (24-bit)
	 */
	public static function fromRGB(int $code) : Color{
		return new MapColor(($code >> 16) & 0xff, ($code >> 8) & 0xff, $code & 0xff);
	}

	/**
	 * Returns a Color from the supplied ARGB colour code (32-bit)
	 */
	public static function fromARGB(int $code) : Color{
		return new MapColor(($code >> 16) & 0xff, ($code >> 8) & 0xff, $code & 0xff, (~($code >> 24) << 1) & 0xff);
	}

	/**
	 * Returns an ARGB 32-bit colour value.
	 */
	public function toARGB() : int{
		return ($this->a << 24) | ($this->r << 16) | ($this->g << 8) | $this->b;
	}

	/**
	 * Returns a Color from the supplied RGBA colour code (32-bit)
	 */
	public static function fromRGBA(int $c) : Color{
		return new MapColor(($c >> 24) & 0xff, ($c >> 16) & 0xff, ($c >> 8) & 0xff, $c & 0xff);
	}

	/**
	 * Returns an RGBA 32-bit colour value.
	 */
	public function toRGBA() : int{
		return ($this->r << 24) | ($this->g << 16) | ($this->b << 8) | $this->a;
	}
	
	public static function getMapColorByBlock(Block $block, $cid){
		$meta = $block->getDamage();
		$id = $block->getId();
		$str = $id.":".$meta;
		/*
		 * 生存斧服务器0.14.x核心
		 * 2.4b5 Sunch233
		 * 参考链接: 
		 * https://wiki.biligame.com/mc/%E5%9C%B0%E5%9B%BE%E7%89%A9%E5%93%81%E6%A0%BC%E5%BC%8F
		 */
		switch($str){
			case "0:0":
				$color = new MapColor(0, 0, 0, 0);
				break;
			case "2:0":
			case "165:0":
				$color = new MapColor(127, 178, 56); //GRASS
				break;
			case "12:0":
			case "17:2":
			case "5:2":
			case "85:2":
			case "184:0":
			case "135:0":
			case "158:2":
			case "194:0":
			case "24:0":
			case "24:1":
			case "24:2":
			case "89:0":
			case "121:0":
				$color = new MapColor(247, 233, 163); //SAND
				break;
			case "26:0":
			case "30:0":
			case "99:8":
				$color = new MapColor(127, 178, 56); //WOOL
				break;
			case "51:0":
			case "46:0":
			case "152:0":
			case "8:0":
			case "8:1":
			case "8:2":
			case "8:3":
			case "8:4":
			case "8:5":
			case "8:6":
			case "8:7":
			case "8:8":
			case "8:9":
			case "8:10":
			case "8:11":
			case "8:12":
			case "8:13":
			case "8:14":
			case "8:15":
			case "11:0":
				$color = new MapColor(255, 0, 0); //FIRE
				break;
			case "79:0":
			case "174:0":
				$color = new MapColor(160, 160, 255); //ICE
				break;
			case "71:0":
			case "379:0":
			case "42:0":
			case "167:0":
			case "145:0":
			case "145:4":
			case "145:8":
			case "148:0":
				$color = new MapColor(167, 167, 167); //METAL
				break;
			case "6:0":
			case "6:1":
			case "6:2":
			case "6:3":
			case "6:4":
			case "6:5":
			case "37:0":
			case "38:0":
			case "38:1":
			case "38:2":
			case "38:3":
			case "38:4":
			case "38:5":
			case "38:6":
			case "38:7":
			case "38:8":
			case "39:0":
			case "40:0":
			case "175:0":
			case "175:1":
			case "175:2":
			case "175:3":
			case "175:4":
			case "175:5":
			case "175:10":
			case "31:0":
			case "31:1":
			case "31:2":
			case "18:0":
			case "18:1":
			case "18:2":
			case "18:3":
			case "161:0":
			case "161:1":
			case "83:0":
			case "83:1":
			case "83:2":
			case "83:3":
			case "83:4":
			case "83:5":
			case "83:6":
			case "83:7":
			case "83:8":
			case "83:9":
			case "83:10":
			case "83:11":
			case "83:12":
			case "83:13":
			case "83:14":
			case "83:15":
			case "86:0":
			case "111:0":
			case "111:1":
			case "84:0":
			case "85:0":
			case "141:0":
			case "127:0":
			case "142:0":
			case "334:0": //?
			case "81:0":
				$color = new MapColor(0, 124, 0); //PLANT
				break;
			case "35:0":
			case "78:0":
			case "80:0":
				$color = new MapColor(255, 255, 255); //SNOW
				break;
			case "82:0":
				$color = new MapColor(164, 168, 184); //CLAY
				break;
			case "60:0":
			case "198:0":
			case "3:0":
			case "1:1":
			case "1:2":
			case "5:3":
			case "158:3":
			case "136:0":
			case "99:14":
			case "85:3":
			case "185:0":
				$color = new MapColor(151, 89, 77); //DIRT
				break;
			case "44:0":
			case "67:0":
			case "44:3":
			case "4:0":
			case "1:0":
			case "1:6":
			case "1:5":
			case "7:0":
			case "14:0":
			case "15:0":
			case "16:0":
			case "21:0":
			case "56:0":
			case "16:0":
			case "73:0":
			case "129:0":
			case "89:0":
			case "44:5":
			case "98:0":
			case "98:1":
			case "98:2":
			case "98:3":
			case "154:0":
			case "118:0":
			case "23:3":
			case "125:3":
				$color = new MapColor(112, 112, 112); //STONE
				break;
			case "8:0":
			case "8:1":
			case "8:2":
			case "8:3":
			case "8:4":
			case "8:5":
			case "8:6":
			case "8:7":
			case "8:8":
			case "8:9":
			case "8:10":
			case "8:11":
			case "8:12":
			case "8:13":
			case "8:14":
			case "8:15":
			case "9:0":
				$color = new MapColor(64, 64, 255); //WATER
				break;
			case "32:0":
			case "63:0":
			case "5:0":
			case "17:0":
			case "96:0":
			case "72:0":
			case "107:0":
			case "85:0":
			case "53:0":
			case "158:0":
			case "25:0":
			case "47:0":
			case "58:0":
			case "146:0":
			case "54:0":
			case "151:0":
				$color = new MapColor(143, 119, 72); //WOOD
				break;
			case "1:3":
			case "1:4":
			case "155:0":
			case "155:1":
			case "155:2":
			case "156:0":
			case "44:6":
				$color = new MapColor(255, 252, 245); //QUARTZ
				break;
			case "5:4":
			case "162:0":
			case "163:0":
			case "158:4":
			case "12:1":
			case "179:0":
			case "179:1":
			case "179:2":
			case "180:0":
			case "182:0":
			case "86:0":
			case "91:0":
			case "35:1":
			case "171:1":
			case "159:1":
			case "172:0":
				$color = new MapColor(216, 127, 51); //COLOR_ORANGE
				break;
			case "35:2":
			case "171:2":
				$color = new MapColor(178, 76, 216); //COLOR_MAGENTA
				break;
			case "35:3":
			case "171:3":
				$color = new MapColor(102, 153, 216); //COLOR_LIGHT_BLUE
				break;
			case "35:4":
			case "171:4":
			case "19:0":
			case "170:0":
				$color = new MapColor(229, 229, 51); //COLOR_YELLOW
				break;
			case "35:5":
			case "171:5":
			case "103:0":
				$color = new MapColor(127, 204, 25); //COLOR_LIGHT_GREEN
				break;
			case "35:6":
			case "171:6":
				$color = new MapColor(242, 127, 165); //COLOR_PINK
				break;
			case "35:7":
			case "171:7":
				$color = new MapColor(76, 76, 76); //COLOR_GRAY
				break;
			case "35:8":
			case "171:8":
				$color = new MapColor(153, 153, 153); //COLOR_LIGHT_GRAY
				break;
			case "35:9":
			case "171:9":
				$color = new MapColor(76, 127, 153); //COLOR_CYAN
				break;
			case "35:10":
			case "171:10":
			case "110:0":
				$color = new MapColor(127, 63, 178); //COLOR_PURPLE
				break;
			case "35:11":
			case "171:11":
				$color = new MapColor(51, 76, 178); //COLOR_BLUE
				break;
			case "35:12":
			case "171:12":
			case "197:0":
			case "5:5":
			case "162:1":
			case "164:0":
			case "158:5":
			case "85:5":
			case "186:0":
			case "88:0":
				$color = new MapColor(102, 76, 51); //COLOR_BROWN
				break;
			case "35:13":
			case "171:13":
			case "120:0":
				$color = new MapColor(51, 76, 178); //COLOR_GREEN
				break;
			case "35:14":
			case "171:14":
			case "115:0":
			case "115:1":
			case "115:2":
			case "115:3":
			case "100:14":
				$color = new MapColor(153, 51, 51); //COLOR_RED
				break;
			case "35:15":
			case "171:15":
			case "49:0":
			case "173:0":
				$color = new MapColor(25, 25, 25); //COLOR_BLACK
				break;
			case "41:0":
			case "147:0":
				$color = new MapColor(250, 238, 77); //GOLD
				break;
			case "57:0":
				$color = new MapColor(92, 219, 213); //DIAMOND
				break;
			case "22:0":
				$color = new MapColor(74, 128, 255); //LAPIS
				break;
			case "133:0":
				$color = new MapColor(0, 217, 58); //EMERALD
				break;
			case "193:0":
			case "243:0":
			case "17:1":
			case "5:1":
			case "158:1":
			case "134:0":
			case "85:1":
			case "183:0":
				$color = new MapColor(129, 86, 49); //PODZOL
				break;
			case "87:0":
			case "112:0":
			case "153:0":
			case "114:0":
			case "44:7":
			case "113:0":
				$color = new MapColor(112, 2, 0); //NETHER
				break;
			default:
				//echo($str." 不支持\n");
				$color = new MapColor(0, 0, 0); //AIR
				break;
		}
		
		$r = $color->getR();
		$g = $color->getG();
		$b = $color->getB();
		$a = (int) $color->getA();
		
		if($cid < 0){
			$r = (int) ($r * 135) / 255;
			$g = (int) ($g * 135) / 255;
			$b = (int) ($b * 135) / 255;
			return new MapColor($r, $g, $b, $a);
		}elseif($cid == 0){
			$r = (int) ($r * 180) / 255;
			$g = (int) ($g * 180) / 255;
			$b = (int) ($b * 180) / 255;
			return new MapColor($r, $g, $b, $a);
		}else{
			return $color;
		}
		
		/*if($id === Block::AIR){
			return new MapColor(0, 0, 0);
		}elseif($id === Block::BED_BLOCK or $id === Block::COBWEB){
			return new MapColor(199, 199, 199);
		}elseif($id === Block::LAVA or $id === Block::STILL_LAVA or $id === Block::FLOWING_LAVA or $id === Block::TNT or $id === Block::FIRE or $id === Block::REDSTONE_BLOCK){
			return new MapColor(255, 0, 0);
		}elseif($id === Block::IRON_BLOCK or $id === Block::IRON_DOOR_BLOCK or $id === Block::IRON_TRAPDOOR or $id === Block::IRON_BARS or $id === Block::BREWING_STAND_BLOCK or $id === Block::ANVIL or $id === Block::HEAVY_WEIGHTED_PRESSURE_PLATE){
			return new MapColor(167, 167, 167);
		}elseif($id === Block::SAPLING or $id === Block::LEAVES or $id === Block::LEAVES2 or $id === Block::TALL_GRASS or $id === Block::DEAD_BUSH or $id === Block::RED_FLOWER or $id === Block::DOUBLE_PLANT or $id === Block::BROWN_MUSHROOM or $id === Block::RED_MUSHROOM or $id === Block::WHEAT_BLOCK or $id === Block::CARROT_BLOCK or $id === Block::POTATO_BLOCK or $id === Block::BEETROOT_BLOCK or $id === Block::CACTUS or $id === Block::SUGARCANE_BLOCK or $id === Block::PUMPKIN_STEM or $id === Block::MELON_STEM or $id === Block::VINE or $id === Block::LILY_PAD or $id === Block::DANDELION){
			return new MapColor(0, 124, 0);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_WHITE) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_WHITE) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_WHITE) or $id === Block::SNOW_LAYER or $id === Block::SNOW_BLOCK){
			return new MapColor(255, 255, 255);
		}elseif($id === Block::CLAY_BLOCK or $id === Block::MONSTER_EGG){
			return new MapColor(164, 168, 184);
		}elseif($id === Block::DIRT or $id === Block::FARMLAND or ($id === Block::STONE and $meta == Stone::GRANITE) or ($id === Block::STONE and $meta == Stone::POLISHED_GRANITE) or ($id === Block::SAND and $meta == 1) or $id === Block::RED_SANDSTONE or $id === Block::RED_SANDSTONE_STAIRS or ($id === Block::LOG and ($meta & 0x03) == Wood::JUNGLE) or ($id === Block::PLANKS and $meta == Planks::JUNGLE) or $id === Block::JUNGLE_FENCE_GATE or ($id === Block::FENCE and $meta == Planks::JUNGLE) or $id === Block::JUNGLE_STAIRS or ($id === Block::WOODEN_SLAB and ($meta & 0x07) == Planks::JUNGLE) or $id === Block::GRASS_PATH){
			return new MapColor(151, 89, 77);
		}elseif($id === Block::STONE or $id === Block::COBBLESTONE or $id === Block::COBBLESTONE_STAIRS or $id === Block::COBBLESTONE_WALL or $id === Block::MOSS_STONE or ($id === Block::STONE and $meta == Stone::ANDESITE) or ($id === Block::STONE and $meta == Stone::POLISHED_ANDESITE) or $id === Block::BEDROCK or $id === Block::GOLD_ORE or $id === Block::IRON_ORE or $id === Block::COAL_ORE or $id === Block::LAPIS_ORE or $id === Block::DISPENSER or $id === Block::DROPPER or $id === Block::STICKY_PISTON or $id === Block::MONSTER_SPAWNER or $id === Block::DIAMOND_ORE or $id === Block::FURNACE or $id === Block::STONE_PRESSURE_PLATE or $id === Block::REDSTONE_ORE or $id === Block::STONE_BRICK or $id === Block::STONE_BRICK_STAIRS or $id === Block::HOPPER_BLOCK or $id === Block::GRAVEL or $id === Block::DOUBLE_SLABS or $id === Block::STONE_SLAB){
			return new MapColor(112, 112, 112);
		}elseif($id === Block::WATER or $id === Block::STILL_WATER or $id === Block::FLOWING_WATER){
			return new MapColor(64, 64, 255);
		}elseif(($id === Block::WOOD and ($meta & 0x03) == Wood::OAK) or ($id === Block::PLANKS and $meta == Planks::OAK) or ($id === Block::FENCE and $meta == Planks::OAK) or $id === Block::OAK_FENCE_GATE or $id === Block::OAK_STAIRS or ($id === Block::WOODEN_SLAB and ($meta & 0x07) == Planks::OAK) or $id === Block::NOTEBLOCK or $id === Block::BOOKSHELF or $id === Block::CHEST or $id === Block::TRAPPED_CHEST or $id === Block::CRAFTING_TABLE or $id === Block::WOODEN_DOOR_BLOCK or $id === Block::BIRCH_DOOR_BLOCK or $id === Block::SPRUCE_DOOR_BLOCK or $id === Block::JUNGLE_DOOR_BLOCK or $id === Block::ACACIA_DOOR_BLOCK or $id === Block::DARK_OAK_DOOR_BLOCK or $id === Block::SIGN_POST or $id === Block::WALL_SIGN or $id === Block::WOODEN_PRESSURE_PLATE or $id === Block::WOODEN_TRAPDOOR or $id === Block::BROWN_MUSHROOM_BLOCK or $id === Block::SLABS){
			return new MapColor(143, 119, 72);
		}elseif($id === Block::QUARTZ_BLOCK or ($id === Block::STONE_SLAB and ($meta & 0x07) == 6) or $id === Block::QUARTZ_STAIRS or ($id === Block::STONE and $meta == Stone::DIORITE) or ($id === Block::STONE and $meta == Stone::POLISHED_DIORITE)){
			return new MapColor(255, 252, 245);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_ORANGE) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_ORANGE) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_ORANGE) or $id === Block::PUMPKIN or $id === Block::JACK_O_LANTERN or $id === Block::HARDENED_CLAY or ($id === Block::WOOD2 and ($meta & 0x03) == Wood2::ACACIA) or ($id === Block::PLANKS and $meta == Planks::ACACIA) or ($id === Block::FENCE and $meta == Planks::ACACIA) or $id === Block::ACACIA_FENCE_GATE or $id === Block::ACACIA_STAIRS or ($id === Block::WOODEN_SLAB and ($meta & 0x07) == Planks::ACACIA)){
			return new MapColor(216, 127, 51);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_MAGENTA) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_MAGENTA) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_MAGENTA)){
			return new MapColor(178, 76, 216);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_LIGHT_BLUE) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_LIGHT_BLUE) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_LIGHT_BLUE)){
			return new MapColor(82, 153, 216);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_YELLOW) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_YELLOW) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_YELLOW) or $id === Block::HAY_BALE or $id === Block::SPONGE){
			return new MapColor(229, 229, 51);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_LIME) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_LIME) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_LIME) or $id === Block::MELON_BLOCK){
			return new MapColor(229, 229, 51);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_PINK) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_PINK) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_PINK)){
			return new MapColor(242, 127, 165);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_GRAY) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_GRAY) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_GRAY) or $id === Block::CAULDRON_BLOCK){
			return new MapColor(76, 76, 76);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_LIGHT_GRAY) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_LIGHT_GRAY) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_LIGHT_GRAY)){
			return new MapColor(153, 153, 153);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_CYAN) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_CYAN) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_CYAN)){
			return new MapColor(76, 127, 153);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_PURPLE) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_PURPLE) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_PURPLE) or $id === Block::MYCELIUM){
			return new MapColor(127, 63, 178);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_BLUE) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_BLUE) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_BLUE)){
			return new MapColor(51, 76, 178);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_BROWN) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_BROWN) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_BROWN) or $id === Block::SOUL_SAND or ($id === Block::WOOD2 and ($meta & 0x03) == Wood2::DARK_OAK) or ($id === Block::PLANKS and $meta == Planks::DARK_OAK) or ($id === Block::FENCE and $meta == Planks::DARK_OAK) or $id === Block::DARK_OAK_FENCE_GATE or $id === Block::DARK_OAK_STAIRS or ($id === Block::WOODEN_SLAB and ($meta & 0x07) == Planks::DARK_OAK)){
			return new MapColor(82, 76, 51);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_GREEN) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_GREEN) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_GREEN) or $id === Block::END_PORTAL_FRAME){
			return new MapColor(82, 127, 51);
		}elseif(($id === Block::WOOL and $meta == self::COLOR_BLOCK_RED) or ($id === Block::CARPET and $meta == self::COLOR_BLOCK_RED) or ($id === Block::STAINED_HARDENED_CLAY and $meta == self::COLOR_BLOCK_RED) or $id === Block::RED_MUSHROOM_BLOCK or $id === Block::BRICK_BLOCK or ($id === Block::STONE_SLAB and ($meta & 0x07) == 4) or $id === Block::BRICK_STAIRS or $id === Block::ENCHANTING_TABLE){
			return new MapColor(153, 51, 51);
		}elseif(($id === Block::WOOL and $meta == 0) or ($id === Block::CARPET and $meta == 0) or ($id === Block::STAINED_HARDENED_CLAY and $meta == 0) or $id === Block::COAL_BLOCK or $id === Block::OBSIDIAN){
			return new MapColor(25, 25, 25);
		}elseif($id === Block::GOLD_BLOCK or $id === Block::LIGHT_WEIGHTED_PRESSURE_PLATE){
			return new MapColor(250, 238, 77);
		}elseif($id === Block::DIAMOND_BLOCK){
			return new MapColor(92, 219, 213);
		}elseif($id === Block::LAPIS_BLOCK){
			return new MapColor(74, 128, 255);
		}elseif($id === Block::EMERALD_BLOCK){
			return new MapColor(0, 217, 58);
		}elseif($id === Block::PODZOL or ($id === Block::WOOD and ($meta & 0x03) == Wood::SPRUCE) or ($id === Block::PLANKS and $meta == Planks::SPRUCE) or ($id === Block::FENCE and $meta == Planks::SPRUCE) or $id === Block::SPRUCE_FENCE_GATE or $id === Block::SPRUCE_STAIRS or ($id === Block::WOODEN_SLAB and ($meta & 0x07) == Planks::SPRUCE)){
			return new MapColor(129, 86, 49);
		}elseif($id === Block::NETHERRACK or $id === Block::NETHER_QUARTZ_ORE or $id === Block::NETHER_BRICK_FENCE or $id === Block::NETHER_BRICK_BLOCK or $id === Block::NETHER_BRICK_STAIRS or ($id === Block::STONE_SLAB and ($meta & 0x07) == 7)){
			return new MapColor(112, 2, 0);
		}else{
			return new MapColor(0, 0, 0, 0);
		}*/
	}

}