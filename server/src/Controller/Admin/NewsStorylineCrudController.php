<?php

namespace App\Controller\Admin;

use App\Entity\NewsStoryline;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Сюжетные линии — только просмотр: их строит `app:news:analyze`. Новости линии — в «Новости → Лента»
 * с фильтром по сюжету. В списке — только линии с названием: безымянные — это одиночные новости,
 * открывшие линию на случай продолжения (их большинство), историями они ещё не стали.
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

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)->andWhere('entity.title IS NOT NULL');
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
