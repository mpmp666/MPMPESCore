<?php

/*
 *
 *  ____            _        _   __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | | (_| |   <| |  _ <
 * |_|  \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\__,_|_|\_\_|_| \___|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author MPMPESCore
 * @link https://github.com/mpmp666/MPMPESCore
 *
*/

namespace pocketmine\network\protocol;

#include <rules/DataPacket.h>

use pocketmine\utils\MapColor;

/**
 * 0.14 服务端 -> 客户端: 地图纹理数据
 * 包格式与 SCAXE 0.14.x 核心一致(Copy From WallBanner):
 * mapId 为 Long, type 为位标志(0x02=纹理更新), 颜色为逐像素 RGBA 字节(行优先)
 */
class ClientboundMapItemDataPacket extends DataPacket{
	const NETWORK_ID = Info::CLIENTBOUND_MAP_ITEM_DATA_PACKET;

	const BITFLAG_TEXTURE_UPDATE = 0x02;
	const BITFLAG_DECORATION_UPDATE = 0x04;

	/** @var int */
	public $mapId;
	/** @var int */
	public $type;
	/** @var int */
	public $scale = 0;

	/** @var array */
	public $decorations = []; //TODO:

	/** @var int */
	public $width = 128;
	/** @var int */
	public $height = 128;
	/** @var int */
	public $xOffset = 0;
	/** @var int */
	public $yOffset = 0;

	/** @var MapColor[][]|string */
	public $colors;

	/** @var bool */
	public $isColorArray = true;

	public function decode(){
		//TODO:
	}

	public function encode(){
		$this->reset();
		$this->putLong($this->mapId);

		$type = 0x00;

		if(count($this->colors) > 0){
			$type |= self::BITFLAG_TEXTURE_UPDATE;
		}
		$this->putInt($type);

		if(($type & self::BITFLAG_TEXTURE_UPDATE) !== 0){
			$this->putByte($this->scale);

			$this->putInt($this->width);
			$this->putInt($this->height);
			$this->putInt($this->xOffset);
			$this->putInt($this->yOffset);

			if($this->isColorArray){
				for($y = 0; $y < $this->height; ++$y){
					for($x = 0; $x < $this->width; ++$x){
						$color = $this->colors[$y][$x];

						$this->putByte($color->getR());
						$this->putByte($color->getG());
						$this->putByte($color->getB());
						$this->putByte($color->getA());
					}
				}
			}else{
				$this->put($this->colors);
			}
		}
	}
}
