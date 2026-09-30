<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Корзина: контакты, удалённые на телефонах или в админке. Восстановленный контакт возвращается
 * на телефоны владельца при следующей синхронизации. Через Contact::TRASH_DAYS дней — удаляется окончательно.
 *
 * @extends AbstractCrudController<Contact>
 */
#[AdminRoute(path: '/trash', name: 'trash')]
final class TrashCrudController extends AbstractCrudController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Контакт в корзине')
            ->setEntityLabelInPlural('Корзина')
            ->setDefaultSort(['deletedAt' => 'DESC'])
            ->setSearchFields(['name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, \sprintf(
                'Контакты, удалённые на телефонах или в админке, хранятся здесь %d дней, потом удаляются окончательно. '
                .'«Восстановить» вернёт контакт владельцу и на его телефоны при следующей синхронизации.',
                Contact::TRASH_DAYS,
            ));
    }

    public function configureActions(Actions $actions): Actions
    {
        $restore = Action::new('restore', 'Восстановить', 'fa fa-rotate-left')
            ->linkToRoute('admin_trash_restore', fn (Contact $c): array => [
                'id' => $c->getId(),
                'token' => $this->csrf->getToken('restore'.$c->getId())->getValue(),
            ])
            ->renderAsForm();
        $forever = static fn (Action $a) => $a->setLabel('Удалить навсегда');

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $restore)
            ->add(Crud::PAGE_DETAIL, $restore)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $forever)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $forever)
            ->update(Crud::PAGE_INDEX, Action::BATCH_DELETE, $forever);
    }

    #[AdminRoute('/{id}/restore', name: 'restore', options: ['methods' => ['POST']])]
    public function restore(#[MapEntity(id: 'id')] Contact $contact, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('restore'.$contact->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
        $contact->restoreFromTrash();
        $em->flush();
        $this->addFlash('success', \sprintf('«%s» восстановлен: вернётся на телефоны владельца при следующей синхронизации.', $contact));

        return $this->redirectToRoute('admin_trash_index');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user')->add('deletedAt');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.deletedAt IS NOT NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield ContactFields::photo(fn (string $sha) => $this->generateUrl('admin_contact_photo', ['sha256' => $sha]), $pageName);
        yield TextField::new('name', 'Имя');
        yield ArrayField::new('phones', 'Телефоны');
        yield ArrayField::new('emails', 'Email')->hideOnIndex();
        yield from ContactFields::birthday();
        yield AssociationField::new('user', 'Владелец');
        yield DateTimeField::new('deletedAt', 'Удалён');
        yield TextField::new('uuid', 'Кем')
            ->formatValue(static fn ($value, Contact $contact) => $contact->isDeletedOnServer() ? 'в админке' : 'на телефоне')
            ->setSortable(false);
        yield TextField::new('uuid', 'Удалится навсегда')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getTrashExpiresAt()?->format('d.m.Y') ?? '—')
            ->setSortable(false);
    }
}
