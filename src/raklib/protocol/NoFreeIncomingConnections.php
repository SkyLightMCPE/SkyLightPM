<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */


namespace raklib\protocol;

use raklib\RakLib;

class NoFreeIncomingConnections extends Packet{
	public static $ID = MessageIdentifiers::ID_NO_FREE_INCOMING_CONNECTIONS;

	public $serverID;

	public function decode(){
		parent::decode();
		$this->offset += 16; //magic
		$this->serverID = $this->getLong();
	}

	public function encode(){
		parent::encode();
		$this->put(RakLib::MAGIC);
		$this->putLong($this->serverID);
	}
}