<?php

namespace App\Controller\Admin;

use App\Entity\NewsItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Собранные новости — только просмотр и поиск: их пишет сборщик (`app:news:collect`).
 *
 * @extends AbstractCrudController<NewsItem>
 */
final class NewsItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Новость')
            ->setEntityLabelInPlural('Новости')
            ->setDefaultSort(['sortAt' => 'DESC'])
            ->setSearchFields(['title', 'summary']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('source')->add('sortAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('sortAt', 'Время (UTC)');
        yield AssociationField::new('source', 'Издание');
        yield TextField::new('title', 'Заголовок');
        yield UrlField::new('link', 'Статья')->onlyOnDetail();
        yield TextareaField::new('summary', 'Анонс')->onlyOnDetail();
        yield TextareaField::new('content', 'Полный текст')->onlyOnDetail();
        yield DateTimeField::new('publishedAt', 'Опубликована (UTC)')->onlyOnDetail();
        yield DateTimeField::new('firstSeenAt', 'Собрана (UTC)')->onlyOnDetail();
    }
}
