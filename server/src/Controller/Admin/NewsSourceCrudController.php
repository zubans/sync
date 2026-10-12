<?php

namespace App\Controller\Admin;

use App\Entity\NewsSource;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Издания для сбора новостей: новое RSS-издание добавляется здесь без правки кода.
 * Выключенное издание не собирается и не показывается в виджете, его новости остаются в базе.
 *
 * @extends AbstractCrudController<NewsSource>
 */
final class NewsSourceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsSource::class;
    }

    public function createEntity(string $entityFqcn): NewsSource
    {
        return new NewsSource('', '', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Издание')
            ->setEntityLabelInPlural('Издания')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Название');
        yield TextField::new('code', 'Код в API')->setHelp('Латиницей: rg, rbc, cnn…');
        yield TextField::new('url', 'Лента')->setHelp('Несколько лент одного издания — через пробел');
        yield ChoiceField::new('format', 'Формат')->setChoices([
            'RSS' => NewsSource::FORMAT_RSS,
            'CNN Lite' => NewsSource::FORMAT_CNN_LITE,
            'Новостная карта сайта' => NewsSource::FORMAT_NEWS_SITEMAP,
            'Сводка (пишет модель)' => NewsSource::FORMAT_DIGEST,
        ]);
        yield ChoiceField::new('language', 'Язык')->setChoices([
            'Русский' => NewsSource::LANGUAGE_RU,
            'Английский' => NewsSource::LANGUAGE_EN,
            'Французский' => NewsSource::LANGUAGE_FR,
            'Украинский' => NewsSource::LANGUAGE_UK,
        ])->setHelp('Не русский — заголовки, анонсы и тексты новостей переводятся на русский');
        yield BooleanField::new('enabled', 'Собирать')->renderAsSwitch(false);
        yield BooleanField::new('showInWidget', 'В виджете')->renderAsSwitch(false)
            ->setHelp('Выключено — новости идут только в сводку, вкладки у издания нет');
        yield DateTimeField::new('lastSuccessAt', 'Последний сбор (UTC)')->hideOnForm();
        yield TextareaField::new('lastError', 'Ошибка')->hideOnForm();
    }
}
