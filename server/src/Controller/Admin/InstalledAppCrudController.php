<?php

namespace App\Controller\Admin;

use App\Entity\InstalledApp;
use App\Service\ApkStorage;
use App\Service\AppInventoryService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Приложения на устройствах пользователей; заполняются клиентом, в админке только просмотр.
 *
 * @extends AbstractCrudController<InstalledApp>
 */
final class InstalledAppCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ApkStorage $storage,
        private readonly AppInventoryService $inventory,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return InstalledApp::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Приложение')
            ->setEntityLabelInPlural('Приложения')
            ->setDefaultSort(['label' => 'ASC'])
            ->setSearchFields(['label', 'packageName', 'user.email'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user')->add('device')->add('installer')->add('removedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Название');
        yield TextField::new('packageName', 'Пакет');
        yield TextField::new('versionName', 'Версия');
        // Строковое поле + formatValue: без прошлой версии показываем «—», а не бейдж «Null».
        yield TextField::new('packageName', 'Прошлая версия')
            ->formatValue(static fn ($value, InstalledApp $app) => $app->getPreviousVersionName() ?? '—')
            ->setSortable(false);
        yield AssociationField::new('user', 'Пользователь');
        yield AssociationField::new('device', 'Устройство');
        yield TextField::new('installer', 'Источник')
            ->formatValue(static fn (?string $installer) => match ($installer) {
                InstalledApp::PLAY_STORE => 'Google Play',
                null => 'Вручную',
                default => $installer,
            });
        yield TextField::new('packageName', 'APK на сервере')
            ->formatValue(fn ($value, InstalledApp $app) => $this->backupState($app))
            ->setSortable(false);
        yield IntegerField::new('versionCode', 'Размер, МБ')
            ->formatValue(static fn ($value, InstalledApp $app) => round($app->getTotalSize() / 1048576, 1))
            ->setSortable(false);
        yield DateTimeField::new('lastSeenAt', 'Последний раз');
        yield DateTimeField::new('removedAt', 'Удалено')->hideOnIndex();
        yield TextField::new('signingSha256', 'Подпись (SHA-256)')->onlyOnDetail();
    }

    private function backupState(InstalledApp $app): string
    {
        if (!$this->inventory->shouldBackUp($app)) {
            return $app->isFromPlay() ? 'не нужен (Play)' : 'слишком большой';
        }
        foreach ($app->getFileHashes() as $sha256) {
            if (!$this->storage->has($sha256)) {
                return 'ожидает загрузки';
            }
        }

        return 'сохранён';
    }
}
