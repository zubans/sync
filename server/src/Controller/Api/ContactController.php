<?php

namespace App\Controller\Api;

use App\Dto\SyncRequest;
use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Service\ContactSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /** Общие контакты семьи пользователя. */
    #[Route('/family/contacts', name: 'api_family_contacts', methods: ['GET'])]
    public function family(#[CurrentUser] User $user, ContactRepository $contacts): JsonResponse
    {
        $family = $user->getFamily();

        return $this->json([
            'family' => $family === null ? null : ['id' => $family->getId(), 'name' => $family->getName()],
            'contacts' => $family === null ? [] : array_map(self::view(...), $contacts->findByFamily($family)),
        ]);
    }

    /** @return array<string, mixed> */
    private static function view(Contact $c): array
    {
        return [
            'serverId' => $c->getUuid(),
            'name' => $c->getName(),
            'phones' => $c->getPhones(),
            'emails' => $c->getEmails(),
            'updatedAt' => $c->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
