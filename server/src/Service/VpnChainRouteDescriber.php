<?php

namespace App\Service;

use App\Entity\VpnChain;
use App\Entity\VpnConnection;
use App\Vpn\VpnProtocol;

/**
 * Описывает путь трафика по цепочке участками «откуда → куда» и показывает, что видно на проводе
 * на каждом участке: обфусцирован ли трафик Argon4 и где он расшифровывается.
 *
 * Правило предметной области: клиент поднимает узлы по очереди, и подключение к узлу N идёт
 * внутри туннеля узла N-1. Поэтому на участке «узел N-1 → узел N» в открытой сети виден протокол
 * узла N. Слепой транзит ничего не расшифровывает: на участках до и после него виден протокол
 * следующего за ним узла.
 */
final class VpnChainRouteDescriber
{
    public function __construct(private readonly MtuProbeService $mtuProbe)
    {
    }

    /**
     * @return list<array{from: string, to: string, address: string, wire: string, obfuscated: bool, decryptedAt: string|null}>
     */
    public function describe(VpnChain $chain): array
    {
        $hops = $chain->getHops()->toArray();
        usort($hops, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
        $connections = array_values(array_filter(array_map(static fn ($h) => $h->getConnection(), $hops)));
        if ($connections === []) {
            return [];
        }

        $segments = [];
        $from = 'Устройство';
        foreach ($connections as $index => $conn) {
            $to = sprintf('Узел %d «%s»', $index + 1, $conn->getName());
            [$wire, $obfuscated, $decryptedHere] = $this->wireOf($conn, $connections[$index + 1] ?? null);
            $segments[] = [
                'from' => $from,
                'to' => $to,
                'address' => $this->addressOf($conn),
                'wire' => $wire,
                'obfuscated' => $obfuscated,
                'decryptedAt' => $decryptedHere ? $to : null,
            ];
            $from = $to;
        }

        $segments[] = [
            'from' => $from,
            'to' => 'Интернет',
            'address' => '',
            'wire' => 'исходный трафик устройства',
            'obfuscated' => false,
            'decryptedAt' => null,
        ];

        return $segments;
    }

    /** Есть ли в цепочке участок открытой сети без обфускации Argon4 (не считая выхода в интернет). */
    public function hasPlainSegment(VpnChain $chain): bool
    {
        $segments = $this->describe($chain);
        array_pop($segments);
        foreach ($segments as $segment) {
            if (!$segment['obfuscated']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: bool, 2: bool} описание протокола на проводе, обфусцирован ли он, расшифровывается ли Argon4 на этом узле
     */
    private function wireOf(VpnConnection $conn, ?VpnConnection $next): array
    {
        $proto = $conn->getProto();
        $label = VpnProtocol::label($proto);

        if ($proto === VpnProtocol::TRANSIT && $conn->getPassword() === null) {
            if ($next === null) {
                return ['слепой транзит без следующего узла', false, false];
            }
            [$nextWire, $nextObfuscated] = $this->wireOf($next, null);

            return [$nextWire . ' — узел пропускает, не расшифровывая', $nextObfuscated, false];
        }

        return match (true) {
            $proto === VpnProtocol::ARGON4 => ['Argon4', true, true],
            $proto === VpnProtocol::TRANSIT => ['Argon4 (L4-транзит)', true, true],
            $conn->isUseArgon() && VpnProtocol::supportsArgonUnderlay($proto) => [sprintf('Argon4, внутри — %s', $label), true, true],
            default => [sprintf('%s без обфускации Argon4', $label), false, false],
        };
    }

    private function addressOf(VpnConnection $conn): string
    {
        $endpoint = $this->mtuProbe->resolveConnectionEndpoint($conn);
        if (!($endpoint['ok'] ?? false)) {
            return '—';
        }
        $host = (string) $endpoint['host'];
        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $usesArgon = (bool) ($endpoint['usesArgon'] ?? false);
        $transport = $usesArgon ? 'UDP' : strtoupper((string) ($endpoint['transport'] ?? 'udp'));

        return sprintf('%s:%d/%s', $host, (int) $endpoint['port'], $transport);
    }
}
