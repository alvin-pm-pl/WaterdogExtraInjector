<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector;

use alvin0319\WaterdogExtraInjector\network\handler\WDPELoginPacketHandler;
use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\PlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Loader extends PluginBase{

	/** @phpstan-var \WeakMap<NetworkSession, PlayerInfo> */
	public static \WeakMap $xuidMap;

	protected function onEnable() : void{
		self::$xuidMap = new \WeakMap();
		$this->saveDefaultConfig();
		if(!$this->getConfig()->get("enabled", false)){
			return;
		}
		$this->getServer()->getPluginManager()->registerEvent(DataPacketReceiveEvent::class, function(DataPacketReceiveEvent $event) : void{
			$packet = $event->getPacket();
			if(!$packet instanceof LoginPacket){
				return;
			}
			$event->getOrigin()->setHandler(new WDPELoginPacketHandler($this->getServer(), $event->getOrigin(),
				function(PlayerInfo $info) use ($event) : void{
					(function() use ($info) : void{
						/** @noinspection PhpUndefinedFieldInspection */
						$this->info = $info;
						Loader::$xuidMap[$this] = $info;
						/** @noinspection PhpUndefinedMethodInspection */
						$this->getLogger()->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
						/** @noinspection PhpUndefinedMethodInspection */
						$this->getLogger()->setPrefix($this->getLogPrefix());
					})->call($event->getOrigin());
				}, function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($event) : void{
					\Closure::bind(
						closure: function(NetworkSession $session) use ($isAuthenticated, $authRequired, $error, $clientPubKey) : void{
							$session->setAuthenticationStatus($isAuthenticated, $authRequired, $error, $clientPubKey);
							if(!isset(Loader::$xuidMap[$session])){
								throw new PacketHandlingException("PlayerInfo not set");
							}
							$session->info = Loader::$xuidMap[$session];
							unset(Loader::$xuidMap[$session]);
						},
						newThis: $this,
						newScope: NetworkSession::class
					)($event->getOrigin());
				}));
		}, EventPriority::LOWEST, $this, true);
	}
}