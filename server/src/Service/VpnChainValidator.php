<?php

namespace App\Service;

use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Vpn\VpnProtocol;

/**
 * Проверка параметров клиентских подключений и цепочек CEP по тем же правилам,
 * что используются в клиентах macOS, Android и cep-cli.
 */
final class VpnChainValidator
{
    /** @return list<string> */
    public function validateConnection(VpnConnection $conn): array
    {
        $errors = [];
        $name = $conn->getName() !== '' ? $conn->getName() : 'Подключение';
        $proto = $conn->getProto();

        if (trim($conn->getName()) === '') {
            $errors[] = 'Укажите название подключения.';
        }
        if (!VpnProtocol::isSupported($proto)) {
            $errors[] = sprintf('Неподдерживаемый протокол «%s».', $proto);

            return $errors;
        }

        $this->validateTransportFields($conn, $name, $proto, $errors);
        $this->validateRoutes($conn, $name, $errors);
        $this->validateArgonOptions($conn, $name, $proto, $errors);

        return $errors;
    }

    /** @return list<string> */
    public function validateChain(VpnChain $chain): array
    {
        $errors = [];
        if (trim($chain->getName()) === '') {
            $errors[] = 'Укажите название цепочки.';
        }

        $hops = $chain->getHops()->toArray();
        if ($hops === []) {
            $errors[] = 'В цепочке должен быть хотя бы один узел.';

            return $errors;
        }

        usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
        $count = count($hops);

        foreach ($hops as $index => $hop) {
            $conn = $hop->getConnection();
            if ($conn === null) {
                $errors[] = sprintf('Шаг #%d не привязан к подключению.', $index + 1);
                continue;
            }
            if (!$conn->isEnabled()) {
                $errors[] = sprintf('Узел #%d «%s» отключён.', $index + 1, $conn->getName());
            }
            foreach ($this->validateConnection($conn) as $err) {
                $errors[] = sprintf('Узел #%d («%s»): %s', $index + 1, $conn->getName(), $err);
            }

            if ($conn->getProto() === VpnProtocol::TRANSIT) {
                if ($index === $count - 1) {
                    $errors[] = sprintf('Транзитный шлюз «%s» не может быть последним узлом цепочки.', $conn->getName());
                } else {
                    $nextConn = $hops[$index + 1]->getConnection();
                    if ($nextConn !== null) {
                        $this->validateTransitHop($conn, $nextConn, $errors);
                    }
                }
            }
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function validateTransportFields(VpnConnection $conn, string $name, string $proto, array &$errors): void
    {
        switch ($proto) {
            case VpnProtocol::ARGON4:
                if (!self::hasHost($conn->getServer())) {
                    $errors[] = sprintf('«%s»: укажите сервер Argon4 в формате host:port или host.', $name);
                }
                if ($conn->getPassword() === null) {
                    $errors[] = sprintf('«%s»: задайте мастер-пароль Argon4.', $name);
                }
                break;

            case VpnProtocol::TRANSIT:
                if (!self::hasHost($conn->getServer())) {
                    $errors[] = sprintf('«%s»: укажите адрес транзитного шлюза.', $name);
                }
                break;

            case VpnProtocol::VLESS:
            case VpnProtocol::HYSTERIA2:
            case VpnProtocol::HYSTERIA:
                $uri = trim((string) $conn->getUri());
                if ($uri === '') {
                    $errors[] = sprintf('«%s»: укажите URI подключения (%s://...).', $name, $proto);
                } else {
                    $scheme = parse_url($uri, PHP_URL_SCHEME);
                    $expectedSchemes = $proto === VpnProtocol::HYSTERIA2 ? ['hysteria2', 'hy2'] : [$proto];
                    if (!is_string($scheme) || !in_array(strtolower($scheme), $expectedSchemes, true)) {
                        $errors[] = sprintf('«%s»: URI должен начинаться со схемы %s://.', $name, implode(':// или ', $expectedSchemes));
                    }
                    $host = parse_url($uri, PHP_URL_HOST);
                    if (!is_string($host) || trim($host) === '') {
                        $errors[] = sprintf('«%s»: в URI отсутствует хост сервера.', $name);
                    }
                }
                break;

            case VpnProtocol::OPENCONNECT:
            case VpnProtocol::SSTP:
                if (!self::hasHost($conn->getServer())) {
                    $errors[] = sprintf('«%s»: укажите адрес сервера (%s).', $name, VpnProtocol::label($proto));
                }
                break;

            case VpnProtocol::OPENVPN:
            case VpnProtocol::AMNEZIAWG:
                if (trim((string) $conn->getConfig()) === '') {
                    $errors[] = sprintf('«%s»: вставьте текст конфигурации (%s).', $name, $proto === VpnProtocol::OPENVPN ? '.ovpn' : 'AmneziaWG/WireGuard');
                }
                break;
        }
    }

    /** @param list<string> $errors */
    private function validateRoutes(VpnConnection $conn, string $name, array &$errors): void
    {
        $routes = trim((string) $conn->getRoutes());
        if ($routes === '') {
            return;
        }
        $parts = preg_split('/[\s,;]+/', $routes) ?: [];
        foreach ($parts as $part) {
            $item = trim($part);
            if ($item === '') {
                continue;
            }
            if (!VpnNodeSettingsService::isValidIpv4Cidr($item) && filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $errors[] = sprintf('«%s»: некорректный маршрут «%s» (ожидается IPv4 или IPv4/маска).', $name, $item);
            }
        }
    }

    /** @param list<string> $errors */
    private function validateArgonOptions(VpnConnection $conn, string $name, string $proto, array &$errors): void
    {
        if ($conn->isUseArgon()) {
            if (!VpnProtocol::supportsArgonUnderlay($proto)) {
                $errors[] = sprintf('«%s»: надстройка Argon4 не используется с протоколом %s.', $name, $proto);
            } elseif ($conn->getArgonPassword() === null) {
                $errors[] = sprintf('«%s»: включена надстройка Argon4, но не задан её мастер-пароль.', $name);
            }
        }

        $isBlindTransit = $proto === VpnProtocol::TRANSIT && $conn->getPassword() === null;
        $usesArgon = $proto === VpnProtocol::ARGON4
            || ($conn->isUseArgon() && VpnProtocol::supportsArgonUnderlay($proto))
            || ($proto === VpnProtocol::TRANSIT && $conn->getPassword() !== null);

        $ver = $conn->getArgonProtocolVersion();
        $behavior = $conn->getArgonBehaviorProfile();

        // Внешний MTU проверяется для любого типа узла (включая слепой транзит и VLESS/WireGuard/OpenVPN).
        $mtu = $conn->getArgonPathMtu();
        if ($mtu !== null && ($mtu < 576 || $mtu > 9000)) {
            $errors[] = sprintf('«%s»: path_mtu должен быть в диапазоне 576..9000.', $name);
        }

        if ($isBlindTransit) {
            if ($behavior === 'video-call') {
                $errors[] = sprintf('«%s»: слепой транзит без пароля не поддерживает профиль video-call.', $name);
            }

            return;
        }

        if (!$usesArgon) {
            return;
        }

        if ($ver !== null && !in_array($ver, [4, 5], true)) {
            $errors[] = sprintf('«%s»: версия протокола Argon4 должна быть 4 или 5.', $name);
        }

        if ($behavior !== null) {
            if (!in_array($behavior, ['baseline', 'video-call'], true)) {
                $errors[] = sprintf('«%s»: неизвестный поведенческий профиль «%s».', $name, $behavior);
            } elseif ($ver === 4 && $behavior === 'video-call') {
                $errors[] = sprintf('«%s»: профиль video-call доступен только начиная с Argon4 v5.', $name);
            } elseif ($behavior === 'video-call' && $conn->isUseArgon()) {
                $innerProto = VpnProtocol::defaultTransportProto($proto, $conn->getConfig());
                if ($innerProto !== 'udp') {
                    $errors[] = sprintf('«%s»: профиль video-call в надстройке Argon4 поддерживается только для UDP-протоколов.', $name);
                }
            }
        }
    }

    /** @param list<string> $errors */
    private function validateTransitHop(VpnConnection $transit, VpnConnection $next, array &$errors): void
    {
        if ($transit->getPassword() === null) {
            $nextOk = $next->getProto() === VpnProtocol::ARGON4
                || $next->isUseArgon()
                || $next->getProto() === VpnProtocol::TRANSIT;
            if (!$nextOk) {
                $errors[] = sprintf(
                    '«%s»: слепой транзит без пароля поддерживает только следующий узел Argon4, Transit или узел с включённой Argon4-надстройкой.',
                    $transit->getName(),
                );
            }
        } elseif ($transit->getArgonBehaviorProfile() === 'video-call') {
            $nextTransport = $next->isUseArgon()
                ? 'udp'
                : VpnProtocol::defaultTransportProto($next->getProto(), $next->getConfig());
            if ($nextTransport !== 'udp') {
                $errors[] = sprintf(
                    '«%s»: профиль video-call в L4-транзите разрешён только когда следующий узел работает по UDP.',
                    $transit->getName(),
                );
            }
        }
    }

    private static function hasHost(?string $server): bool
    {
        if ($server === null || trim($server) === '') {
            return false;
        }
        $raw = trim($server);
        if (str_contains($raw, '://')) {
            $host = parse_url($raw, PHP_URL_HOST);

            return is_string($host) && trim($host) !== '';
        }

        return preg_match('/^[a-zA-Z0-9._\[\]:-]+$/', $raw) === 1;
    }
}
