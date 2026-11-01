<?php

namespace App\Controller\Admin;

use App\Entity\BrowsingVisit;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * История браузеров с устройств; заполняется клиентом, в админке только просмотр.
 *
 * @extends AbstractCrudController<BrowsingVisit>
 */
final class BrowsingVisitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BrowsingVisit::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Посещение')
            ->setEntityLabelInPlural('История браузеров')
            ->setDefaultSort(['visitedAt' => 'DESC'])
            ->setSearchFields(['url', 'title', 'user.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('user')
            ->add('device')
            ->add('browser')
            ->add('visitedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('visitedAt', 'Когда');
        yield UrlField::new('url', 'Адрес');
        yield TextField::new('title', 'Заголовок');
        yield TextField::new('browser', 'Браузер');
        yield AssociationField::new('user', 'Пользователь');
        yield AssociationField::new('device', 'Устройство');
    }
}
