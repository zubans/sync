<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\RequiresFeature;
use App\Service\FeatureManager;
use App\Service\PhonebookViewService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Просмотр телефонной книги любого пользователя: личные контакты, семейные, корзина и устройства. */
#[RequiresFeature(FeatureManager::CONTACTS)]
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/phonebook', name: 'phonebook')]
final class PhonebookController extends AbstractController
{
    #[AdminRoute('/', name: 'index')]
    public function index(Request $request, PhonebookViewService $phonebookView): Response
    {
        $rawUserId = $request->query->get('user');
        $requestedUserId = $rawUserId !== null && $rawUserId !== '' ? (int) $rawUserId : null;

        return $this->renderPhonebook($requestedUserId, $request, $phonebookView);
    }

    #[AdminRoute('/{userId<\d+>}', name: 'user')]
    public function showUser(int $userId, Request $request, PhonebookViewService $phonebookView): Response
    {
        return $this->renderPhonebook($userId, $request, $phonebookView);
    }

    #[AdminRoute('/{userId<\d+>}/export.vcf', name: 'export_vcf')]
    public function exportVcf(int $userId, Request $request, PhonebookViewService $phonebookView): Response
    {
        $user = $phonebookView->resolveUser($userId);
        if ($user === null) {
            throw $this->createNotFoundException('Пользователь не найден.');
        }

        $includeFamily = $request->query->getBoolean('family');
        $content = $phonebookView->buildVCard($user, $includeFamily);

        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $user->getEmail()) ?: 'user';
        $filename = \sprintf('phonebook-%s-%d.vcf', trim($slug, '-'), $user->getId() ?? 0);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function renderPhonebook(?int $requestedUserId, Request $request, PhonebookViewService $phonebookView): Response
    {
        $selectedUser = $phonebookView->resolveUser($requestedUserId);
        if ($requestedUserId !== null && $selectedUser === null) {
            throw $this->createNotFoundException('Пользователь не найден.');
        }

        $rawDevice = $request->query->get('device');
        $deviceId = $rawDevice !== null && $rawDevice !== '' ? (int) $rawDevice : null;
        $tab = (string) $request->query->get('tab', PhonebookViewService::TAB_PERSONAL);
        $query = (string) $request->query->get('q', '');

        $view = $phonebookView->buildView($selectedUser, $deviceId, $tab, $query);

        return $this->render('admin/phonebook.html.twig', $view);
    }
}
