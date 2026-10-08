<?php

namespace App\Service;

use App\Dto\AppFileInput;
use App\Dto\InventoryInput;
use App\Entity\Device;
use App\Entity\InstalledApp;
use App\Entity\User;
use App\Repository\ApkBlobRepository;
use App\Repository\DeviceRepository;
use App\Repository\InstalledAppRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Список приложений устройства и решение, какие APK нужно загрузить.
 */
final class AppInventoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceRepository $devices,
        private readonly InstalledAppRepository $apps,
        private readonly ApkBlobRepository $blobs,
        #[Autowire('%env(int:APK_MAX_SIZE)%')]
        private readonly int $maxSize,
    ) {
    }

    /**
     * @return list<string> SHA-256 файлов, которые клиенту нужно загрузить
     */
    public function sync(User $user, InventoryInput $input): array
    {
        return $this->em->wrapInTransaction(function () use ($user, $input): array {
            $device = $this->devices->findOneBy(['user' => $user, 'installId' => $input->device->installId]);
            if ($device === null) {
                $device = new Device($user, $input->device->installId);
                $this->em->persist($device);
            }
            $device->setModel($input->device->model);

            $existing = $device->getId() === null ? [] : $this->apps->findByDeviceIndexed($device);
            $wanted = [];

            foreach ($input->apps as $appInput) {
                $app = $existing[$appInput->packageName] ?? null;
                unset($existing[$appInput->packageName]);
                if ($app === null) {
                    $app = new InstalledApp($device, $appInput->packageName);
                    $this->em->persist($app);
                }
                $files = array_map(static fn (AppFileInput $f) => ['name' => $f->name, 'sha256' => $f->sha256, 'size' => $f->size], $appInput->files);
                // Обновилось — текущая версия становится прошлой (для отката), но только если её APK
                // сохранён: иначе держим прежнюю прошлую, чтобы не остаться без рабочей копии.
                if (!$app->isSameVersion($appInput->versionCode, $files) && $this->isStored($app->getFileHashes())) {
                    $app->rememberCurrentAsPrevious();
                }
                $app->update(
                    $appInput->label,
                    $appInput->versionName,
                    $appInput->versionCode,
                    $appInput->installer,
                    $appInput->signingSha256,
                    $files,
                );

                if ($this->shouldBackUp($app)) {
                    foreach ($appInput->files as $file) {
                        $wanted[$file->sha256] = true;
                    }
                }
            }

            foreach ($existing as $removed) {
                $removed->markRemoved();
            }
            $this->em->flush();

            $stored = array_map(fn ($b) => $b->getSha256(), $this->blobs->findBy(['sha256' => array_keys($wanted)]));

            return array_values(array_diff(array_map('strval', array_keys($wanted)), $stored));
        });
    }

    /** @param list<string> $hashes */
    private function isStored(array $hashes): bool
    {
        return $hashes !== [] && \count($this->blobs->findBy(['sha256' => $hashes])) === \count(array_unique($hashes));
    }

    public function shouldBackUp(InstalledApp $app): bool
    {
        return $app->getTotalSize() <= $this->maxSize;
    }
}
