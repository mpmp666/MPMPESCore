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

/**
 * 0.14 客户端 -> 服务端: 玩家持有地图物品时请求该地图的数据
 * 包格式与 SCAXE 0.14.x 核心一致(Copy From WallBanner): mapId 为 Long
 */
class MapInfoRequestPacket extends DataPacket{
	const NETWORK_ID = Info::MAP_INFO_REQUEST_PACKET;

	/** @var int */
	public $mapId;

	public function encode(){
		$this->reset();
		$this->putLong($this->mapId);
	}

	public function decode(){
		$this->mapId = $this->getLong();
	}
}
