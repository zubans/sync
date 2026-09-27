<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Хранилище APK на диске, по содержимому: <dir>/ab/cd/<sha256>.apk.
 *
 * Загрузка идёт частями в файл <dir>/uploads/<user>-<sha256>.part и возобновляется с его текущего размера.
 * Для продакшена хранилище стоит вынести в S3-совместимое (интерфейс этого класса это позволяет).
 */
final class ApkStorage
{
    public function __construct(
        #[Autowire('%env(resolve:APK_STORAGE_DIR)%')]
        private readonly string $dir,
    ) {
    }

    public function has(string $sha256): bool
    {
        return is_file($this->path($sha256));
    }

    public function path(string $sha256): string
    {
        return \sprintf('%s/%s/%s/%s.apk', $this->dir, substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
    }

    /** Сколько байт уже загружено (с этого места клиент продолжает). */
    public function uploadedBytes(int $userId, string $sha256): int
    {
        $part = $this->partPath($userId, $sha256);
        clearstatcache(true, $part);

        return is_file($part) ? (int) filesize($part) : 0;
    }

    /**
     * Дописывает часть файла. offset должен совпадать с уже загруженным объёмом.
     *
     * @param resource $stream
     *
     * @return int новый объём загруженного
     */
    public function append(int $userId, string $sha256, int $offset, $stream, int $maxSize): int
    {
        $part = $this->partPath($userId, $sha256);
        $this->ensureDir(\dirname($part));

        $handle = fopen($part, 'c');
        if ($handle === false || !flock($handle, \LOCK_EX)) {
            throw new \RuntimeException('Не удалось открыть файл загрузки.');
        }
        try {
            $current = fstat($handle)['size'];
            if ($offset !== $current) {
                throw new UploadOffsetMismatch($current);
            }
            fseek($handle, $current);
            $written = stream_copy_to_stream($stream, $handle, $maxSize - $current + 1);
            if ($current + $written > $maxSize) {
                ftruncate($handle, $current);
                throw new \LengthException('Файл больше заявленного размера.');
            }
            fflush($handle);

            return $current + $written;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Проверяет хэш загруженного и переносит файл в хранилище.
     *
     * @return bool false — хэш не совпал, загрузка сброшена
     */
    public function complete(int $userId, string $sha256): bool
    {
        $part = $this->partPath($userId, $sha256);
        if (!is_file($part) || hash_file('sha256', $part) !== $sha256) {
            @unlink($part);

            return false;
        }

        $target = $this->path($sha256);
        $this->ensureDir(\dirname($target));
        if (!rename($part, $target)) {
            throw new \RuntimeException('Не удалось сохранить APK.');
        }

        return true;
    }

    private function partPath(int $userId, string $sha256): string
    {
        return \sprintf('%s/uploads/%d-%s.part', $this->dir, $userId, $sha256);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог $dir.");
        }
    }
}
