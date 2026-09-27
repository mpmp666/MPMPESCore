<?php

/*
 * 0.16-only packet: sent right before StartGamePacket so 0.16 clients leave the
 * "loading resources" state. We have no resource packs, so it is always empty.
 * Encoded natively as nothing; MultiProtocol::translateOutgoing16() builds the
 * real wire bytes and all other families drop it.
 */

namespace pocketmine\network\protocol;

class ResourcePacksInfoPacket extends DataPacket{
	//virtual native id, outside the 0.14 range on purpose
	const NETWORK_ID = 0xec;

	public function decode(){

	}

	public function encode(){
		$this->reset();
	}

}
