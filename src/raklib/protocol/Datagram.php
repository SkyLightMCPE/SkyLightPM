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

#include <rules/RakLibPacket.h>

class Datagram extends Packet{

	const DATAGRAM_HEADER_LENGTH = 4; //1 byte (bitflags) + 3 bytes (sequence number)

	const DATAGRAM_FULL_OVERHEAD = self::DATAGRAM_HEADER_LENGTH + RakLib::IP_HEADER_LENGTH + RakLib::UDP_HEADER_LENGTH + 8; //8 unaccounted for (RakNet is weird)

	const BITFLAG_VALID = 0x80;
	const BITFLAG_ACK = 0x40;
	const BITFLAG_NAK = 0x20;
	const BITFLAG_PACKET_PAIR = 0x10;
	const BITFLAG_CONTINUOUS_SEND = 0x08;
	const BITFLAG_NEEDS_B_AND_AS = 0x04;
	/*
	 * isValid          0x80
	 * isACK            0x40
	 * isNAK            0x20 (hasBAndAS for ACKs)
	 * isPacketPair     0x10
	 * isContinuousSend 0x08
	 * needsBAndAS      0x04
	 */

	public $isPacketPair = false;
	public $isContinuousSend = false;
	public $needsBAndAS = false;

	/** @var EncapsulatedPacket[] */
	protected $packets = [];

	public $seqNumber;

	protected $length = 0;

	/**
	 * @param EncapsulatedPacket $packet
	 * @param int                $mtuSize
	 *
	 * @return bool
	 */
	public function addPacket(EncapsulatedPacket $packet, int $mtuSize) : bool{
		if(($pkLen = $packet->getTotalLength()) + self::DATAGRAM_FULL_OVERHEAD + $this->length > $mtuSize){
			return false;
		}

		$this->packets[] = $packet;
		$this->length += $pkLen;

		return true;
	}

	/**
	 * @return EncapsulatedPacket[]
	 */
	public function getPackets() : array{
		return $this->packets;
	}

	public function encode(){
		$this->reset();
		$flags = (self::BITFLAG_VALID |
			($this->isPacketPair     ? self::BITFLAG_PACKET_PAIR     : 0) |
			($this->isContinuousSend ? self::BITFLAG_CONTINUOUS_SEND : 0) |
			($this->needsBAndAS      ? self::BITFLAG_NEEDS_B_AND_AS  : 0));

		$this->putByte($flags);
		$this->putLTriad($this->seqNumber);

		foreach($this->packets as $packet){
			$this->put($packet instanceof EncapsulatedPacket ? $packet->toBinary() : (string) $packet);
		}
	}

	public function length() : int{
		return $this->length + self::DATAGRAM_FULL_OVERHEAD - 8; //Remove 8 nonexistent bytes
	}

	public function decode(){
		$flags = $this->getByte();
		$this->isPacketPair =     ($flags & self::BITFLAG_PACKET_PAIR)     !== 0;
		$this->isContinuousSend = ($flags & self::BITFLAG_CONTINUOUS_SEND) !== 0;
		$this->needsBAndAS =      ($flags & self::BITFLAG_NEEDS_B_AND_AS)  !== 0;

		$this->seqNumber = $this->getLTriad();

		while(!$this->feof()){
			$offset = 0;
			$data = substr($this->buffer, $this->offset);
			$packet = EncapsulatedPacket::fromBinary($data, false, $offset);
			$this->offset += $offset;
			if(strlen($packet->buffer) === 0){
				break;
			}
			$this->packets[] = $packet;
		}
	}

	public function clean(){
		$this->packets = [];
		$this->seqNumber = null;
		return parent::clean();
	}
}