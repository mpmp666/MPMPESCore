<?php

/*
 * 0.16-only packet: command autocomplete data for the client chat bar.
 * MultiProtocol::translateOutgoing16() builds the real wire bytes; all other
 * families drop it.
 */

namespace pocketmine\network\protocol;

class AvailableCommandsPacket extends DataPacket{
	//virtual native id, outside the 0.14 range on purpose
	const NETWORK_ID = 0xee;

	public $commands = "{}";
	public $unknown = "";

	public function decode(){

	}

	public function encode(){
		$this->reset();
	}

}
