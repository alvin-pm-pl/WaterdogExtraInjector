<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector\network;

use alvin0319\WaterdogExtraInjector\network\handler\WDPELoginPacketHandler;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\PlayerInfo;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use ReflectionException;

/**
 * @phpstan-type TValue T
 */
final class WDPENetworkSession extends NetworkSession{

	protected string $playerAddress = "";

	public function setPlayerAddress(string $address) : void{
		$this->playerAddress = $address;
	}

	public function getIp() : string{
		return $this->playerAddress === "" ? parent::getIp() : $this->playerAddress;
	}

	public function setHandler(?PacketHandler $handler) : void{
		if(!$handler instanceof LoginPacketHandler){
			parent::setHandler($handler);
			return;
		}
		parent::setHandler(new WDPELoginPacketHandler(
			$this->getProperty("server", $this),
			$this,
			function(PlayerInfo $info) : void{
				$this->setProperty("info", $info, $this);
				$this->getLogger()->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
				$this->getLogger()->setPrefix($this->invokeFunction("getLogPrefix", $this));
			},
			function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void{
				$this->invokeFunction("setAuthenticationStatus", $this, [$isAuthenticated, $authRequired, $error, $clientPubKey]);
			}
		));
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
		$reflection = new ReflectionClass(NetworkSession::class);
		$property = $reflection->getProperty($name);
		$property->setAccessible(true);
		$value = $property->getValue($bindObject);
		$property->setAccessible(false);
		return $properties[$name] = $value;
	}

	public function setProperty(string $propertyName, mixed $value, ?object $bindObject = null) : void{
		$reflection = new ReflectionClass(NetworkSession::class);
		$property = $reflection->getProperty($propertyName);
		$property->setAccessible(true);
		$property->setValue($bindObject, $value);
	}

	public function invokeFunction(string $function, ?object $bindObject = null, array $args = []) : mixed{
		$reflection = new ReflectionClass(NetworkSession::class);
		$method = $reflection->getMethod($function);
		$method->setAccessible(true);
		$result = null;
		if($method->hasReturnType()){
			$result = $method->invoke($bindObject, ...$args);
		}
		$method->setAccessible(false);
		return $result;
	}
}