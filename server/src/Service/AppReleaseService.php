<?php

namespace App\Service;

use App\Entity\AppRelease;
use App\Repository\AppReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Сборки Android-клиента: публикация (файл кладётся в каталог сервера, версия становится активной),
 * переключение активной версии и удаление старых сборок. Путь к файлу задаёт сервер: клиент
 * получает только ссылку на скачивание.
 */
final class AppReleaseService
{
    /** Сколько сборок хранить: активная и прошлые для отката. Более старые удаляются при публикации. */
    public const KEEP = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppReleaseRepository $releases,
        #[Autowire('%env(resolve:APP_RELEASE_DIR)%')]
        private readonly string $dir,
    ) {
    }

    public function path(AppRelease $release): string
    {
        return \sprintf('%s/contactsync-%d.apk', $this->dir, $release->getVersionCode());
    }

    /**
     * Копирует APK в каталог сборок и делает версию активной.
     *
     * @throws \InvalidArgumentException файла нет или версия ниже активной
     */
    public function publish(string $apk, int $versionCode, string $versionName, string $sourceRevision, bool $activate = true): AppRelease
    {
        if (!is_file($apk) || !is_readable($apk)) {
            throw new \InvalidArgumentException(\sprintf('Файл %s не найден.', $apk));
        }
        if ($versionCode < 1) {
            throw new \InvalidArgumentException('versionCode должен быть положительным.');
        }
        $active = $this->releases->findActive();
        if ($activate && $active !== null && $active->getVersionCode() > $versionCode) {
            // Иначе телефоны с новой версией не получат обновлений, пока номер не догонит.
            throw new \InvalidArgumentException(\sprintf('Активна более новая версия %s.', $active));
        }

        $sha256 = (string) hash_file('sha256', $apk);
        $size = (int) filesize($apk);
        $release = $this->releases->findOneBy(['versionCode' => $versionCode]);
        if ($release === null) {
            $release = new AppRelease($versionCode, $versionName, $sourceRevision, $sha256, $size);
            $this->em->persist($release);
        } else {
            $release->replaceBuild($versionName, $sourceRevision, $sha256, $size);
        }

        $target = $this->path($release);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог сборок.');
        }
        // Копия во временный файл и переименование: скачивающие не увидят недописанный APK.
        $tmp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (!copy($apk, $tmp) || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось сохранить APK.');
        }

        if ($activate) {
            $this->activate($release);
        }
        $this->em->flush();
        $this->prune();

        return $release;
    }

    public function activate(AppRelease $release): void
    {
        foreach ($this->releases->findBy(['active' => true]) as $current) {
            $current->setActive(false);
        }
        // Снять старую отметку до новой: иначе на миг активных было бы две.
        $this->em->flush();
        $release->setActive(true);
        $this->em->flush();
    }

    public function delete(AppRelease $release): void
    {
        if ($release->isActive()) {
            throw new \InvalidArgumentException('Активную версию удалить нельзя — сначала сделайте активной другую.');
        }
        @unlink($this->path($release));
        $this->em->remove($release);
        $this->em->flush();
    }

    /** Удаляет сборки сверх KEEP самых новых; активная остаётся всегда. */
    private function prune(): void
    {
        foreach (\array_slice($this->releases->findNewestFirst(), self::KEEP) as $old) {
            if (!$old->isActive()) {
                $this->delete($old);
            }
        }
    }
}
