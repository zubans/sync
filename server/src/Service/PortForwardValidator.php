<?php

namespace App\Service;

use App\Entity\PortForwardRule;
use App\Entity\VpnServerService;
use App\Repository\PortForwardRuleRepository;
use App\Repository\VpnServerServiceRepository;
use App\Vpn\VpnProtocol;

/**
 * Валидация правил проброса портов и серверных VPN-сервисов: проверка адресов,
 * диапазонов портов и взаимных конфликтов между правилами и демонами узла.
 */
final class PortForwardValidator
{
    public function __construct(
        private readonly PortForwardRuleRepository $ruleRepository,
        private readonly VpnServerServiceRepository $serviceRepository,
    ) {
    }

    /** @return list<string> */
    public function validateServerService(VpnServerService $svc): array
    {
        $errors = [];
        if (trim($svc->getName()) === '') {
            $errors[] = 'Укажите название серверного сервиса.';
        }

        $port = $svc->getListenPort();
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Порт прослушивания должен быть в диапазоне 1..65535.';
        }

        $subnet = $svc->getSubnetCidr();
        if ($subnet !== null && !VpnNodeSettingsService::isValidIpv4Cidr($subnet)) {
            $errors[] = sprintf('Некорректная подсеть сервиса «%s» (ожидается IPv4 CIDR, например 10.44.0.1/24).', $subnet);
        }

        $proto = $svc->getProto();
        if ($proto === VpnProtocol::ARGON4 && $svc->getMasterPassword() === null) {
            $errors[] = 'Для серверного сервиса Argon4 требуется мастер-пароль.';
        }
        if ($proto === VpnProtocol::TRANSIT) {
            if ($svc->isTransitBlind()) {
                if ($svc->getTransitTarget() === null) {
                    $errors[] = 'Для слепого транзитного узла обязательно укажите целевой адрес (Transit target, host:port).';
                }
            } elseif ($svc->getMasterPassword() === null) {
                $errors[] = 'Для L4-транзита без режима «слепой» задайте мастер-пароль (или включите слепой режим).';
            }
        }

        $mtu = $svc->getArgonPathMtu();
        if ($mtu !== 0 && ($mtu < 576 || $mtu > 9000)) {
            $errors[] = 'Внешний path_mtu должен быть 0 (автоподбор) или от 576 до 9000.';
        }
        if ($svc->getArgonBehaviorProfile() === 'video-call' && ($svc->getArgonProtocolVersion() === 4 || $svc->getTransportProto() === 'tcp')) {
            $errors[] = 'Профиль video-call поддерживается только для Argon4 v5 поверх UDP.';
        }

        if ($proto === VpnProtocol::VLESS && $svc->getRealityPrivateKey() !== null) {
            $derivedRealityPub = VpnProtocol::deriveX25519PublicKey($svc->getRealityPrivateKey(), true);
            if ($derivedRealityPub === null) {
                $errors[] = 'Некорректный приватный ключ VLESS Reality (ожидается 32-байтный ключ X25519 в base64url).';
            } elseif ($svc->getRealityPublicKey() !== null && $svc->getRealityPublicKey() !== $derivedRealityPub) {
                $errors[] = 'Публичный ключ VLESS Reality не соответствует приватному ключу.';
            }
        }

        if ($errors !== [] || !$svc->isEnabled()) {
            return $errors;
        }

        foreach ($this->serviceRepository->findEnabledOrdered() as $other) {
            if ($other->getId() !== null && $other->getId() === $svc->getId()) {
                continue;
            }
            if ($other->getListenPort() === $port && self::protocolsOverlap($svc->getTransportProto(), $other->getTransportProto())) {
                $errors[] = sprintf(
                    'Конфликт порта %d/%s с другим активным сервисом «%s».',
                    $port,
                    strtoupper($svc->getTransportProto()),
                    $other->getName(),
                );
            }
        }

        foreach ($this->ruleRepository->findEnabledOrdered() as $rule) {
            if ($port >= $rule->getListenPort() && $port <= $rule->getEffectiveEndPort()
                && self::protocolsOverlap($rule->getProtocol(), $svc->getTransportProto())) {
                $errors[] = sprintf(
                    'Порт %d занят активным правилом проброса портов «%s» (%s/%s).',
                    $port,
                    $rule->getName(),
                    $rule->getPortRangeDisplay(),
                    strtoupper($rule->getProtocol()),
                );
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function validate(PortForwardRule $rule): array
    {
        $errors = [];

        if (trim($rule->getName()) === '') {
            $errors[] = 'Укажите название правила проброса портов.';
        }

        $iface = $rule->getListenInterface();
        if ($iface !== null && !preg_match('/^[a-zA-Z0-9._:-]{1,32}$/', $iface)) {
            $errors[] = 'Некорректный входящий интерфейс (разрешены латиница, цифры, ., _, -, :).';
        }

        $startPort = $rule->getListenPort();
        if ($startPort < 1 || $startPort > 65535) {
            $errors[] = 'Входной порт должен быть в диапазоне 1..65535.';
        }

        $endPort = $rule->getListenPortEnd();
        if ($endPort !== null) {
            if ($endPort < 1 || $endPort > 65535) {
                $errors[] = 'Конечный порт диапазона должен быть от 1 до 65535.';
            } elseif ($endPort < $startPort) {
                $errors[] = 'Конечный порт диапазона не может быть меньше начального.';
            }
        }

        $targetPort = $rule->getTargetPort();
        if ($targetPort < 1 || $targetPort > 65535) {
            $errors[] = 'Целевой порт должен быть от 1 до 65535.';
        }

        if ($endPort !== null && $endPort > $startPort && $targetPort !== $startPort) {
            $errors[] = 'Для диапазона портов начальный целевой порт должен совпадать с начальным входным (1:1 DNAT).';
        }

        if (filter_var($rule->getTargetIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = sprintf('Некорректный целевой IPv4-адрес «%s».', $rule->getTargetIp());
        }

        $sourceCidr = $rule->getSourceCidr();
        if ($sourceCidr !== null && !VpnNodeSettingsService::isValidIpv4Cidr($sourceCidr) && filter_var($sourceCidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = sprintf('Некорректная исходная подсеть/IP «%s».', $sourceCidr);
        }

        if ($errors !== [] || !$rule->isEnabled()) {
            return $errors;
        }

        $this->checkOverlaps($rule, $errors);

        return $errors;
    }

    /** @param list<string> $errors */
    private function checkOverlaps(PortForwardRule $candidate, array &$errors): void
    {
        $candStart = $candidate->getListenPort();
        $candEnd = $candidate->getEffectiveEndPort();
        $candProto = $candidate->getProtocol();
        $candIface = $candidate->getListenInterface() ?? '*';

        foreach ($this->ruleRepository->findEnabledOrdered() as $other) {
            if ($other->getId() !== null && $other->getId() === $candidate->getId()) {
                continue;
            }
            $otherIface = $other->getListenInterface() ?? '*';
            if ($candIface !== '*' && $otherIface !== '*' && $candIface !== $otherIface) {
                continue;
            }
            if (!self::protocolsOverlap($candProto, $other->getProtocol())) {
                continue;
            }
            if ($candStart <= $other->getEffectiveEndPort() && $other->getListenPort() <= $candEnd) {
                $errors[] = sprintf(
                    'Конфликт портов %s с правилом «%s» (%s/%s).',
                    $candidate->getPortRangeDisplay(),
                    $other->getName(),
                    $other->getPortRangeDisplay(),
                    strtoupper($other->getProtocol()),
                );
            }
        }

        foreach ($this->serviceRepository->findEnabledOrdered() as $svc) {
            if (!$this->servicePortOverlaps($candidate, $svc)) {
                continue;
            }
            $errors[] = sprintf(
                'Порт %d занят активным серверным сервисом «%s» (%s).',
                $svc->getListenPort(),
                $svc->getName(),
                strtoupper($svc->getTransportProto()),
            );
        }
    }

    private function servicePortOverlaps(PortForwardRule $rule, VpnServerService $svc): bool
    {
        $port = $svc->getListenPort();
        if ($port < $rule->getListenPort() || $port > $rule->getEffectiveEndPort()) {
            return false;
        }

        return self::protocolsOverlap($rule->getProtocol(), $svc->getTransportProto());
    }

    private static function protocolsOverlap(string $a, string $b): bool
    {
        return $a === PortForwardRule::PROTO_BOTH
            || $b === PortForwardRule::PROTO_BOTH
            || $a === $b;
    }
}
