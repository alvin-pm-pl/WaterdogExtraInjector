# WaterdogExtraInjector

A plugin to correct player IP and xuid when using WaterdogPE with extra data enabled.

# How does it work?
This plugin will register the custom `RakLibInterface` that replaces the `NetworkSession` to my custom `WDPENetowrkSession` (it is the same as NetworkSession, just added a few methods to support extra data)

When the player tries to log in, this plugin will try to overwrite LoginPacketHandler and find the `Waterdog_IP` and `Waterdog_XUID` from `ClientData`.

Note that if you don't enable extra data on your WaterdogPE, you will get a `Packet processing error` when you tried to login.
