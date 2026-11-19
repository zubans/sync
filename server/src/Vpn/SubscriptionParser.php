<?php

namespace App\Vpn;

/**
 * Разбор ответа подписки провайдера — те же правила, что SubscriptionParser в CEP для macOS и CepLogic на Android:
 * тело — base64 (обычный или url-safe, с паддингом или без) либо текст по ссылке в строке; имя сервера — метка #…
 * ссылки без пробелов по краям, без метки — host:port; повторяющиеся имена получают номер.
 */
final class SubscriptionParser
{
    /** Панели выбирают формат ответа по User-Agent; с этим отдают простой список ссылок. */
    public const USER_AGENT = 'v2rayN/7.10.0';

    /** Разница между эпохой дат JSONEncoder на macOS (01.01.2001) и Unix-временем, в секундах. */
    public const APPLE_EPOCH = 978307200;

    /** @return list<array{name: string, uri: string}> */
    public static function parse(string $body): array
    {
        $raw = trim($body);
        $text = str_contains($raw, '://') ? $raw : (self::decodeBase64($raw) ?? $raw);
        $result = [];
        $names = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $uri = trim($line);
            $scheme = strstr($uri, '://', true);
            if ($scheme === false || $scheme === '') {
                continue;
            }
            $name = self::label($uri);
            if ($name === '') {
                $name = self::address($uri) ?? $uri;
            }
            $unique = $name;
            for ($n = 2; isset($names[$unique]); ++$n) {
                $unique = sprintf('%s (%d)', $name, $n);
            }
            $names[$unique] = true;
            $result[] = ['name' => $unique, 'uri' => $uri];
        }

        return $result;
    }

    private static function label(string $uri): string
    {
        $hash = strpos($uri, '#');

        return $hash === false ? '' : trim(rawurldecode(substr($uri, $hash + 1)));
    }

    /** host:port ссылки — запасной ключ, если провайдер переименовал сервер. */
    public static function address(string $uri): ?string
    {
        $parts = parse_url(preg_replace('/#.*$/s', '', $uri) ?? $uri);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
            return null;
        }

        return trim($parts['host'], '[]') . ':' . $parts['port'];
    }

    private static function decodeBase64(string $s): ?string
    {
        $b = strtr(preg_replace('/\s+/', '', $s) ?? '', '-_', '+/');
        $b .= str_repeat('=', (4 - strlen($b) % 4) % 4);
        $decoded = base64_decode($b, true);

        return $decoded === false || !mb_check_encoding($decoded, 'UTF-8') ? null : $decoded;
    }

    /** Протокол CEP для ссылки или null (trojan, ss, vmess… CEP не поддерживает). */
    public static function protocolOf(string $uri): ?string
    {
        return match (strtolower((string) strstr($uri, ':', true))) {
            'vless' => VpnProtocol::VLESS,
            'hy2', 'hysteria2' => VpnProtocol::HYSTERIA2,
            'hysteria' => VpnProtocol::HYSTERIA,
            default => null,
        };
    }

    /**
     * Remnawave без принятого HWID отдаёт заглушку «Приложение не поддерживается» с адресом 0.0.0.0.
     *
     * @param list<array{name: string, uri: string}> $servers
     */
    public static function isStub(array $servers): bool
    {
        if ($servers === []) {
            return false;
        }
        foreach ($servers as $server) {
            if (!str_starts_with((string) self::address($server['uri']), '0.0.0.0:')) {
                return false;
            }
        }

        return true;
    }

    /** Имя подписки из заголовка profile-title (бывает «base64:…»). */
    public static function title(?string $header): ?string
    {
        $h = trim((string) $header);
        if ($h === '') {
            return null;
        }

        return str_starts_with($h, 'base64:') ? (($t = self::decodeBase64(substr($h, 7))) === null ? null : trim($t)) : $h;
    }

    /** «upload=0; download=…; total=0; expire=…» → «использовано 237.5 ГБ из ∞, до 27.11.2026». */
    public static function userInfo(?string $header): ?string
    {
        $v = [];
        foreach (explode(';', (string) $header) as $part) {
            $kv = array_map('trim', explode('=', $part, 2));
            if (count($kv) === 2 && is_numeric($kv[1])) {
                $v[$kv[0]] = (int) $kv[1];
            }
        }
        if ($v === []) {
            return null;
        }
        $gb = static fn (int $b): string => sprintf('%.1f ГБ', $b / 1073741824);
        $used = ($v['upload'] ?? 0) + ($v['download'] ?? 0);
        $total = $v['total'] ?? 0;
        $s = sprintf('использовано %s из %s', $gb($used), $total > 0 ? $gb($total) : '∞');
        if (($v['expire'] ?? 0) > 0) {
            $s .= ', до ' . date('d.m.Y', $v['expire']);
        }

        return $s;
    }

    /** HWID устройства для панели: постоянный и не раскрывает сам идентификатор (sha256, 32 hex), как в клиентах CEP. */
    public static function hwid(string $seed): string
    {
        return substr(hash('sha256', 'cep-hwid:' . $seed), 0, 32);
    }

    /**
     * Сервер подписки для подключения: по имени, а если провайдер переименовал — по прежнему host:port.
     *
     * @param list<array{name: string, uri: string}> $servers
     *
     * @return array{name: string, uri: string}|null
     */
    public static function match(array $servers, string $name, ?string $previousUri): ?array
    {
        foreach ($servers as $server) {
            if ($server['name'] === $name) {
                return $server;
            }
        }
        $old = $previousUri !== null ? self::address($previousUri) : null;
        if ($old === null) {
            return null;
        }
        foreach ($servers as $server) {
            if (self::address($server['uri']) === $old) {
                return $server;
            }
        }

        return null;
    }
}
