<?php

namespace App\Controller\Admin;

use App\Entity\GoogleAccount;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;

/**
 * Google-аккаунты с устройств пользователей; заполняются клиентом, в админке только просмотр.
 *
 * @extends AbstractCrudController<GoogleAccount>
 */
final class GoogleAccountCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GoogleAccount::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Google-аккаунт')
            ->setEntityLabelInPlural('Google-аккаунты')
            ->setDefaultSort(['lastSeenAt' => 'DESC'])
            ->setSearchFields(['email', 'user.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user');
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'Google-аккаунт');
        yield AssociationField::new('user', 'Пользователь');
        yield AssociationField::new('device', 'Устройство');
        yield DateTimeField::new('firstSeenAt', 'Впервые');
        yield DateTimeField::new('lastSeenAt', 'Последний раз');
    }
}
