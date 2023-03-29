<?php

declare(strict_types=1);

namespace alvin0319\WaterdogExtraInjector\network\handler;

use Closure;
use InvalidArgumentException;
use JsonMapper;
use JsonMapper_Exception;
use pocketmine\entity\InvalidSkinException;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\network\mcpe\auth\ProcessLoginTask;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationData;
use pocketmine\network\mcpe\protocol\types\login\ClientDataPersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\login\ClientDataPersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\login\JwtChain;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\Server;
use Ramsey\Uuid\Uuid;
use function array_map;
use function base64_decode;
use function is_array;

final class WDPELoginPacketHandler extends PacketHandler
{


    /** @var Server */
    private Server $server;
    /** @var NetworkSession */
    private NetworkSession $session;
    /**
     * @var Closure
     * @phpstan-var Closure(PlayerInfo) : void
     */
    private Closure $playerInfoConsumer;
    /**
     * @var Closure
     * @phpstan-var Closure(bool, bool, ?string, ?string) : void
     */
    private Closure $authCallback;

    /**
     * @phpstan-param Closure(PlayerInfo) : void $playerInfoConsumer
     * @phpstan-param Closure(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void $authCallback
     */
    public function __construct(Server $server, NetworkSession $session, Closure $playerInfoConsumer, Closure $authCallback)
    {
        $this->session = $session;
        $this->server = $server;
        $this->playerInfoConsumer = $playerInfoConsumer;
        $this->authCallback = $authCallback;
    }

    public function handleLogin(LoginPacket $packet): bool
    {
        if (!$this->isCompatibleProtocol($packet->protocol)) {
            $this->session->sendDataPacket(PlayStatusPacket::create($packet->protocol <  534 /**ProtocolInfo::ACCEPTED_PROTOCOL*/ ? PlayStatusPacket::LOGIN_FAILED_CLIENT : PlayStatusPacket::LOGIN_FAILED_SERVER), true);

            //This pocketmine disconnect message will only be seen by the console (PlayStatusPacket causes the messages to be shown for the client)
            $this->session->disconnect(
                $this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_disconnect_incompatibleProtocol((string)$packet->protocol)),
                false
            );

            return true;
        }

        $extraData = $this->fetchAuthData($packet->chainDataJwt);

        if (!Player::isValidUserName($extraData->displayName)) {
            $this->session->disconnect(KnownTranslationKeys::DISCONNECTIONSCREEN_INVALIDNAME);

            return true;
        }

        $clientData = $this->parseWDPEClientData($packet->clientDataJwt);

        (function () use ($clientData): void {
            /** @noisnpectoin PhpUndefinedFieldInspection */
            $this->ip = $clientData->Waterdog_IP;
        })->call($this->session);

        try {
            $skin = SkinAdapterSingleton::get()->fromSkinData(self::fromClientData($clientData));
        } catch (InvalidArgumentException|InvalidSkinException $e) {
            $this->session->getLogger()->debug("Invalid skin: " . $e->getMessage());
            $this->session->disconnect(KnownTranslationKeys::DISCONNECTIONSCREEN_INVALIDSKIN);

            return true;
        }

        if (!Uuid::isValid($extraData->identity)) {
            throw new PacketHandlingException("Invalid login UUID");
        }
        $uuid = Uuid::fromString($extraData->identity);
        if ($clientData->Waterdog_XUID !== "") {
            $playerInfo = new XboxLivePlayerInfo(
                $clientData->Waterdog_XUID,
                $extraData->displayName,
                $uuid,
                $skin,
                $clientData->LanguageCode,
                (array)$clientData
            );

        } else {
            $playerInfo = new PlayerInfo(
                $extraData->displayName,
                $uuid,
                $skin,
                $clientData->LanguageCode,
                (array)$clientData
            );
        }
        ($this->playerInfoConsumer)($playerInfo);
        $s = $this->session;
        $ev = new PlayerPreLoginEvent(
            $playerInfo,
            $s,
            $this->server->requiresAuthentication()
        );
        if ($this->server->getNetwork()->getConnectionCount() > $this->server->getMaxPlayers()) {
            $ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_SERVER_FULL, KnownTranslationKeys::DISCONNECTIONSCREEN_SERVERFULL);
        }
        if (!$this->server->isWhitelisted($playerInfo->getUsername())) {
            $ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_SERVER_WHITELISTED, "Server is whitelisted");
        }
        if ($this->server->getNameBans()->isBanned($playerInfo->getUsername()) or $this->server->getIPBans()->isBanned($this->session->getIp())) {
            $ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_BANNED, "You are banned");
        }

        $ev->call();
        if (!$ev->isAllowed()) {
            $this->session->disconnect($ev->getFinalKickMessage());
            return true;
        }

        $this->processLogin($packet, $ev->isAuthRequired());

        return true;
    }

    /**
     * @throws PacketHandlingException
     */
    protected function fetchAuthData(JwtChain $chain): AuthenticationData
    {
        /** @var AuthenticationData|null $extraData */
        $extraData = null;
        foreach ($chain->chain as $k => $jwt) {
            //validate every chain element
            try {
                [, $claims,] = JwtUtils::parse($jwt);
            } catch (JwtException $e) {
                throw PacketHandlingException::wrap($e);
            }
            if (isset($claims["extraData"])) {
                if ($extraData !== null) {
                    throw new PacketHandlingException("Found 'extraData' more than once in chainData");
                }

                if (!is_array($claims["extraData"])) {
                    throw new PacketHandlingException("'extraData' key should be an array");
                }
                $mapper = new JsonMapper;
                $mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
                $mapper->bExceptionOnMissingData = true;
                $mapper->bExceptionOnUndefinedProperty = true;
                try {
                    /** @var AuthenticationData $extraData */
                    $extraData = $mapper->map($claims["extraData"], new AuthenticationData);
                } catch (JsonMapper_Exception $e) {
                    throw PacketHandlingException::wrap($e);
                }
            }
        }
        if ($extraData === null) {
            throw new PacketHandlingException("'extraData' not found in chain data");
        }
        return $extraData;
    }

    /**
     * @throws PacketHandlingException
     */
    protected function parseWDPEClientData(string $clientDataJwt): WDPEClientData
    {
        try {
            [, $clientDataClaims,] = JwtUtils::parse($clientDataJwt);
        } catch (JwtException $e) {
            throw PacketHandlingException::wrap($e);
        }

        $mapper = new JsonMapper;
        $mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
        $mapper->bExceptionOnMissingData = true;
        $mapper->bExceptionOnUndefinedProperty = true;
        try {
            $clientData = $mapper->map($clientDataClaims, new WDPEClientData());
        } catch (JsonMapper_Exception $e) {
            throw PacketHandlingException::wrap($e);
        }
        return $clientData;
    }

    /**
     * TODO: This is separated for the purposes of allowing plugins (like Specter) to hack it and bypass authentication.
     * In the future this won't be necessary.
     *
     * @throws InvalidArgumentException
     */
    protected function processLogin(LoginPacket $packet, bool $authRequired): void
    {
        $this->server->getAsyncPool()->submitTask(new ProcessLoginTask($packet->chainDataJwt->chain, $packet->clientDataJwt, $authRequired, $this->authCallback));
        $this->session->setHandler(null); //drop packets received during login verification
    }

    protected function isCompatibleProtocol(int $protocolVersion): bool
    {
//		return $protocolVersion == ProtocolInfo::ACCEPTED_PROTOCOL;
        return $protocolVersion === 534;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function safeB64Decode(string $base64, string $context): string
    {
        $result = base64_decode($base64, true);
        if ($result === false) {
            throw new InvalidArgumentException("$context: Malformed base64, cannot be decoded");
        }
        return $result;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromClientData(WDPEClientData $clientData): SkinData
    {
        /** @var SkinAnimation[] $animations */
        $animations = [];
        foreach ($clientData->AnimatedImageData as $k => $animation) {
            $animations[] = new SkinAnimation(
                new SkinImage(
                    $animation->ImageHeight,
                    $animation->ImageWidth,
                    self::safeB64Decode($animation->Image, "AnimatedImageData.$k.Image")
                ),
                $animation->Type,
                $animation->Frames,
                $animation->AnimationExpression
            );
        }
        return new SkinData(
            $clientData->SkinId,
            $clientData->PlayFabId,
            self::safeB64Decode($clientData->SkinResourcePatch, "SkinResourcePatch"),
            new SkinImage($clientData->SkinImageHeight, $clientData->SkinImageWidth, self::safeB64Decode($clientData->SkinData, "SkinData")),
            $animations,
            new SkinImage($clientData->CapeImageHeight, $clientData->CapeImageWidth, self::safeB64Decode($clientData->CapeData, "CapeData")),
            self::safeB64Decode($clientData->SkinGeometryData, "SkinGeometryData"),
            self::safeB64Decode($clientData->SkinGeometryDataEngineVersion, "SkinGeometryDataEngineVersion"), //yes, they actually base64'd the version!
            self::safeB64Decode($clientData->SkinAnimationData, "SkinAnimationData"),
            $clientData->CapeId,
            null,
            $clientData->ArmSize,
            $clientData->SkinColor,
            array_map(function (ClientDataPersonaSkinPiece $piece): PersonaSkinPiece {
                return new PersonaSkinPiece($piece->PieceId, $piece->PieceType, $piece->PackId, $piece->IsDefault, $piece->ProductId);
            }, $clientData->PersonaPieces),
            array_map(function (ClientDataPersonaPieceTintColor $tint): PersonaPieceTintColor {
                return new PersonaPieceTintColor($tint->PieceType, $tint->Colors);
            }, $clientData->PieceTintColors),
            true,
            $clientData->PremiumSkin,
            $clientData->PersonaSkin,
            $clientData->CapeOnClassicSkin,
            true, //assume this is true? there's no field for it ...
        );
    }
}
