<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\FeatureManager;
use App\Service\NodeRuntimeExporter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Управление включением/выключением функциональных блоков системы
 * (Данные устройства, Новости, Рынок акций, VPN) и отдельных функций ИИ.
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/modules', name: 'features')]
final class FeatureSettingsController extends AbstractController
{
    private const CSRF_SAVE = 'admin_features_save';

    #[AdminRoute('/', name: 'index')]
    public function index(FeatureManager $features): Response
    {
        $settings = $features->getSettings();
        $profiles = $features->recommendedComposeProfiles();

        return $this->render('admin/features.html.twig', [
            'settings' => $settings,
            'effective' => $features->getEffectiveMap(),
            'labels' => FeatureManager::LABELS,
            'profiles' => $profiles,
            'composeCommand' => $profiles !== []
                ? 'COMPOSE_PROFILES='.implode(',', $profiles).' docker compose up -d'
                : 'docker compose up -d',
            'csrfTokenId' => self::CSRF_SAVE,
        ]);
    }

    #[AdminRoute('/save', name: 'save', options: ['methods' => ['POST']])]
    public function save(Request $request, FeatureManager $features, NodeRuntimeExporter $runtimeExporter): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_SAVE, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }

        $wasVpnEnabled = $features->isEnabled(FeatureManager::VPN);
        $input = $request->request->all();
        $features->updateFromFormInput($input);
        $isVpnEnabled = $features->isEnabled(FeatureManager::VPN);

        // При выключении (или повторном включении) блока VPN сразу синхронизируем runtime и вызываем apply-хук на хосте.
        if (!$isVpnEnabled || $wasVpnEnabled !== $isVpnEnabled) {
            $syncResult = $runtimeExporter->sync(applyNow: true);
            if ($syncResult['status'] === 'error') {
                $this->addFlash('danger', $syncResult['message']);
            } elseif (!$isVpnEnabled) {
                $this->addFlash('info', $syncResult['message']);
            }
        }

        $profiles = $features->recommendedComposeProfiles();
        $profileHint = $profiles !== []
            ? ' Рекомендуемый профиль Docker Compose: COMPOSE_PROFILES='.implode(',', $profiles).'.'
            : ' Дополнительные контейнеры news/vpn сейчас не требуются.';

        $this->addFlash('success', 'Настройки модулей и ИИ сохранены.'.$profileHint);

        return $this->redirectToRoute('admin_features_index');
    }
}
