<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\UserRepository;
use App\Service\ContactMerger;
use App\Service\DuplicateFinder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Возможные дубли контактов и их объединение. Лишние контакты уходят в корзину — ошибочное объединение
 * можно откатить, восстановив их оттуда.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/duplicates', name: 'duplicates')]
final class DuplicateController extends AbstractController
{
    private const CSRF_INTENTION = 'merge-duplicates';

    #[AdminRoute('/', name: 'index')]
    public function index(DuplicateFinder $finder, UserRepository $users, #[MapQueryParameter] ?int $user = null): Response
    {
        $selected = $user === null ? null : $users->find($user);

        return $this->render('admin/duplicates.html.twig', [
            'groups' => $finder->find($selected),
            'users' => $users->findBy([], ['email' => 'ASC']),
            'selected_user' => $selected?->getId(),
            'csrf_intention' => self::CSRF_INTENTION,
            'trash_days' => Contact::TRASH_DAYS,
        ]);
    }

    #[AdminRoute('/merge', name: 'merge', options: ['methods' => ['POST']])]
    public function merge(Request $request, ContactRepository $contacts, ContactMerger $merger): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
        $primary = $contacts->find($request->request->getInt('primary'));
        $ids = array_map(intval(...), $request->request->all('contacts'));
        $others = array_filter($contacts->findBy(['id' => $ids]), static fn (Contact $c) => !$c->isDeleted());
        $back = $this->redirectToRoute('admin_duplicates_index', array_filter(['user' => $request->request->get('user')]));

        if ($primary === null || $primary->isDeleted() || !\in_array($primary->getId(), $ids, true) || \count($others) < 2) {
            $this->addFlash('warning', 'Отметьте хотя бы два контакта и выберите основной среди отмеченных.');

            return $back;
        }
        try {
            $merger->merge($primary, array_values($others));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $back;
        }

        $this->addFlash('success', \sprintf(
            'Объединено в «%s». Остальные (%d) — в корзине, их можно восстановить %d дней. Телефоны владельца получат объединённый контакт при синхронизации.',
            $primary, \count($others) - 1, Contact::TRASH_DAYS,
        ));

        return $back;
    }
}
