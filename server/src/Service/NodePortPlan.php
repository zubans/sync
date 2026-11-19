<?php

namespace App\Service;

use App\Entity\NodeSettings;
use App\Entity\PortForwardRule;
use App\Entity\RelayRoute;
use App\Entity\VpnServerService;
use App\Repository\PortForwardRuleRepository;
use App\Repository\RelayRouteRepository;
use App\Repository\VpnServerServiceRepository;
use App\Vpn\VpnProtocol;

/**
 * План портов узла: какие порты узел слушает снаружи, что за каждым стоит и куда уходит трафик.
 *
 * Это единственный источник правды о портах: по нему строится таблица «Порты узла» в админке,
 * раздел `ports` в desired-state.json (его читает скрипт применения на хосте, чтобы открыть
 * порты в firewall) и проверка конфликтов при сохранении служб, обёрток, пропусков и пробросов.
 * Учитывается только то, что реально работает в текущей роли узла: службы и пробросы —
 * в роли «VPN-сервер», правила пропуска — в ролях «Узел на цепочке» и «VPN-сервер».
 */
final class NodePortPlan
{
    public const KIND_SERVICE = 'service';
    public const KIND_ARGON_WRAP = 'argon_wrap';
    public const KIND_RELAY = 'relay';
    public const KIND_FORWARD = 'forward';

    public function __construct(
        private readonly VpnNodeSettingsService $settingsService,
        private readonly VpnServerServiceRepository $serviceRepository,
        private readonly RelayRouteRepository $relayRepository,
        private readonly PortForwardRuleRepository $forwardRepository,
    ) {
    }

    /**
     * @return list<array{
     *     key: string,
     *     kind: string,
     *     start: int,
     *     end: int,
     *     transport: string,
     *     name: string,
     *     listener: string,
     *     leadsTo: string,
     *     obfuscation: string,
     *     editRoute: string,
     *     entityId: int|null,
     *     iface: string|null
     * }>
     */
    public function build(?NodeSettings $settings = null): array
    {
        $settings ??= $this->settingsService->getSettings();
        $entries = [];

        if ($settings->isServerMode()) {
            foreach ($this->serviceRepository->findEnabledOrdered() as $svc) {
                $entries[] = $this->serviceEntry($svc);
                if ($svc->hasArgonWrap()) {
                    $entries[] = $this->wrapEntry($svc);
                }
            }
            foreach ($this->forwardRepository->findEnabledOrdered() as $rule) {
                $entries[] = $this->forwardEntry($rule);
            }
        }

        if ($settings->canRelay()) {
            foreach ($this->relayRepository->findEnabledOrdered() as $route) {
                $entries[] = $this->relayEntry($route);
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$a['start'], $a['key']] <=> [$b['start'], $b['key']]);

        return $entries;
    }

    /**
     * Пары записей плана, претендующих на один и тот же порт и транспорт.
     *
     * @return list<string>
     */
    public function findConflicts(?NodeSettings $settings = null): array
    {
        $entries = $this->build($settings);
        $messages = [];
        $count = count($entries);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $entries[$i]['iface'];
                $b = $entries[$j]['iface'];
                if ($a !== null && $b !== null && $a !== $b) {
                    continue;
                }
                if (self::ownerOf($entries[$i]['key']) !== self::ownerOf($entries[$j]['key'])
                    && self::overlaps($entries[$i], $entries[$j]['start'], $entries[$j]['end'], $entries[$j]['transport'])) {
                    $messages[] = sprintf(
                        'Порт %s занят дважды: «%s» и «%s».',
                        self::portLabel($entries[$j]),
                        $entries[$i]['name'],
                        $entries[$j]['name'],
                    );
                }
            }
        }

        return $messages;
    }

    /**
     * Проверяет, свободен ли порт (или диапазон) для объекта с ключом $ownerKey.
     * Собственные записи владельца (например, порт службы и порт её обёртки) пропускаются,
     * поэтому сравнивать их между собой должен вызывающий код. $iface задаётся только для
     * пробросов с явно указанным входным интерфейсом.
     *
     * @return list<string>
     */
    public function conflictsWith(string $ownerKey, int $start, int $end, string $transport, ?NodeSettings $settings = null, ?string $iface = null): array
    {
        $messages = [];
        foreach ($this->build($settings) as $entry) {
            if (self::ownerOf($entry['key']) === $ownerKey) {
                continue;
            }
            // Пробросы на разных явно заданных интерфейсах друг другу не мешают.
            if ($iface !== null && $entry['iface'] !== null && $iface !== $entry['iface']) {
                continue;
            }
            if (self::overlaps($entry, $start, $end, $transport)) {
                $messages[] = sprintf(
                    'Порт %s уже занят: %s «%s».',
                    $start === $end ? sprintf('%d/%s', $start, strtoupper($transport)) : sprintf('%d-%d/%s', $start, $end, strtoupper($transport)),
                    self::kindLabel($entry['kind']),
                    $entry['name'],
                );
            }
        }

        return $messages;
    }

    /**
     * Компактное представление для desired-state.json: скрипт на хосте открывает эти порты в firewall.
     *
     * @return list<array<string, mixed>>
     */
    public function exportForRuntime(?NodeSettings $settings = null): array
    {
        return array_map(static fn (array $e): array => [
            'kind' => $e['kind'],
            'name' => $e['name'],
            'port' => $e['start'],
            'portEnd' => $e['end'],
            'transport' => $e['transport'],
            'listener' => $e['listener'],
            'leadsTo' => $e['leadsTo'],
        ], $this->build($settings));
    }

    public static function serviceKey(VpnServerService $svc): string
    {
        return 'service:' . ($svc->getId() ?? 'new');
    }

    public static function relayKey(RelayRoute $route): string
    {
        return 'relay:' . ($route->getId() ?? 'new');
    }

    public static function forwardKey(PortForwardRule $rule): string
    {
        return 'forward:' . ($rule->getId() ?? 'new');
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_SERVICE => 'служба',
            self::KIND_ARGON_WRAP => 'обёртка Argon4 службы',
            self::KIND_RELAY => 'пропуск трафика',
            self::KIND_FORWARD => 'проброс порта',
            default => $kind,
        };
    }

    /** @param array{start: int, end: int, transport: string} $entry */
    public static function portLabel(array $entry): string
    {
        $range = $entry['start'] === $entry['end']
            ? (string) $entry['start']
            : sprintf('%d-%d', $entry['start'], $entry['end']);

        return sprintf('%s/%s', $range, match ($entry['transport']) {
            PortForwardRule::PROTO_BOTH => 'UDP+TCP',
            default => strtoupper($entry['transport']),
        });
    }

    /** @param array{start: int, end: int, transport: string} $entry */
    private static function overlaps(array $entry, int $start, int $end, string $transport): bool
    {
        if ($start > $entry['end'] || $entry['start'] > $end) {
            return false;
        }

        return $transport === PortForwardRule::PROTO_BOTH
            || $entry['transport'] === PortForwardRule::PROTO_BOTH
            || $transport === $entry['transport'];
    }

    /** Обёртка принадлежит своей службе: «service:5» и «service:5:wrap» — один владелец. */
    private static function ownerOf(string $key): string
    {
        return str_ends_with($key, ':wrap') ? substr($key, 0, -5) : $key;
    }

    /** @return array{key: string, kind: string, start: int, end: int, transport: string, name: string, listener: string, leadsTo: string, obfuscation: string, editRoute: string, entityId: int|null, iface: string|null} */
    private function serviceEntry(VpnServerService $svc): array
    {
        $proto = $svc->getProto();
        [$listener, $leadsTo, $obfuscation] = match ($proto) {
            VpnProtocol::ARGON4 => [
                'argon-tunnel-server (tunnel)',
                'расшифровка → подсеть ' . ($svc->getSubnetCidr() ?: '10.44.0.1/24') . ' → интернет (NAT)',
                'Argon4: расшифровывается здесь',
            ],
            VpnProtocol::TRANSIT => $svc->isTransitBlind()
                ? ['argon-tunnel-server (слепой transit)', 'без расшифровки → ' . ($svc->getTransitTarget() ?? '—'), 'Argon4: проходит насквозь']
                : ['argon-tunnel-server (L4 transit)', 'расшифровка → ' . ($svc->getTransitTarget() ?? ('разрешённые цели: ' . ($svc->getTransitAllow() ?? '—'))), 'Argon4: расшифровывается здесь'],
            default => [
                VpnProtocol::label($proto),
                'служба узла → интернет',
                $svc->hasArgonWrap() ? 'без обёртки (обёрнутый трафик — на порту ' . ($svc->getArgonWrapPort() ?? '—') . ')' : 'нет',
            ],
        };

        return [
            'key' => self::serviceKey($svc),
            'kind' => self::KIND_SERVICE,
            'start' => $svc->getListenPort(),
            'end' => $svc->getListenPort(),
            'transport' => $proto === VpnProtocol::TRANSIT ? PortForwardRule::PROTO_BOTH : $svc->getTransportProto(),
            'name' => $svc->getName(),
            'listener' => $listener,
            'leadsTo' => $leadsTo,
            'obfuscation' => $obfuscation,
            'editRoute' => 'admin_vpn_server_service_edit',
            'entityId' => $svc->getId(),
            'iface' => null,
        ];
    }

    /** @return array{key: string, kind: string, start: int, end: int, transport: string, name: string, listener: string, leadsTo: string, obfuscation: string, editRoute: string, entityId: int|null, iface: string|null} */
    private function wrapEntry(VpnServerService $svc): array
    {
        $port = $svc->getArgonWrapPort() ?? VpnProtocol::ARGON_DEFAULT_PORT;

        return [
            'key' => self::serviceKey($svc) . ':wrap',
            'kind' => self::KIND_ARGON_WRAP,
            'start' => $port,
            'end' => $port,
            'transport' => PortForwardRule::PROTO_BOTH,
            'name' => $svc->getName() . ' — обёртка Argon4',
            'listener' => 'argon-tunnel-server (L4 transit)',
            'leadsTo' => sprintf('расшифровка → %s на 127.0.0.1:%d/%s', VpnProtocol::label($svc->getProto()), $svc->getListenPort(), strtoupper($svc->getTransportProto())),
            'obfuscation' => 'Argon4: расшифровывается здесь',
            'editRoute' => 'admin_vpn_server_service_edit',
            'entityId' => $svc->getId(),
            'iface' => null,
        ];
    }

    /** @return array{key: string, kind: string, start: int, end: int, transport: string, name: string, listener: string, leadsTo: string, obfuscation: string, editRoute: string, entityId: int|null, iface: string|null} */
    private function relayEntry(RelayRoute $route): array
    {
        return [
            'key' => self::relayKey($route),
            'kind' => self::KIND_RELAY,
            'start' => $route->getListenPort(),
            'end' => $route->getListenPort(),
            'transport' => $route->getEffectiveTransport(),
            'name' => $route->getName(),
            'listener' => $route->isArgonBlind() ? 'argon-tunnel-server (слепой transit)' : 'iptables DNAT',
            'leadsTo' => 'без расшифровки → ' . $route->getTargetAddress(),
            'obfuscation' => $route->isArgonBlind() ? 'Argon4: проходит насквозь' : 'как есть (узел не вмешивается)',
            'editRoute' => 'admin_relay_route_edit',
            'entityId' => $route->getId(),
            'iface' => null,
        ];
    }

    /** @return array{key: string, kind: string, start: int, end: int, transport: string, name: string, listener: string, leadsTo: string, obfuscation: string, editRoute: string, entityId: int|null, iface: string|null} */
    private function forwardEntry(PortForwardRule $rule): array
    {
        $end = $rule->getEffectiveEndPort();
        $targetEnd = $rule->getTargetPort() + ($end - $rule->getListenPort());
        $target = $end > $rule->getListenPort()
            ? sprintf('%s:%d-%d', $rule->getTargetIp(), $rule->getTargetPort(), $targetEnd)
            : sprintf('%s:%d', $rule->getTargetIp(), $rule->getTargetPort());

        return [
            'key' => self::forwardKey($rule),
            'kind' => self::KIND_FORWARD,
            'start' => $rule->getListenPort(),
            'end' => $end,
            'transport' => $rule->getProtocol(),
            'name' => $rule->getName(),
            'listener' => 'iptables DNAT',
            'leadsTo' => $target,
            'obfuscation' => 'как есть',
            'editRoute' => 'admin_port_forward_edit',
            'entityId' => $rule->getId(),
            'iface' => $rule->getListenInterface(),
        ];
    }
}
