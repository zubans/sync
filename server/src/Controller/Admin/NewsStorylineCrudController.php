<?php

namespace App\Controller\Admin;

use App\Entity\NewsStoryline;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Сюжетные линии — только просмотр: их строит `app:news:analyze`. Новости линии — в «Новости → Лента»
 * с фильтром по сюжету.
 *
 * @extends AbstractCrudController<NewsStoryline>
 */
final class NewsStorylineCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsStoryline::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Сюжет')
            ->setEntityLabelInPlural('Сюжеты')
            ->setDefaultSort(['lastSeenAt' => 'DESC'])
            ->setSearchFields(['title', 'summary']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Название');
        yield TextareaField::new('summary', 'О чём')->onlyOnDetail();
        yield IntegerField::new('newsCount', 'Новостей');
        yield DateTimeField::new('firstSeenAt', 'Началась (UTC)');
        yield DateTimeField::new('lastSeenAt', 'Последняя новость (UTC)');
    }
}
