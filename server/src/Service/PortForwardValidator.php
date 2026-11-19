<?php

namespace App\Service;

use App\Entity\PortForwardRule;
use App\Entity\RelayRoute;
use App\Entity\VpnServerService;
use App\Vpn\VpnProtocol;

/**
 * Валидация всего, что занимает порты узла: серверных служб (и их обёрток Argon4), правил
 * пропуска трафика и правил проброса портов. Конфликты портов проверяются по общему плану
 * портов узла (NodePortPlan), поэтому служба, обёртка, пропуск и проброс не могут занять один порт.
 */
final class PortForwardValidator
{
    public function __construct(
        private readonly NodePortPlan $portPlan,
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

        $this->validateArgonWrap($svc, $errors);

        if ($errors !== [] || !$svc->isEnabled()) {
            return $errors;
        }

        $key = NodePortPlan::serviceKey($svc);
        $transport = $proto === VpnProtocol::TRANSIT ? PortForwardRule::PROTO_BOTH : $svc->getTransportProto();
        foreach ($this->portPlan->conflictsWith($key, $port, $port, $transport) as $message) {
            $errors[] = $message;
        }
        if ($svc->hasArgonWrap()) {
            $wrapPort = $svc->getArgonWrapPort() ?? VpnProtocol::ARGON_DEFAULT_PORT;
            foreach ($this->portPlan->conflictsWith($key, $wrapPort, $wrapPort, PortForwardRule::PROTO_BOTH) as $message) {
                $errors[] = 'Обёртка Argon4: ' . $message;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function validateRelayRoute(RelayRoute $route): array
    {
        $errors = [];
        if (trim($route->getName()) === '') {
            $errors[] = 'Укажите название правила пропуска.';
        }

        $port = $route->getListenPort();
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Входной порт должен быть в диапазоне 1..65535.';
        }
        $targetPort = $route->getTargetPort();
        if ($targetPort < 1 || $targetPort > 65535) {
            $errors[] = 'Порт следующего узла должен быть в диапазоне 1..65535.';
        }

        $host = $route->getTargetHost();
        if ($host === '') {
            $errors[] = 'Укажите адрес следующего узла.';
        } elseif ($route->isArgonBlind()) {
            if (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/', $host)) {
                $errors[] = sprintf('Некорректный адрес следующего узла «%s» (ожидается IP или домен).', $host);
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = sprintf('Для DNAT адрес следующего узла задаётся IPv4-адресом, а не «%s».', $host);
        }

        $source = $route->getSourceCidr();
        if ($source !== null) {
            if ($route->isArgonBlind()) {
                $errors[] = 'Ограничение по источнику поддерживается только для DNAT.';
            } elseif (!VpnNodeSettingsService::isValidIpv4Cidr($source) && filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $errors[] = sprintf('Некорректная исходная подсеть/IP «%s».', $source);
            }
        }

        $mtu = $route->getArgonPathMtu();
        if ($route->isArgonBlind() && $mtu !== 0 && ($mtu < 576 || $mtu > 9000)) {
            $errors[] = 'Внешний MTU должен быть 0 (автоподбор) или от 576 до 9000.';
        }

        if ($errors !== [] || !$route->isEnabled()) {
            return $errors;
        }

        foreach ($this->portPlan->conflictsWith(NodePortPlan::relayKey($route), $port, $port, $route->getEffectiveTransport()) as $message) {
            $errors[] = $message;
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function validateArgonWrap(VpnServerService $svc, array &$errors): void
    {
        if (!$svc->isArgonWrapEnabled()) {
            return;
        }
        if (!VpnProtocol::supportsArgonUnderlay($svc->getProto())) {
            $errors[] = 'Обёртка Argon4 не нужна службам Argon4 и Transit: они уже принимают Argon4.';

            return;
        }
        if ($svc->getArgonWrapPassword() === null) {
            $errors[] = 'Для обёртки Argon4 задайте мастер-пароль обёртки.';
        }
        // Обёртка отдаёт расшифрованный поток службе на 127.0.0.1, поэтому служба должна слушать loopback.
        if (!in_array($svc->getListenHost(), ['0.0.0.0', '127.0.0.1', '::'], true)) {
            $errors[] = sprintf('Обёртка Argon4 передаёт трафик службе на 127.0.0.1, а служба слушает только %s: укажите Listen IP 0.0.0.0.', $svc->getListenHost());
        }
        $wrapPort = $svc->getArgonWrapPort() ?? VpnProtocol::ARGON_DEFAULT_PORT;
        if ($wrapPort < 1 || $wrapPort > 65535) {
            $errors[] = 'Порт обёртки Argon4 должен быть в диапазоне 1..65535.';
        } elseif ($wrapPort === $svc->getListenPort()) {
            $errors[] = sprintf('Порт обёртки Argon4 (%d) совпадает с портом самой службы: обёртке нужен отдельный порт.', $wrapPort);
        }
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
        foreach ($this->portPlan->conflictsWith(
            NodePortPlan::forwardKey($candidate),
            $candidate->getListenPort(),
            $candidate->getEffectiveEndPort(),
            $candidate->getProtocol(),
            iface: $candidate->getListenInterface(),
        ) as $message) {
            $errors[] = $message;
        }
    }
}
