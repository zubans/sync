<?php

namespace App\Controller\Api;

use App\Dto\InventoryInput;
use App\Entity\ApkBlob;
use App\Entity\InstalledApp;
use App\Entity\User;
use App\Repository\ApkBlobRepository;
use App\Repository\InstalledAppRepository;
use App\Service\ApkGarbageCollector;
use App\Service\ApkStorage;
use App\Service\AppInventoryService;
use App\Service\UploadOffsetMismatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Резервные копии приложений: список установленных, загрузка APK частями, скачивание для восстановления.
 */
#[Route('/api')]
final class AppController extends AbstractController
{
    private const SHA256 = '[0-9a-f]{64}';

    public function __construct(
        private readonly InstalledAppRepository $apps,
        private readonly ApkStorage $storage,
        private readonly AppInventoryService $inventory,
    ) {
    }

    /**
     * Список приложений устройства. В ответе — хэши APK, которых на сервере ещё нет.
     * После сверки удаляются APK, которые больше нигде не установлены (старые версии, удалённые приложения).
     */
    #[Route('/apps/inventory', name: 'api_apps_inventory', methods: ['POST'])]
    public function inventory(
        #[CurrentUser] User $user,
        #[MapRequestPayload] InventoryInput $input,
        AppInventoryService $inventory,
        ApkGarbageCollector $collector,
    ): JsonResponse {
        $missing = $inventory->sync($user, $input);
        $collector->collect();

        return $this->json(['missing' => $missing]);
    }

    /**
     * Приложения пользователя для восстановления: по каждому пакету — самая свежая версия
     * среди устройств, где он ещё установлен, и прошлая сохранённая версия для отката.
     */
    #[Route('/apps', name: 'api_apps', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, ApkBlobRepository $blobs): JsonResponse
    {
        /** @var array<string, list<InstalledApp>> $byPackage */
        $byPackage = [];
        foreach ($this->apps->findByUser($user) as $app) {
            if ($app->getRemovedAt() === null) {
                $byPackage[$app->getPackageName()][] = $app;
            }
        }

        $hashes = [];
        foreach ($byPackage as $apps) {
            foreach ($apps as $app) {
                array_push($hashes, ...$app->getAllFileHashes());
            }
        }
        $stored = array_flip(array_map(static fn (ApkBlob $b) => $b->getSha256(), $blobs->findBy(['sha256' => array_values(array_unique($hashes))])));
        $isStored = static fn (array $files): bool => $files !== [] && array_diff(array_column($files, 'sha256'), array_keys($stored)) === [];

        $result = [];
        foreach ($byPackage as $apps) {
            usort($apps, static fn (InstalledApp $a, InstalledApp $b) => $b->getVersionCode() <=> $a->getVersionCode());
            $latest = $apps[0];

            // Кандидаты на откат: текущие и прошлые версии со всех устройств, старше текущей и сохранённые.
            $previous = null;
            foreach ($apps as $app) {
                $candidates = [
                    [$app->getVersionName(), $app->getVersionCode(), $app->getFiles()],
                    [$app->getPreviousVersionName(), $app->getPreviousVersionCode(), $app->getPreviousFiles()],
                ];
                foreach ($candidates as [$name, $code, $files]) {
                    if ($code !== null && $code < $latest->getVersionCode() && $isStored($files) && ($previous === null || $code > $previous['versionCode'])) {
                        $previous = [
                            'versionName' => $name,
                            'versionCode' => $code,
                            'files' => $files,
                            'size' => array_sum(array_column($files, 'size')),
                        ];
                    }
                }
            }

            $result[] = [
                'packageName' => $latest->getPackageName(),
                'label' => $latest->getLabel(),
                'versionName' => $latest->getVersionName(),
                'versionCode' => $latest->getVersionCode(),
                'fromPlay' => $latest->isFromPlay(),
                'installer' => $latest->getInstaller(),
                'signingSha256' => $latest->getSigningSha256(),
                'files' => $latest->getFiles(),
                'size' => $latest->getTotalSize(),
                'backedUp' => $isStored($latest->getFiles()),
                'previous' => $previous,
            ];
        }
        usort($result, static fn (array $a, array $b) => strcasecmp($a['label'] ?? $a['packageName'], $b['label'] ?? $b['packageName']));

        return $this->json(['apps' => $result]);
    }

    /** Состояние загрузки: с какого байта продолжать. */
    #[Route('/apk/uploads/{sha256}', name: 'api_apk_upload_status', requirements: ['sha256' => self::SHA256], methods: ['GET'])]
    public function uploadStatus(#[CurrentUser] User $user, string $sha256): JsonResponse
    {
        $this->declaredSizeOrDeny($user, $sha256);

        return $this->json([
            'complete' => $this->storage->has($sha256),
            'offset' => $this->storage->uploadedBytes($user->getId(), $sha256),
        ]);
    }

    /** Очередная часть файла: тело запроса — байты, начиная с offset. */
    #[Route('/apk/uploads/{sha256}', name: 'api_apk_upload_chunk', requirements: ['sha256' => self::SHA256], methods: ['PUT'])]
    public function uploadChunk(
        #[CurrentUser] User $user,
        string $sha256,
        Request $request,
        #[MapQueryParameter] int $offset,
    ): JsonResponse {
        $size = $this->declaredSizeOrDeny($user, $sha256);
        if ($this->storage->has($sha256)) {
            return $this->json(['complete' => true, 'offset' => $size]);
        }

        try {
            $uploaded = $this->storage->append($user->getId(), $sha256, $offset, $request->getContent(true), $size);
        } catch (UploadOffsetMismatch $e) {
            return $this->json(['error' => 'Неверное смещение.', 'offset' => $e->expected], Response::HTTP_CONFLICT);
        } catch (\LengthException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $this->json(['complete' => false, 'offset' => $uploaded]);
    }

    #[Route('/apk/uploads/{sha256}/complete', name: 'api_apk_upload_complete', requirements: ['sha256' => self::SHA256], methods: ['POST'])]
    public function uploadComplete(
        #[CurrentUser] User $user,
        string $sha256,
        ApkBlobRepository $blobs,
        EntityManagerInterface $em,
    ): JsonResponse {
        $size = $this->declaredSizeOrDeny($user, $sha256);

        if (!$this->storage->has($sha256)) {
            if ($this->storage->uploadedBytes($user->getId(), $sha256) !== $size) {
                return $this->json(['error' => 'Файл загружен не полностью.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!$this->storage->complete($user->getId(), $sha256)) {
                return $this->json(['error' => 'Хэш файла не совпал, загрузите заново.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($blobs->findOneBy(['sha256' => $sha256]) === null) {
            $em->persist(new ApkBlob($sha256, $size));
            $em->flush();
        }

        return $this->json(['complete' => true], Response::HTTP_CREATED);
    }

    /** Скачивание APK — только того, что был установлен у этого пользователя. */
    #[Route('/apk/{sha256}', name: 'api_apk_download', requirements: ['sha256' => self::SHA256], methods: ['GET'])]
    public function download(#[CurrentUser] User $user, string $sha256): BinaryFileResponse
    {
        if (!$this->apps->userHasFile($user, $sha256) || !$this->storage->has($sha256)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->storage->path($sha256));
        $response->headers->set('Content-Type', 'application/vnd.android.package-archive');

        return $response;
    }

    /**
     * Загружать можно только файлы из своей инвентаризации, подлежащие бэкапу по политике:
     * сервер — не файлообменник.
     */
    private function declaredSizeOrDeny(User $user, string $sha256): int
    {
        foreach ($this->apps->findByUser($user) as $app) {
            if (!$this->inventory->shouldBackUp($app)) {
                continue;
            }
            foreach ($app->getFiles() as $file) {
                if ($file['sha256'] === $sha256) {
                    return $file['size'];
                }
            }
        }

        throw $this->createNotFoundException();
    }
}
