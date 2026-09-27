<?php

namespace App\Controller\Api;

use App\Dto\VaultChangesInput;
use App\Dto\VaultKeyInput;
use App\Entity\User;
use App\Entity\Vault;
use App\Repository\VaultItemRepository;
use App\Repository\VaultRepository;
use App\Service\VaultService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Хранилище паролей. Сервер хранит только шифротекст и параметры получения ключа.
 */
#[Route('/api/vault')]
final class VaultController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VaultRepository $vaults,
    ) {
    }

    #[Route('', name: 'api_vault_get', methods: ['GET'])]
    public function get(#[CurrentUser] User $user): JsonResponse
    {
        $vault = $this->vaults->findOneBy(['user' => $user]);
        if ($vault === null) {
            return $this->json(['error' => 'Хранилище не создано.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(self::keyView($vault));
    }

    #[Route('', name: 'api_vault_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] VaultKeyInput $input): JsonResponse
    {
        if ($this->vaults->findOneBy(['user' => $user]) !== null) {
            return $this->json(['error' => 'Хранилище уже создано.'], Response::HTTP_CONFLICT);
        }

        $vault = new Vault($user, $input->kdfAlgorithm, $input->kdfIterations, $input->kdfSalt, $input->protectedKey, $input->kdfMemory, $input->kdfParallelism);
        $this->em->persist($vault);
        $this->em->flush();

        return $this->json(self::keyView($vault), Response::HTTP_CREATED);
    }

    /** Смена мастер-пароля или параметров KDF: клиент перешифровал ключ хранилища. */
    #[Route('/key', name: 'api_vault_rekey', methods: ['PUT'])]
    public function rekey(#[CurrentUser] User $user, #[MapRequestPayload] VaultKeyInput $input): JsonResponse
    {
        $vault = $this->requireVault($user);
        $vault->setKey($input->kdfAlgorithm, $input->kdfIterations, $input->kdfSalt, $input->protectedKey, $input->kdfMemory, $input->kdfParallelism);
        $this->em->flush();

        return $this->json(self::keyView($vault));
    }

    /** Сброс хранилища (забыт мастер-пароль): без него данные всё равно не расшифровать. */
    #[Route('', name: 'api_vault_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user): JsonResponse
    {
        $vault = $this->vaults->findOneBy(['user' => $user]);
        if ($vault !== null) {
            $this->em->remove($vault);
            $this->em->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Записи, изменённые после ревизии since (включая удалённые). */
    #[Route('/items', name: 'api_vault_items', methods: ['GET'])]
    public function items(
        #[CurrentUser] User $user,
        VaultItemRepository $items,
        #[MapQueryParameter] int $since = 0,
    ): JsonResponse {
        $vault = $this->requireVault($user);

        return $this->json([
            'revision' => $vault->getRevision(),
            'items' => array_map(VaultService::view(...), $items->findChangedSince($vault, $since)),
        ]);
    }

    #[Route('/items', name: 'api_vault_push', methods: ['POST'])]
    public function push(
        #[CurrentUser] User $user,
        #[MapRequestPayload] VaultChangesInput $input,
        VaultService $service,
    ): JsonResponse {
        $vault = $this->requireVault($user);
        $results = $service->apply($vault, $input->changes);

        return $this->json(['revision' => $vault->getRevision(), 'results' => $results]);
    }

    private function requireVault(User $user): Vault
    {
        return $this->vaults->findOneBy(['user' => $user])
            ?? throw $this->createNotFoundException('Хранилище не создано.');
    }

    /** @return array<string, mixed> */
    private static function keyView(Vault $vault): array
    {
        return [
            'kdfAlgorithm' => $vault->getKdfAlgorithm(),
            'kdfIterations' => $vault->getKdfIterations(),
            'kdfMemory' => $vault->getKdfMemory(),
            'kdfParallelism' => $vault->getKdfParallelism(),
            'kdfSalt' => $vault->getKdfSalt(),
            'protectedKey' => $vault->getProtectedKey(),
            'revision' => $vault->getRevision(),
        ];
    }
}
