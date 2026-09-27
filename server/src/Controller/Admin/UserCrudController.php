<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пользователь')
            ->setEntityLabelInPlural('Пользователи')
            ->setDefaultSort(['email' => 'ASC'])
            ->setSearchFields(['email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $contacts = Action::new('contacts', 'Контакты', 'fa fa-address-book')
            ->linkToUrl(fn (User $user) => ContactCrudController::indexUrlForUser($this->container->get(AdminUrlGeneratorInterface::class), $user));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $contacts)
            ->add(Crud::PAGE_DETAIL, $contacts);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('family');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email', 'Email');
        yield TextField::new('plainPassword', Crud::PAGE_NEW === $pageName ? 'Пароль' : 'Новый пароль')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Оставьте пустым, чтобы не менять.' : 'Не короче 8 символов.')
            ->onlyOnForms();
        yield ChoiceField::new('roles', 'Роли')
            ->setChoices(['Администратор' => User::ROLE_ADMIN])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges();
        yield AssociationField::new('family', 'Семья');
        yield DateTimeField::new('createdAt', 'Создан')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        $plain = $user->getPlainPassword();
        if ($plain !== null && $plain !== '') {
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->setPlainPassword(null);
        }
    }
}
