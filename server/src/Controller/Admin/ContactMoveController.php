<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\FamilyRepository;
use App\Repository\UserRepository;
use App\Service\ContactMover;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Добавление контактов в семью перетаскиванием: контакт получает отметку «Семья», остаётся у владельца
 * и ставится на новые устройства членов семьи.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/contact-move', name: 'contact_move')]
final class ContactMoveController extends AbstractController
{
    private const PAGE_LIMIT = 300;
    private const CSRF_INTENTION = 'contact-move';

    #[AdminRoute('/', name: 'index')]
    public function index(
        ContactRepository $contacts,
        UserRepository $users,
        FamilyRepository $families,
        #[MapQueryParameter] ?int $user = null,
        #[MapQueryParameter] string $q = '',
    ): Response {
        $qb = $contacts->createQueryBuilder('c')
            ->addSelect('u')
            ->join('c.user', 'u')
            ->where('c.deletedAt IS NULL')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults(self::PAGE_LIMIT + 1);
        if ($user !== null) {
            $qb->andWhere('u.id = :user')->setParameter('user', $user);
        }
        if (trim($q) !== '') {
            $qb->andWhere('LOWER(c.name) LIKE :q')->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }
        $list = $qb->getQuery()->getResult();

        return $this->render('admin/contact_move.html.twig', [
            'contacts' => \array_slice($list, 0, self::PAGE_LIMIT),
            'truncated' => \count($list) > self::PAGE_LIMIT,
            'users' => $users->findBy([], ['email' => 'ASC']),
            'families' => $families->findBy([], ['name' => 'ASC']),
            'selected_user' => $user,
            'q' => $q,
            'csrf_intention' => self::CSRF_INTENTION,
        ]);
    }

    #[AdminRoute('/move', name: 'move', options: ['methods' => ['POST']])]
    public function move(Request $request, ContactRepository $contacts, FamilyRepository $families, ContactMover $mover): JsonResponse
    {
        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, (string) ($payload['token'] ?? ''))) {
            return $this->json(['error' => 'Сессия устарела, обновите страницу.'], Response::HTTP_FORBIDDEN);
        }

        $family = $families->find((int) ($payload['familyId'] ?? 0));
        $ids = array_values(array_filter(array_map('intval', (array) ($payload['contactIds'] ?? []))));
        if (!$family instanceof Family || $ids === []) {
            return $this->json(['error' => 'Не выбраны контакты или семья.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var list<Contact> $selected */
        $selected = $contacts->findBy(['id' => $ids]);
        $result = $mover->share($selected, $family);

        return $this->json($result + [
            'familyContacts' => \count($contacts->findByFamily($family)),
            'familyName' => $family->getName(),
            'sharedIds' => array_map(static fn (Contact $c) => $c->getId(), array_values(array_filter($selected, static fn (Contact $c) => $c->getFamily() === $family))),
        ]);
    }
}
