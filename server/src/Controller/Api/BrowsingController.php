<?php

namespace App\Controller\Api;

use App\Dto\BrowsingInput;
use App\Entity\BrowsingVisit;
use App\Entity\Device;
use App\Entity\User;
use App\Repository\BrowsingVisitRepository;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * История браузеров с устройств пользователя: клиент выгружает её раз в час, в админке — только просмотр.
 */
#[Route('/api')]
final class BrowsingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceRepository $devices,
        private readonly BrowsingVisitRepository $visits,
    ) {
    }

    #[Route('/browsing', name: 'api_browsing', methods: ['POST'])]
    public function upload(#[CurrentUser] User $user, #[MapRequestPayload] BrowsingInput $input): JsonResponse
    {
        $saved = $this->em->wrapInTransaction(function () use ($user, $input): int {
            $device = $this->devices->findOneBy(['user' => $user, 'installId' => $input->device->installId]);
            if ($device === null) {
                $device = new Device($user, $input->device->installId);
                $this->em->persist($device);
            }
            $device->setModel($input->device->model);
            $this->em->flush();

            // Хэш посещения считаем один раз: он нужен и для отсева уже сохранённых, и внутри сущности.
            $byHash = [];
            foreach ($input->visits as $visit) {
                $visitedAt = \DateTimeImmutable::createFromFormat('U', (string) intdiv($visit->visitedAt, 1000));
                if ($visitedAt === false) {
                    continue;
                }
                $byHash[BrowsingVisit::hash($visit->url, $visitedAt)] = [$visit, $visitedAt];
            }

            $existing = $this->visits->existingHashes($device, array_keys($byHash));
            $saved = 0;
            foreach ($byHash as $hash => [$visit, $visitedAt]) {
                if (isset($existing[$hash])) {
                    continue;
                }
                $entity = (new BrowsingVisit($user, $device, $visit->url, $visitedAt))
                    ->setTitle($visit->title)
                    ->setBrowser($visit->browser);
                $this->em->persist($entity);
                ++$saved;
            }
            $this->em->flush();

            return $saved;
        });

        return $this->json(['saved' => $saved]);
    }
}
