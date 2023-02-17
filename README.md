# WaterdogExtraInjector

A plugin to correct player IP and xuid when using WaterdogPE with extra data enabled.

# How does it work?

This plugin intercepts `LoginPacket` and replaces the `LoginPacketHandler` to my custom `WDPELoginPacketHandler` to handle WDPE's extra data (such as `Waterdog_IP`)

Note that if you don't enable extra data on your WaterdogPE, you will get a Packet processing error when you tried to login.
