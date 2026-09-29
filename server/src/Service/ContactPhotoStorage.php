<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Фото контактов на диске, по содержимому: <dir>/ab/<sha256>. Одинаковые фото хранятся один раз.
 */
final class ContactPhotoStorage
{
    public const MAX_SIZE = 5 * 1024 * 1024;

    /** Форматы, которые отдаёт ContactsProvider и принимает обратно. */
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        #[Autowire('%env(resolve:CONTACT_PHOTO_DIR)%')]
        private readonly string $dir,
    ) {
    }

    public function has(string $sha256): bool
    {
        return is_file($this->path($sha256));
    }

    public function path(string $sha256): string
    {
        return \sprintf('%s/%s/%s', $this->dir, substr($sha256, 0, 2), $sha256);
    }

    /**
     * Сохраняет фото, если его содержимое совпадает с заявленным хэшем и это изображение.
     *
     * @return string|null текст ошибки или null, если сохранено
     */
    public function store(string $sha256, string $bytes): ?string
    {
        if (\strlen($bytes) > self::MAX_SIZE) {
            return 'Фото больше 5 МБ.';
        }
        if (hash('sha256', $bytes) !== $sha256) {
            return 'Хэш фото не совпадает.';
        }
        if (!\in_array((new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes), self::MIME_TYPES, true)) {
            return 'Это не изображение JPEG, PNG или WebP.';
        }

        $path = $this->path($sha256);
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o775, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException('Не удалось создать каталог для фото.');
        }
        // Пишем во временный файл и переименовываем: читатели не увидят недописанное фото.
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $bytes);
        rename($tmp, $path);

        return null;
    }

    public function mimeType(string $sha256): string
    {
        return (new \finfo(\FILEINFO_MIME_TYPE))->file($this->path($sha256)) ?: 'application/octet-stream';
    }
}
