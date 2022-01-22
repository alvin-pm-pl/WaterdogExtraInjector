<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector;

use alvin0319\WaterdogExtraInjector\network\WDPERakLibInterface;
use pocketmine\event\EventPriority;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\plugin\PluginBase;

final class Loader extends PluginBase{

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvent(NetworkInterfaceRegisterEvent::class, function(NetworkInterfaceRegisterEvent $event) : void{
			$network = $event->getInterface();
			if($network instanceof DedicatedQueryNetworkInterface){
				$event->cancel();
				return;
			}
			if($network instanceof RakLibInterface && !$network instanceof WDPERakLibInterface){
				$event->cancel();
				$this->getServer()->getNetwork()->registerInterface(new WDPERakLibInterface($this->getServer(), $this->getServer()->getIp(), $this->getServer()->getPort(), false));
				if($this->getServer()->getConfigGroup()->getConfigBool("enable-ipv6", true)){
					$this->getServer()->getNetwork()->registerInterface(new WDPERakLibInterface($this->getServer(), $this->getServer()->getIpV6(), $this->getServer()->getPortV6(), true));
				}
			}
		}, EventPriority::NORMAL, $this, true);
		/*
		$this->getScheduler()->scheduleTask(new ClosureTask(function() : void{
			foreach($this->getServer()->getNetwork()->getInterfaces() as $interface){
				if($interface instanceof RakLibInterface || $interface instanceof DedicatedQueryNetworkInterface){
					$this->getServer()->getNetwork()->unregisterInterface($interface);
				}
			}
			$this->getServer()->getNetwork()->registerInterface(new WDPERakLibInterface($this->getServer(), $this->getServer()->getIp(), $this->getServer()->getPort(), false));
			if($this->getServer()->getConfigGroup()->getConfigBool("enable-ipv6", true)){
				$this->getServer()->getNetwork()->registerInterface(new WDPERakLibInterface($this->getServer(), $this->getServer()->getIpV6(), $this->getServer()->getPort(), true));
			}
		}));
		*/
	}
}