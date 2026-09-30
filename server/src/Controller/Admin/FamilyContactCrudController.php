<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ContactMover;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\QueryBuilder;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

/**
 * Общие контакты семей: попадают на телефоны всех участников семьи.
 *
 * @extends AbstractCrudController<Contact>
 */
final class FamilyContactCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public static function indexUrlForFamily(AdminUrlGeneratorInterface $urls, Family $family): string
    {
        return $urls->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('filters', ['family' => ['comparison' => '=', 'value' => $family->getId()]])
            ->generateUrl();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Семейный контакт')
            ->setEntityLabelInPlural('Семейные контакты')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'family.name'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $toPersonal = Action::new('toPersonal', 'Вернуть в личные', 'fa fa-user')->linkToCrudAction('toPersonal');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $toPersonal)
            ->add(Crud::PAGE_DETAIL, $toPersonal)
            ->add(Crud::PAGE_EDIT, $toPersonal);
    }

    /**
     * Убирает контакт из семьи, отдавая его в личные контакты выбранного пользователя.
     * GET — выбор пользователя, POST — перенос.
     */
    #[AdminRoute('/{id}/to-personal', name: 'to_personal', options: ['methods' => ['GET', 'POST']])]
    public function toPersonal(
        #[MapEntity(id: 'id')] Contact $contact,
        Request $request,
        UserRepository $users,
        ContactMover $mover,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        $family = $contact->getFamily() ?? throw $this->createNotFoundException('Контакт не семейный.');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('to-personal'.$contact->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
            }
            $user = $users->find($request->request->getInt('user')) ?? throw $this->createNotFoundException('Пользователь не найден.');
            $name = (string) $contact;
            $this->addFlash('success', $mover->moveToUser($contact, $user)
                ? \sprintf('«%s» убран из семьи «%s» и стал личным контактом %s.', $name, $family->getName(), $user->getEmail())
                : \sprintf('«%s» убран из семьи «%s»: у %s такой контакт уже есть.', $name, $family->getName(), $user->getEmail()));

            return $this->redirectToRoute('admin_family_contact_index');
        }

        $members = $family->getMembers()->toArray();
        $others = array_values(array_filter($users->findBy([], ['email' => 'ASC']), static fn (User $u) => !\in_array($u, $members, true)));

        return $this->render('admin/contact_to_personal.html.twig', [
            'contact' => $contact,
            'family' => $family,
            'members' => $members,
            'others' => $others,
        ]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('family');
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.family IS NOT NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Имя');
        yield ArrayField::new('phones', 'Телефоны')->setRequired(false);
        yield ArrayField::new('emails', 'Email')->setRequired(false);
        // Без пустого варианта: у контакта всегда должен быть владелец.
        yield AssociationField::new('family', 'Семья')
            ->setRequired(true)
            ->setFormTypeOption('placeholder', false)
            ->setHelp('Чтобы убрать контакт из семьи, удалите его или верните в личные контакты пользователя (действие «Вернуть в личные»).');
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
