<?php

/*
 * 0.16-only inbound packet: clients run slash commands through this instead of
 * TextPacket chat. Buffers are parsed by MultiProtocol::decodeIncoming16()
 * which fills the properties below; decode() intentionally does nothing.
 */

namespace pocketmine\network\protocol;

class CommandStepPacket extends DataPacket{
	//virtual native id, outside the 0.14 range on purpose
	const NETWORK_ID = 0xef;

	public $command = "";
	public $overload = "";
	/** @var array|null */
	public $args = null;

	public function decode(){

	}

	public function encode(){

	}

}
