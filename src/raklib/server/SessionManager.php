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

namespace raklib\server;

use raklib\Binary;
use raklib\protocol\ACK;
use raklib\protocol\AdvertiseSystem;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\NAK;
use raklib\protocol\NoFreeIncomingConnections;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\Packet;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;
use raklib\RakLib;

class SessionManager{
	protected $packetPool = [];

	/** @var ACK */
	protected $cachedACK = null;
	/** @var NAK */
	protected $cachedNAK = null;
	/** @var Datagram */
	protected $cachedDatagram = null;

	/** @var RakLibServer */
	protected $server;

	protected $socket;

	protected $receiveBytes = 0;
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessions = [];
	protected $maxAllowedConnections = PHP_INT_MAX;

	protected $name = "";

	protected $packetLimit = 200;

	protected $shutdown = false;

	protected $ticks = 0;
	protected $lastMeasure;

	protected $block = [];
	protected $ipSec = [];

	public $portChecking = true;

	public function __construct(RakLibServer $server, UDPServerSocket $socket){
		$this->server = $server;
		$this->socket = $socket;
		$this->registerPackets();
		$this->cachedACK = new ACK();
		$this->cachedNAK = new NAK();
		$this->cachedDatagram = new Datagram();

		$this->serverId = mt_rand(0, PHP_INT_MAX);

		$this->run();
	}

	public function getPort(){
		return $this->server->getPort();
	}

	public function getLogger(){
		return $this->server->getLogger();
	}

	public function run(){
		$this->tickProcessor();
	}

	private function tickProcessor(){
		$this->lastMeasure = microtime(true);

		while(!$this->shutdown){
			$start = microtime(true);
			$max = 5000;
			while(--$max and $this->receivePacket());
			while($this->receiveStream());
			$this->tick();
			$time = microtime(true) - $start;
			if($time < 0.01){
				time_sleep_until(microtime(true) + 0.01 - $time);
			}
		}
	}

	private function tick(){
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		foreach($this->ipSec as $address => $count){
			if($count >= $this->packetLimit){
				$this->blockAddress($address);
			}
		}
		$this->ipSec = [];



		if(($this->ticks & 0b1111) === 0){
			$diff = max(0.005, $time - $this->lastMeasure);
			$this->streamOption("bandwidth", serialize([
				"up" => $this->sendBytes / $diff,
				"down" => $this->receiveBytes / $diff
			]));
			$this->lastMeasure = $time;
			$this->sendBytes = 0;
			$this->receiveBytes = 0;

			if(count($this->block) > 0){
				asort($this->block);
				$now = microtime(true);
				foreach($this->block as $address => $timeout){
					if($timeout <= $now){
						unset($this->block[$address]);
					}else{
						break;
					}
				}
			}
		}

		++$this->ticks;
	}

	/**
	 * Reads a packet from the socket and processes it.
	 *
	 * @return bool if anything was read from the socket.
	 */
	private function receivePacket(){
		$buffer = $source = $port = null;
		$len = $this->socket->readPacket($buffer, $source, $port);
		if($buffer !== null){
			try{
				$this->receiveBytes += $len;
				if(isset($this->block[$source])){
					return true;
				}

				if(isset($this->ipSec[$source])){
					$this->ipSec[$source]++;
				}else{
					$this->ipSec[$source] = 1;
				}

				if($len > 0){
					$pid = ord($buffer{0});

					if($this->sessionExists($source, $port) and ($pid & Datagram::BITFLAG_VALID)){
						if($pid & Datagram::BITFLAG_ACK){
							$packet = clone $this->cachedACK;
							$packet->buffer = $buffer;
							$this->getSession($source, $port)->handleACK($packet);
						}elseif($pid & Datagram::BITFLAG_NAK){
							$packet = clone $this->cachedNAK;
							$packet->buffer = $buffer;
							$this->getSession($source, $port)->handleNAK($packet);
						}else{ //Normal data packet
							$packet = clone $this->cachedDatagram;
							$packet->buffer = $buffer;
							$this->getSession($source, $port)->handleDatagram($packet);
						}
					}elseif(strpos($buffer, RakLib::MAGIC) !== false){ //Offline message
						switch($pid){
							case MessageIdentifiers::ID_UNCONNECTED_PING:
							//case MessageIdentifiers::ID_UNCONNECTED_PING_OPEN_CONNECTIONS: //TODO
								$packet = new UnconnectedPing($buffer);
								$packet->decode();

								$pk = new UnconnectedPong();
								$pk->serverID = $this->getID();
								$pk->pingID = $packet->pingID;
								$pk->serverName = $this->getName();
								$this->sendPacket($pk, $source, $port);
								break;
							case MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1:
								$packet = new OpenConnectionRequest1($buffer);
								$packet->decode();

								if(false/*$packet->protocol !== RakLib::PROTOCOL*/){
									//TODO: figure out how to handle incompatible protocol (MCPE uses 8, nothing else will)
									/*$pk = new IncompatibleProtocolVersion();
									$pk->remoteProtocol = RakLib::PROTOCOL;
									$this->sendPacket($pk, $source, $port);*/
								}else{
									$pk = new OpenConnectionReply1();
									$pk->mtuSize = min(Session::MAX_MTU_SIZE, $packet->mtuSize);
									$pk->serverID = $this->getID();
									$this->sendPacket($pk, $source, $port);
								}
								break;
							case MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2:
								$packet = new OpenConnectionRequest2($buffer);
								$packet->decode();

								if($this->sessionExists($source, $port)){
									//already connected, return ID_ALREADY_CONNECTED (TODO)
								}elseif(count($this->sessions) >= $this->maxAllowedConnections){
									$pk = new NoFreeIncomingConnections();
									$pk->serverID = $this->getID();
									$this->sendPacket($pk, $source, $port);
									//TODO: notify main thread
								}else{
									if($packet->serverPort === $this->getPort() or !$this->portChecking){
										$pk = new OpenConnectionReply2();
										$pk->mtuSize = $packet->mtuSize;
										$pk->serverID = $this->getID();
										$pk->clientAddress = $source;
										$pk->clientPort = $port;
										$this->sendPacket($pk, $source, $port);
										$this->createSession($source, $port, $packet->mtuSize);
									}else{
										$this->getLogger()->debug("Received connection on unexpected port " . $packet->serverPort . ", expected " . $this->getPort());
									}
								}
								break;
							default:
								//$this->getLogger()->debug("Unhandled offline message from $source $port: 0x" . bin2hex($buffer));
								break;
						}
					}else{ //Not RakNet message or valid datagram, maybe Query
						$this->streamRaw($source, $port, $buffer);
					}
				}
			}catch(\Throwable $e){
				$this->blockAddress($source);
				$this->getLogger()->logException($e);
			}

			return true;
		}

		return false;
	}

	public function sendPacket(Packet $packet, $dest, $port){
		$packet->encode();
		assert(strlen($packet->buffer) <= 1492, get_class($packet) . " is too big (" . strlen($packet->buffer) . " bytes)");
		$this->sendBytes += $this->socket->writePacket($packet->buffer, $dest, $port);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, $flags = RakLib::PRIORITY_NORMAL){
		$id = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toBinary(true);
		$this->server->pushThreadToMainPacket($buffer);
	}

	public function streamRaw($address, $port, $payload){
		$buffer = chr(RakLib::PACKET_RAW) . chr(strlen($address)) . $address . Binary::writeShort($port) . $payload;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamClose($identifier, $reason){
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamInvalid($identifier){
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOpen(Session $session){
		$identifier = $session->getAddress() . ":" . $session->getPort();
		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($session->getAddress())) . $session->getAddress() . Binary::writeShort($session->getPort()) . Binary::writeLong($session->getID());
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamACK($identifier, $identifierACK){
		$buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
		$this->server->pushThreadToMainPacket($buffer);
	}

	protected function streamOption($name, $value){
		$buffer = chr(RakLib::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->server->pushThreadToMainPacket($buffer);
	}

	private function checkSessions(){
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $i => $s){
				if(!$s->isConnected()){
					unset($this->sessions[$i]);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function receiveStream(){
		if(strlen($packet = $this->server->readMainToThreadPacket()) > 0){
			$id = ord($packet{0});
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				if(isset($this->sessions[$identifier])){
					$flags = ord($packet{$offset++});
					$buffer = substr($packet, $offset);
					$this->sessions[$identifier]->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($buffer, true), $flags);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_RAW){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$this->socket->writePacket($payload, $address, $port);
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === RakLib::PACKET_SET_OPTION){
				$len = ord($packet{$offset++});
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
				switch($name){
					case "name":
						$this->name = $value;
						break;
					case "portChecking":
						$this->portChecking = (bool) $value;
						break;
					case "packetLimit":
						$this->packetLimit = (int) $value;
						break;
				}
			}elseif($id === RakLib::PACKET_BLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$timeout = Binary::readInt(substr($packet, $offset, 4));
				$this->blockAddress($address, $timeout);
			}elseif($id === RakLib::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}

				$this->socket->close();
				$this->shutdown = true;
			}elseif($id === RakLib::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}else{
				return false;
			}

			return true;
		}

		return false;
	}

	public function blockAddress($address, $timeout = 300){
		$final = microtime(true) + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				$this->getLogger()->notice("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	public function sessionExists(string $ip, int $port){
		return isset($this->sessions[$ip . ":" . $port]);
	}

	public function createSession(string $ip, int $port, int $mtuSize){
		$id = $ip . ":" . $port;
		if(isset($this->sessions[$id])){
			throw new \InvalidStateException("Session for $ip:$port already exists");
		}

		$this->sessions[$id] = new Session($this, $ip, $port, $mtuSize);
	}

	/**
	 * @param string $ip
	 * @param int    $port
	 *
	 * @return Session
	 */
	public function getSession(string $ip, int $port){
		return $this->sessions[$ip . ":" . $port] ?? null;
	}

	public function removeSession(Session $session, $reason = "unknown"){
		$id = $session->getAddress() . ":" . $session->getPort();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			unset($this->sessions[$id]);
			$this->streamClose($id, $reason);
		}
	}

	public function openSession(Session $session){
		$this->streamOpen($session);
	}

	public function notifyACK(Session $session, $identifierACK){
		$this->streamACK($session->getAddress() . ":" . $session->getPort(), $identifierACK);
	}

	public function getName(){
		return $this->name;
	}

	public function getID(){
		return $this->serverId;
	}

	private function registerPacket($id, $class){
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param int    $id
	 * @param string $buffer
	 * @param int    $offset
	 *
	 * @return Packet|null
	 */
	public function getPacketFromPool(int $id, string $buffer = "", int $offset = 0){
		if(isset($this->packetPool[$id])){
			$pk = clone $this->packetPool[$id];
			$pk->buffer = $buffer;
			$pk->offset = $offset;
			return $pk;
		}

		return null;
	}

	private function registerPackets(){
		//$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionRequest1::$ID, OpenConnectionRequest1::class);
		$this->registerPacket(OpenConnectionReply1::$ID, OpenConnectionReply1::class);
		$this->registerPacket(OpenConnectionRequest2::$ID, OpenConnectionRequest2::class);
		$this->registerPacket(OpenConnectionReply2::$ID, OpenConnectionReply2::class);
		$this->registerPacket(UnconnectedPong::$ID, UnconnectedPong::class);
		$this->registerPacket(AdvertiseSystem::$ID, AdvertiseSystem::class);
	}
}
