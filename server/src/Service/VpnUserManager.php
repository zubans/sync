<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\VpnUser;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Repository\VpnServerServiceRepository;
use App\Repository\VpnUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Управление пользователями VPN-сервера и при необходимости учётными записями входа в панель sync.
 * Любое создание/изменение разрешено только когда узел переведён в режим «Сервер».
 */
final class VpnUserManager
{
    public function __construct(
        private readonly VpnNodeSettingsService $settingsService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly VpnUserRepository $vpnUserRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly VpnServerServiceRepository $serviceRepository,
        private readonly CepExportService $cepExport,
        private readonly ApiTokenRepository $apiTokenRepository,
    ) {
    }

    /** @return list<string> */
    public function validate(VpnUser $vpnUser): array
    {
        $errors = [];

        if (!$this->settingsService->isServerMode()) {
            $errors[] = 'Создание и изменение пользователей системы доступны только в режиме «Сервер».';

            return $errors;
        }

        $username = trim($vpnUser->getUsername());
        if ($username === '' || !preg_match('/^[a-zA-Z0-9._@-]{2,64}$/', $username)) {
            $errors[] = 'Логин пользователя должен содержать 2..64 символа (латиница, цифры, ., _, -, @).';
        } else {
            $dupUser = $this->vpnUserRepository->findOneBy(['username' => $username]);
            if ($dupUser !== null && $dupUser->getId() !== $vpnUser->getId()) {
                $errors[] = sprintf('Пользователь с логином «%s» уже существует.', $username);
            }
        }

        $dupClient = $this->vpnUserRepository->findOneBy(['clientId' => $vpnUser->getClientId()]);
        if ($dupClient !== null && $dupClient->getId() !== $vpnUser->getId()) {
            $errors[] = sprintf('Client ID «%s» уже используется другим пользователем.', $vpnUser->getClientId());
        }

        $staticIp = $vpnUser->getStaticVpnIp();
        if ($staticIp !== null && filter_var($staticIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = sprintf('Некорректный статический VPN IPv4-адрес «%s».', $staticIp);
        }

        if ($vpnUser->isGrantSystemAccess()) {
            if (strlen($vpnUser->getTunnelPassword()) < 8) {
                $errors[] = 'Для входа в веб-панель пароль туннеля/системы должен быть не короче 8 символов.';
            }
            $email = $vpnUser->getEffectiveSystemEmail();
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = sprintf('Некорректный email для системной учётной записи «%s».', $email);
            } else {
                $existingSys = $this->userRepository->findOneBy(['email' => mb_strtolower($email)]);
                $linkedSys = $vpnUser->getSystemUser();
                if ($existingSys !== null && ($linkedSys === null || $existingSys->getId() !== $linkedSys->getId())) {
                    $otherVpnUser = $this->vpnUserRepository->findOneBy(['systemUser' => $existingSys]);
                    if ($otherVpnUser !== null && $otherVpnUser->getId() !== $vpnUser->getId()) {
                        $errors[] = sprintf('Email «%s» уже привязан к пользователю VPN «%s».', $email, $otherVpnUser->getUsername());
                    }
                }
            }
        }

        return $errors;
    }

    public function cleanupSystemAccountOnDelete(VpnUser $vpnUser, ?User $currentAdmin = null): void
    {
        $linked = $vpnUser->getSystemUser();
        if ($linked === null) {
            return;
        }
        $vpnUser->setSystemUser(null);
        if ($currentAdmin !== null && $linked->getId() === $currentAdmin->getId()) {
            return;
        }
        $this->disableSystemAccount($linked);
    }

    public function prepareAndSyncSystemAccount(VpnUser $vpnUser, ?string $plainSystemPassword = null): void
    {
        $this->settingsService->assertServerMode('Создание или изменение пользователя системы');

        $errors = $this->validate($vpnUser);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        if (!$vpnUser->isGrantSystemAccess()) {
            $linked = $vpnUser->getSystemUser();
            if ($linked !== null) {
                $this->disableSystemAccount($linked);
                $vpnUser->setSystemUser(null);
            }

            return;
        }

        $email = mb_strtolower($vpnUser->getEffectiveSystemEmail());
        $account = $vpnUser->getSystemUser()
            ?? $this->userRepository->findOneBy(['email' => $email])
            ?? (new User())->setEmail($email);

        $account->setEmail($email);
        if (!$vpnUser->isEnabled()) {
            $this->disableSystemAccount($account);
            $this->entityManager->persist($account);
            $vpnUser->setSystemUser($account);

            return;
        }

        $account->setEnabled(true);
        $roles = $vpnUser->getSystemRole() === User::ROLE_ADMIN
            ? [User::ROLE_ADMIN]
            : [];
        $account->setRoles($roles);

        $secret = $plainSystemPassword !== null && $plainSystemPassword !== ''
            ? $plainSystemPassword
            : $vpnUser->getTunnelPassword();
        $account->setPassword($this->passwordHasher->hashPassword($account, $secret));
        $account->setPlainPassword(null);

        $this->entityManager->persist($account);
        $vpnUser->setSystemUser($account);
    }

    /**
     * Формирует персональный CEP profiles.json для конкретного VPN-пользователя по доступным ему сервисам.
     *
     * @return array<string, mixed>
     */
    public function buildPersonalCepStore(VpnUser $vpnUser): array
    {
        $this->settingsService->assertServerMode('Экспорт конфигурации пользователя');

        $settings = $this->settingsService->getSettings();
        $services = $vpnUser->getAllowedServices()->isEmpty()
            ? $this->serviceRepository->findEnabledOrdered()
            : $vpnUser->getAllowedServices()->toArray();

        $profiles = [];
        foreach ($services as $svc) {
            if (!$svc->isEnabled()) {
                continue;
            }
            $conn = $this->cepExport->createConnectionFromServerService($svc, $settings, $vpnUser);
            $profiles[] = $this->cepExport->serializeConnection($conn);
        }

        return [
            'profiles' => $profiles,
            'chains' => [],
            'activeChain' => null,
            'blockIPv6' => $settings->isBlockIpv6(),
            'dns' => $settings->getDnsServers(),
        ];
    }

    private function disableSystemAccount(User $user): void
    {
        $user->setEnabled(false);
        $user->setRoles([]);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->apiTokenRepository->revokeAllForUser($user);
    }
}
