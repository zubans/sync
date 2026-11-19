<?php

namespace App\Vpn;

/**
 * Каталог всех протоколов VPN и прокси, поддерживаемых клиентами CEP и серверной частью sync.
 * Коды протоколов совпадают со схемой profiles.json (macOS, Android, cep-cli).
 */
final class VpnProtocol
{
    public const ARGON4 = 'argon4';
    public const TRANSIT = 'transit';
    public const VLESS = 'vless';
    public const HYSTERIA2 = 'hysteria2';
    public const HYSTERIA = 'hysteria';
    public const OPENCONNECT = 'openconnect';
    public const OPENVPN = 'openvpn';
    public const AMNEZIAWG = 'amneziawg';
    public const SSTP = 'sstp';

    /** Порт Argon4 по умолчанию: служба Argon4, транзит и обёртка Argon4 у клиентов CEP. */
    public const ARGON_DEFAULT_PORT = 51820;

    /** @var list<string> Все поддерживаемые в разработке типы подключений. */
    public const ALL = [
        self::ARGON4,
        self::TRANSIT,
        self::VLESS,
        self::HYSTERIA2,
        self::HYSTERIA,
        self::OPENCONNECT,
        self::OPENVPN,
        self::AMNEZIAWG,
        self::SSTP,
    ];

    /** @var array<string, string> Человекочитаемые подписи протоколов для админки (значение => метка). */
    public const CHOICES = [
        'Argon4 (UDP-туннель обфускации v4/v5)' => self::ARGON4,
        'Argon4 Transit (промежуточный узел: слепой или L4 с паролем)' => self::TRANSIT,
        'VLESS (URI: Reality / TLS / WS / gRPC)' => self::VLESS,
        'Hysteria 2 (URI: QUIC / Salamander)' => self::HYSTERIA2,
        'Hysteria v1 (URI: UDP / WeChat / FakeTCP)' => self::HYSTERIA,
        'OpenConnect (AnyConnect / GlobalProtect / Pulse / Fortinet / Juniper / Array)' => self::OPENCONNECT,
        'OpenVPN (.ovpn профиль TCP/UDP)' => self::OPENVPN,
        'AmneziaWG (Конфиг WireGuard с обфускацией)' => self::AMNEZIAWG,
        'SSTP (HTTPS-туннель ms-sstp)' => self::SSTP,
    ];

    /** @var array<string, string> Краткие названия протоколов. */
    public const LABELS = [
        self::ARGON4 => 'Argon4',
        self::TRANSIT => 'Argon4 Transit',
        self::VLESS => 'VLESS',
        self::HYSTERIA2 => 'Hysteria 2',
        self::HYSTERIA => 'Hysteria v1',
        self::OPENCONNECT => 'OpenConnect',
        self::OPENVPN => 'OpenVPN',
        self::AMNEZIAWG => 'AmneziaWG',
        self::SSTP => 'SSTP',
    ];

    /** @var array<string, string> Диалекты OpenConnect для формы и профиля. */
    public const OPENCONNECT_FLAVORS = [
        'Cisco AnyConnect (по умолчанию)' => 'anyconnect',
        'Palo Alto GlobalProtect' => 'gp',
        'Juniper / Ivanti Network Connect' => 'nc',
        'Pulse Connect Secure' => 'pulse',
        'Fortinet FortiGate' => 'fortinet',
        'Array Networks' => 'array',
    ];

    /** @var array<string, string> Поведенческие профили Argon4 v5. */
    public const BEHAVIOR_PROFILES = [
        'Базовый (baseline — адаптивный сбалансированный трафик)' => 'baseline',
        'Маскировка под видеозвонок (video-call — только поверх UDP)' => 'video-call',
    ];

    /** @var array<string, string> Профили padding Argon4 v5. */
    public const PADDING_PROFILES = [
        'Сбалансированный (balanced)' => 'balanced',
        'Компактный (compact)' => 'compact',
        'Широкий (wide)' => 'wide',
        'Пользовательский (custom)' => 'custom',
    ];

    public static function isSupported(string $proto): bool
    {
        return in_array($proto, self::ALL, true);
    }

    public static function supportsArgonUnderlay(string $proto): bool
    {
        return $proto !== self::ARGON4 && $proto !== self::TRANSIT;
    }

    public static function defaultTransportProto(string $proto, ?string $rawConfig = null): string
    {
        return match ($proto) {
            self::ARGON4, self::TRANSIT, self::HYSTERIA2, self::HYSTERIA, self::AMNEZIAWG => 'udp',
            self::OPENVPN => self::detectOpenVpnProto($rawConfig),
            default => 'tcp',
        };
    }

    public static function defaultPort(string $proto): int
    {
        return match ($proto) {
            self::ARGON4, self::TRANSIT => 51820,
            self::VLESS, self::HYSTERIA2, self::OPENCONNECT, self::SSTP => 443,
            self::HYSTERIA => 36712,
            self::OPENVPN => 1194,
            self::AMNEZIAWG => 51821,
            default => 443,
        };
    }

    public static function label(string $proto): string
    {
        return self::LABELS[$proto] ?? $proto;
    }

    public static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function generateSecret(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Генерирует пару ключей X25519 (RFC 7748) для WireGuard / AmneziaWG в стандартном base64 (44 символа).
     *
     * @return array{private: string, public: string}
     */
    public static function generateX25519Keypair(): array
    {
        $privRaw = self::clampX25519PrivateKey(random_bytes(32));
        $pubRaw = sodium_crypto_scalarmult_base($privRaw);

        return [
            'private' => base64_encode($privRaw),
            'public' => base64_encode($pubRaw),
        ];
    }

    /**
     * Генерирует пару ключей X25519 для VLESS Reality (unpadded base64url, формат xray/sing-box).
     *
     * @return array{private: string, public: string}
     */
    public static function generateRealityKeypair(): array
    {
        $privRaw = self::clampX25519PrivateKey(random_bytes(32));
        $pubRaw = sodium_crypto_scalarmult_base($privRaw);

        return [
            'private' => self::encodeBase64Url($privRaw),
            'public' => self::encodeBase64Url($pubRaw),
        ];
    }

    /**
     * Вычисляет публичный ключ X25519 из приватного ключа (поддерживает base64 и base64url).
     */
    public static function deriveX25519PublicKey(?string $privateKey, bool $base64Url = false): ?string
    {
        $privRaw = self::decodeX25519Key($privateKey);
        if ($privRaw === null) {
            return null;
        }
        $pubRaw = sodium_crypto_scalarmult_base(self::clampX25519PrivateKey($privRaw));

        return $base64Url ? self::encodeBase64Url($pubRaw) : base64_encode($pubRaw);
    }

    /**
     * Декодирует 32-байтный ключ X25519 из стандартного base64 или base64url.
     */
    public static function decodeX25519Key(?string $encoded): ?string
    {
        if ($encoded === null) {
            return null;
        }
        $clean = trim($encoded);
        if ($clean === '') {
            return null;
        }
        $std = strtr($clean, '-_', '+/');
        $pad = strlen($std) % 4;
        if ($pad !== 0) {
            $std .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($std, true);

        return is_string($raw) && strlen($raw) === 32 ? $raw : null;
    }

    /**
     * Детерминированно вычисляет согласованную X25519-пару клиента AmneziaWG для пользователя
     * (или для базового клиентского профиля сервиса при $clientSeed === null), чтобы клиентский
     * [Interface] PrivateKey всегда соответствовал [Peer] PublicKey в конфигурации сервера.
     *
     * @return array{private: string, public: string}
     */
    public static function deriveAmneziaWgClientKeypair(
        ?string $clientId,
        ?string $clientSecret,
        ?string $fallbackPrivateKey = null,
        string $serviceSeed = 'sync-awg',
    ): array {
        if ($clientId !== null && $clientId !== '' && $clientSecret !== null && $clientSecret !== '') {
            $privRaw = self::clampX25519PrivateKey(hash('sha256', 'awg-peer:' . $clientId . ':' . $clientSecret, true));

            return [
                'private' => base64_encode($privRaw),
                'public' => base64_encode(sodium_crypto_scalarmult_base($privRaw)),
            ];
        }

        $decoded = self::decodeX25519Key($fallbackPrivateKey);
        $privRaw = $decoded !== null
            ? self::clampX25519PrivateKey($decoded)
            : self::clampX25519PrivateKey(hash('sha256', 'awg-default-client:' . $serviceSeed, true));

        return [
            'private' => base64_encode($privRaw),
            'public' => base64_encode(sodium_crypto_scalarmult_base($privRaw)),
        ];
    }

    private static function clampX25519PrivateKey(string $bytes): string
    {
        $bytes[0] = chr(ord($bytes[0]) & 248);
        $bytes[31] = chr((ord($bytes[31]) & 127) | 64);

        return $bytes;
    }

    private static function encodeBase64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function detectOpenVpnProto(?string $rawConfig): string
    {
        if ($rawConfig === null || trim($rawConfig) === '') {
            return 'udp';
        }
        if (preg_match('/^\s*proto\s+tcp/im', $rawConfig) === 1) {
            return 'tcp';
        }

        return 'udp';
    }
}
