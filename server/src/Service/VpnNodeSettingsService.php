<?php

namespace App\Service;

use App\Entity\NodeSettings;
use App\Repository\NodeSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Управление локальной ролью и сетевыми настройками этого узла (Клиент, Узел на цепочке или VPN-сервер).
 */
final class VpnNodeSettingsService
{
    public function __construct(
        private readonly NodeSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getSettings(): NodeSettings
    {
        return $this->repository->getOrCreate();
    }

    public function isServerMode(): bool
    {
        return $this->getSettings()->isServerMode();
    }

    public function isClientMode(): bool
    {
        return $this->getSettings()->isClientMode();
    }

    public function assertServerMode(string $action = 'Операция доступна только в режиме «Сервер»'): void
    {
        if (!$this->isServerMode()) {
            throw new AccessDeniedException(sprintf('%s: переведите узел в режим «Сервер».', $action));
        }
    }

    public function assertRelayCapable(string $action = 'Операция доступна только в ролях «Узел на цепочке» и «VPN-сервер»'): void
    {
        if (!$this->getSettings()->canRelay()) {
            throw new AccessDeniedException(sprintf('%s: смените роль узла на странице «Узел, роль и порты».', $action));
        }
    }

    /**
     * @param list<string>|null $dnsServers
     * @return list<string> Ошибки валидации (пустой массив при успехе)
     */
    public function validateNetworkParameters(
        ?string $publicHost,
        string $wanInterface,
        string $vpnSubnetCidr,
        ?array $dnsServers = null,
    ): array {
        $errors = [];

        if ($publicHost !== null && trim($publicHost) !== '') {
            $host = trim($publicHost);
            if (!preg_match('/^[a-zA-Z0-9._:-]{1,253}$/', $host)) {
                $errors[] = 'Некорректный внешний хост/IP: допустимы только буквы, цифры, точки, двоеточие и дефис.';
            }
        }

        if (!preg_match('/^[a-zA-Z0-9._:-]{1,32}$/', trim($wanInterface))) {
            $errors[] = 'Некорректное имя сетевого интерфейса (допустимо например eth0, ens3, en0).';
        }

        if (!self::isValidIpv4Cidr($vpnSubnetCidr)) {
            $errors[] = 'Подсеть VPN должна быть задана в формате IPv4 CIDR (например 10.44.0.0/24).';
        }

        if ($dnsServers !== null) {
            foreach ($dnsServers as $dns) {
                if (filter_var(trim($dns), FILTER_VALIDATE_IP) === false) {
                    $errors[] = sprintf('Некорректный DNS-адрес «%s».', $dns);
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string>|null $dnsServers
     */
    public function updateSettings(
        string $mode,
        string $nodeName,
        ?string $publicHost,
        string $wanInterface,
        string $vpnSubnetCidr,
        ?array $dnsServers,
        bool $blockIpv6,
        ?int $activeClientChainId,
        bool $autoApplyRuntime = true,
    ): NodeSettings {
        $errors = $this->validateNetworkParameters($publicHost, $wanInterface, $vpnSubnetCidr, $dnsServers);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $settings = $this->getSettings();
        $settings->setMode($mode);
        $settings->setNodeName($nodeName);
        $settings->setPublicHost($publicHost);
        $settings->setWanInterface($wanInterface);
        $settings->setVpnSubnetCidr($vpnSubnetCidr);
        if ($dnsServers !== null) {
            $settings->setDnsServers($dnsServers);
        }
        $settings->setBlockIpv6($blockIpv6);
        $settings->setActiveClientChainId($activeClientChainId);
        $settings->setAutoApplyRuntime($autoApplyRuntime);

        $this->entityManager->flush();

        return $settings;
    }

    public static function isValidIpv4Cidr(string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 3);
        if (count($parts) !== 2) {
            return false;
        }
        [$ip, $prefix] = $parts;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (!ctype_digit($prefix)) {
            return false;
        }
        $bits = (int) $prefix;

        return $bits >= 8 && $bits <= 32;
    }
}
