<?php

namespace App\Controller\Api;

use App\Entity\AppRelease;
use App\Repository\AppReleaseRepository;
use App\Service\AppReleaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Обновление Android-клиента: активная версия и ссылка на APK. Без входа — ссылку можно открыть
 * в браузере на новом телефоне, где приложения ещё нет. В APK нет секретов, только адреса серверов.
 */
#[Route('/api/app')]
final class AppReleaseController extends AbstractController
{
    public function __construct(
        private readonly AppReleaseRepository $releases,
        private readonly AppReleaseService $service,
    ) {
    }

    #[Route('/version', name: 'api_app_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        $release = $this->releases->findActive()
            ?? throw $this->createNotFoundException('Опубликованных версий приложения нет.');

        return $this->json([
            'versionCode' => $release->getVersionCode(),
            'versionName' => $release->getVersionName(),
            'sha256' => $release->getSha256(),
            'size' => $release->getSize(),
            // Ссылка на эту же сборку: активная может смениться, пока телефон качает.
            'url' => $this->generateUrl('api_app_download', ['versionCode' => $release->getVersionCode()], UrlGeneratorInterface::ABSOLUTE_URL),
            'latestUrl' => $this->generateUrl('api_app_download_latest', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    /** Ссылка «на последнюю версию» — для браузера и для пересылки. */
    #[Route('/download', name: 'api_app_download_latest', methods: ['GET'])]
    public function latest(): BinaryFileResponse
    {
        return $this->apk($this->releases->findActive());
    }

    #[Route('/download/{versionCode}', name: 'api_app_download', requirements: ['versionCode' => '\d+'], methods: ['GET'])]
    public function download(int $versionCode): BinaryFileResponse
    {
        return $this->apk($this->releases->findOneBy(['versionCode' => $versionCode]));
    }

    private function apk(?AppRelease $release): BinaryFileResponse
    {
        if ($release === null || !is_file($this->service->path($release))) {
            throw $this->createNotFoundException('Такой версии приложения нет.');
        }
        $response = new BinaryFileResponse($this->service->path($release));
        // Без этого типа браузер телефона сохранит APK как zip и не предложит установить.
        $response->headers->set('Content-Type', 'application/vnd.android.package-archive');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('contactsync-%s.apk', preg_replace('/[^0-9A-Za-z._-]+/', '-', $release->getVersionName())),
        );

        return $response;
    }
}
