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

#include <rules/RakLibPacket.h>

class NewIncomingConnection extends Packet{
	public static $ID = MessageIdentifiers::ID_NEW_INCOMING_CONNECTION;

	public $address;
	public $port;
	public $addrVersion = 4;
	
	public $systemAddresses = [];
	
	public $sendPing;
	public $sendPong;

	public function encode(){
		parent::encode();
		$this->putAddress($this->address, $this->port, $this->addrVersion);
		for($i = 0; $i < 10; ++$i){
			$this->putAddress($this->systemAddresses[$i][0], $this->systemAddresses[$i][1], $this->systemAddresses[$i][2]);
		}

		$this->putLong($this->sendPing);
		$this->putLong($this->sendPong);
	}

	public function decode(){
		parent::decode();
		$this->getAddress($this->address, $this->port, $this->addrVersion);
		for($i = 0; $i < 10; ++$i){
			$this->getAddress($addr, $port, $version);
			$this->systemAddresses[$i] = [$addr, $port, $version];
		}

		$this->sendPing = $this->getLong();
		$this->sendPong = $this->getLong();
	}
}
