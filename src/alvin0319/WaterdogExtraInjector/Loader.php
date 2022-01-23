<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector;

use alvin0319\WaterdogExtraInjector\network\handler\WDPELoginPacketHandler;
use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\player\PlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Loader extends PluginBase{

	protected function onEnable() : void{
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
						/** @noinspection PhpUndefinedMethodInspection */
						$this->getLogger()->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
						/** @noinspection PhpUndefinedMethodInspection */
						$this->getLogger()->setPrefix($this->getLogPrefix());
					})->call($event->getOrigin());
				}, function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($event) : void{
					(function() use ($isAuthenticated, $authRequired, $error, $clientPubKey) : void{
						/** @noinspection PhpUndefinedMethodInspection */
						$this->setAuthenticationStatus($isAuthenticated, $authRequired, $error, $clientPubKey);
					})->call($event->getOrigin());
				}));
		}, EventPriority::LOWEST, $this, true);
	}
}