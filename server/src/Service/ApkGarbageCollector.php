<?php

namespace App\Service;

use App\Repository\ApkBlobRepository;
use App\Repository\InstalledAppRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет APK, которые больше не нужны для восстановления: их нет ни в одном приложении,
 * установленном сейчас на каком-либо устройстве. Так уходят старые версии после обновления
 * приложения и копии удалённых приложений — сервер хранит только то, что стоит на телефонах.
 */
final class ApkGarbageCollector
{
    /** Недокачанные загрузки, которые не продолжали сутки, считаем брошенными. */
    private const STALE_UPLOAD_SECONDS = 86400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstalledAppRepository $apps,
        private readonly ApkBlobRepository $blobs,
        private readonly ApkStorage $storage,
    ) {
    }

    /**
     * @return array{apks: int, bytes: int, uploads: int} сколько удалено APK, байт и брошенных загрузок
     */
    public function collect(): array
    {
        $referenced = [];
        foreach ($this->apps->findBy(['removedAt' => null]) as $app) {
            foreach ($app->getFileHashes() as $sha256) {
                $referenced[$sha256] = true;
            }
        }

        $apks = $bytes = 0;
        foreach ($this->blobs->findAll() as $blob) {
            if (isset($referenced[$blob->getSha256()])) {
                continue;
            }
            $this->storage->delete($blob->getSha256());
            $this->em->remove($blob);
            ++$apks;
            $bytes += $blob->getSize();
        }
        $this->em->flush();

        return ['apks' => $apks, 'bytes' => $bytes, 'uploads' => $this->storage->deleteStaleUploads(self::STALE_UPLOAD_SECONDS)];
    }
}
