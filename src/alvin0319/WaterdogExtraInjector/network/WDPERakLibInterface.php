<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector\network;

use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;
use ReflectionClass;
use ReflectionException;

/**
 * @phpstan-type TValue T
 */
final class WDPERakLibInterface extends RakLibInterface{

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$client = new WDPENetworkSession(
			$this->getProperty("server", $this),
			$this->getProperty("network", $this)->getSessionManager(),
			PacketPool::getInstance(),
			new RakLibPacketSender($sessionId, $this),
			$this->getProperty("broadcaster", $this),
			ZlibCompressor::getInstance(),
			$address,
			$port
		);
		$this->putSession($client, $sessionId);
	}

	/**
	 * @param string      $name
	 * @param object|null $bindObject
	 *
	 * @return mixed
	 * @phpstan-return TValue(T)
	 * @throws ReflectionException
	 */
	private function getProperty(string $name, ?object $bindObject = null) : mixed{
		static $properties = [];
		if(isset($properties[$name])){
			return $properties[$name];
		}
		$reflection = new ReflectionClass(RakLibInterface::class);
		$property = $reflection->getProperty($name);
		$property->setAccessible(true);
		$value = $property->getValue($bindObject);
		$property->setAccessible(false);
		return $properties[$name] = $value;
	}

	public function putSession(WDPENetworkSession $session, int $clientID) : void{
		$property = $this->getProperty("sessions", $this);
		$property[$clientID] = $session;
		$this->setProperty("sessions", $property, $this);
	}

	public function setProperty(string $propertyName, mixed $value, ?object $bindObject = null) : void{
		$reflection = new ReflectionClass(RakLibInterface::class);
		$property = $reflection->getProperty($propertyName);
		$property->setAccessible(true);
		$property->setValue($bindObject, $value);
	}
}