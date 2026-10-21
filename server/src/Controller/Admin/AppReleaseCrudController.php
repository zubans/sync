<?php

namespace App\Controller\Admin;

use App\Entity\AppRelease;
use App\Entity\User;
use App\Service\AppReleaseService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Версии Android-клиента. Сборки публикует make release при раскатке; здесь — выбор активной и удаление старых.
 *
 * @extends AbstractCrudController<AppRelease>
 */
#[AdminRoute(path: '/app-release', name: 'app_release')]
final class AppReleaseCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AppReleaseService $releases,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AppRelease::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Версия приложения')
            ->setEntityLabelInPlural('Версии приложения')
            ->setDefaultSort(['versionCode' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, \sprintf(
                'Сборки публикует make deploy, когда меняется клиент. Телефоны с версией ниже активной предлагают обновиться; '
                .'ссылка на активную — %s. Хранятся %d последних сборок. Активная прошлая версия дойдёт только до телефонов, '
                .'которые ещё не обновились: Android не ставит старую версию поверх новой.',
                $this->generateUrl('api_app_download_latest', [], 0),
                AppReleaseService::KEEP,
            ));
    }

    public function configureActions(Actions $actions): Actions
    {
        $activate = Action::new('activate', 'Сделать активной', 'fa fa-check')
            ->linkToRoute('admin_app_release_activate', fn (AppRelease $r): array => [
                'id' => $r->getId(),
                'token' => $this->csrf->getToken('activate'.$r->getId())->getValue(),
            ])
            ->renderAsForm()
            ->displayIf(static fn (AppRelease $r) => !$r->isActive());
        $download = Action::new('download', 'Скачать', 'fa fa-download')
            ->linkToUrl(fn (AppRelease $r): string => $this->generateUrl('api_app_download', ['versionCode' => $r->getVersionCode()]));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $activate)
            ->add(Crud::PAGE_INDEX, $download)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $a) => $a->displayIf(static fn (AppRelease $r) => !$r->isActive()));
    }

    #[AdminRoute('/{id}/activate', name: 'activate', options: ['methods' => ['POST']])]
    public function activate(#[MapEntity(id: 'id')] AppRelease $release, Request $request): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('activate'.$release->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
        $this->releases->activate($release);
        $this->addFlash('success', \sprintf('Активна версия %s.', $release));

        return $this->redirectToRoute('admin_app_release_index');
    }

    /** Вместе с записью удаляется и файл APK. */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof AppRelease);
        $this->releases->delete($entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('versionName', 'Версия');
        yield IntegerField::new('versionCode', 'Номер сборки');
        yield BooleanField::new('active', 'Активна')->renderAsSwitch(false);
        yield IntegerField::new('size', 'Размер')
            ->formatValue(static fn ($value, AppRelease $r) => \sprintf('%.1f МБ', $r->getSize() / 1048576));
        yield TextField::new('sourceRevision', 'Git-дерево android/')->onlyOnDetail();
        yield TextField::new('sha256', 'SHA-256')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Опубликована');
    }
}
