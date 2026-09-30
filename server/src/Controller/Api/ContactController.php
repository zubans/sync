<?php

namespace App\Controller\Api;

use App\Dto\SyncRequest;
use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Service\ContactPhotoStorage;
use App\Service\ContactSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api')]
final class ContactController extends AbstractController
{
    /** Выгрузка личных контактов устройства и списка Google-аккаунтов. */
    #[Route('/sync', name: 'api_sync', methods: ['POST'])]
    public function sync(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SyncRequest $request,
        ContactSyncService $syncService,
    ): JsonResponse {
        return $this->json($syncService->sync($user, $request));
    }

    /** Личные контакты пользователя — для восстановления на телефон. */
    #[Route('/contacts', name: 'api_contacts', methods: ['GET'])]
    public function personal(#[CurrentUser] User $user, ContactRepository $contacts): JsonResponse
    {
        return $this->json(['contacts' => array_map(self::view(...), $contacts->findPersonal($user))]);
    }

    /** Общие контакты семьи, которыми поделились другие её члены. */
    #[Route('/family/contacts', name: 'api_family_contacts', methods: ['GET'])]
    public function family(#[CurrentUser] User $user, ContactRepository $contacts): JsonResponse
    {
        $family = $user->getFamily();

        return $this->json([
            'family' => $family === null ? null : ['id' => $family->getId(), 'name' => $family->getName()],
            'contacts' => $family === null ? [] : array_map(self::view(...), $contacts->findSharedForUser($user, $family)),
        ]);
    }

    /**
     * Загрузка фото контакта. Принимается только фото, которое сервер попросил в missingPhotos:
     * хэш должен совпасть с фото одного из контактов пользователя.
     */
    #[Route('/contact-photos/{sha256}', name: 'api_contact_photo_upload', requirements: ['sha256' => '[0-9a-f]{64}'], methods: ['PUT'])]
    public function uploadPhoto(
        #[CurrentUser] User $user,
        string $sha256,
        Request $request,
        ContactRepository $contacts,
        ContactPhotoStorage $photos,
    ): JsonResponse {
        if (!$contacts->userCanSeePhoto($user, $sha256)) {
            throw $this->createNotFoundException();
        }
        if (!$photos->has($sha256) && ($error = $photos->store($sha256, $request->getContent())) !== null) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Фото для восстановления на телефон — только своих и семейных контактов. */
    #[Route('/contact-photos/{sha256}', name: 'api_contact_photo', requirements: ['sha256' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function photo(
        #[CurrentUser] User $user,
        string $sha256,
        ContactRepository $contacts,
        ContactPhotoStorage $photos,
    ): BinaryFileResponse {
        if (!$contacts->userCanSeePhoto($user, $sha256) || !$photos->has($sha256)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($photos->path($sha256));
        $response->headers->set('Content-Type', $photos->mimeType($sha256));

        return $response->setPrivate();
    }

    /** @return array<string, mixed> */
    private static function view(Contact $c): array
    {
        return ContactSyncService::view($c);
    }
}
