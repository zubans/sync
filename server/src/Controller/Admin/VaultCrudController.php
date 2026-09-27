<?php

namespace App\Controller\Admin;

use App\Entity\Vault;
use App\Repository\VaultItemRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Хранилища паролей. Содержимое зашифровано ключом пользователя и в админке недоступно;
 * администратор видит только метаданные и может сбросить хранилище (например, если забыт мастер-пароль).
 *
 * @extends AbstractCrudController<Vault>
 */
final class VaultCrudController extends AbstractCrudController
{
    public function __construct(private readonly VaultItemRepository $items)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Vault::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Хранилище паролей')
            ->setEntityLabelInPlural('Хранилища паролей')
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->setSearchFields(['user.email'])
            ->setHelp(Crud::PAGE_INDEX, 'Пароли зашифрованы на устройствах пользователей, сервер их прочитать не может. Удаление сбрасывает хранилище безвозвратно.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Пользователь');
        yield IntegerField::new('id', 'Записей')
            ->formatValue(fn ($value, Vault $vault) => $this->items->countAlive($vault))
            ->setSortable(false);
        yield IntegerField::new('revision', 'Изменений');
        yield TextField::new('kdfAlgorithm', 'KDF')->onlyOnDetail();
        yield IntegerField::new('kdfIterations', 'Итераций KDF')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Создано');
        yield DateTimeField::new('updatedAt', 'Изменено');
    }
}
