<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use App\Entity\User;
use App\Service\ContactMover;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Все контакты пользователей. Отметка «Семья» — контакт общий для семьи владельца.
 *
 * @extends AbstractCrudController<Contact>
 */
final class ContactCrudController extends AbstractCrudController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public static function indexUrlForUser(AdminUrlGeneratorInterface $urls, User $user): string
    {
        return $urls->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('filters', ['user' => ['comparison' => '=', 'value' => $user->getId()]])
            ->generateUrl();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Контакт')
            ->setEntityLabelInPlural('Контакты')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                Crud::PAGE_INDEX,
                'Контакт с отметкой «Семья» общий: он ставится на новые устройства членов семьи владельца. «Добавить в семью» и «Убрать из семьи» '
                .'меняют только отметку, с телефонов ничего не удаляется. Контакт, удалённый на телефоне, остаётся здесь с отметкой «Удалён на устройстве». '
                .'Удаление здесь — окончательное: контакт удалится с телефонов при следующей синхронизации.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        $share = self::sharingAction('share', 'Добавить в семью', 'fa fa-people-roof', $this->csrf)
            ->displayIf(static fn (Contact $c) => !$c->isShared() && $c->getUser()?->getFamily() !== null);
        $unshare = self::sharingAction('unshare', 'Убрать из семьи', 'fa fa-user', $this->csrf)
            ->displayIf(static fn (Contact $c) => $c->isShared());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $share)
            ->add(Crud::PAGE_INDEX, $unshare)
            ->add(Crud::PAGE_DETAIL, $share)
            ->add(Crud::PAGE_DETAIL, $unshare);
    }

    /** Кнопка-форма POST на share/unshare; CSRF-токен в URL (EasyAdmin сам его не добавляет). */
    public static function sharingAction(string $name, string $label, string $icon, CsrfTokenManagerInterface $csrf): Action
    {
        return Action::new($name, $label, $icon)
            ->linkToRoute('admin_contact_'.$name, static fn (Contact $c): array => [
                'id' => $c->getId(),
                'token' => $csrf->getToken('sharing'.$c->getId())->getValue(),
            ])
            ->renderAsForm();
    }

    #[AdminRoute('/{id}/share', name: 'share', options: ['methods' => ['POST']])]
    public function share(#[MapEntity(id: 'id')] Contact $contact, Request $request, ContactMover $mover): Response
    {
        $this->checkSharingRequest($contact, $request);
        $family = $contact->getUser()?->getFamily() ?? throw $this->createNotFoundException('Владелец не состоит в семье.');
        $status = array_search(1, $mover->share([$contact], $family), true);
        $this->addFlash($status === ContactMover::SHARED ? 'success' : 'warning', match ($status) {
            ContactMover::SHARED => \sprintf('«%s» теперь общий для семьи «%s».', $contact, $family->getName()),
            ContactMover::DUPLICATE => \sprintf('В семье «%s» уже есть такой общий контакт.', $family->getName()),
            default => 'Контакт уже в семье.',
        });

        return $this->back($request);
    }

    #[AdminRoute('/{id}/unshare', name: 'unshare', options: ['methods' => ['POST']])]
    public function unshare(#[MapEntity(id: 'id')] Contact $contact, Request $request, ContactMover $mover): Response
    {
        $this->checkSharingRequest($contact, $request);
        $family = $contact->getFamily();
        $mover->unshare($contact);
        $this->addFlash('success', \sprintf('«%s» убран из семьи «%s»: остался у владельца и больше не будет приходить на новые устройства.', $contact, $family?->getName()));

        return $this->back($request);
    }

    private function checkSharingRequest(Contact $contact, Request $request): void
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
        if (!$this->isCsrfTokenValid('sharing'.$contact->getId(), (string) $request->query->get('token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
    }

    /** Возврат на страницу, с которой пришли (список контактов или семейных), иначе — к контактам. */
    private function back(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer');

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/admin/')
            ? $this->redirect($referer)
            : $this->redirectToRoute('admin_contact_index');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('user')
            ->add('family')
            ->add(NullFilter::new('deletedAt', 'Удалён на устройстве')->setChoiceLabels('Нет', 'Да'))
            ->add('updatedAt');
    }

    public function configureFields(string $pageName): iterable
    {
        // ImageField без фото показывает бейдж «Null»; строковое поле + formatValue даёт миниатюру или «—».
        // В HTML попадает только SHA-256 (hex), экранировать нечего.
        yield TextField::new('uuid', 'Фото')
            ->formatValue(fn ($value, Contact $contact) => $contact->getPhotoSha256() === null
                ? '—'
                : \sprintf(
                    '<img src="%s" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:50%%">',
                    $this->generateUrl('admin_contact_photo', ['sha256' => $contact->getPhotoSha256()]),
                ))
            ->renderAsHtml()
            ->setSortable(false)
            ->hideOnForm();
        yield TextField::new('name', 'Имя');
        yield ArrayField::new('phones', 'Телефоны')->setRequired(false);
        yield ArrayField::new('emails', 'Email')->setRequired(false);
        yield AssociationField::new('user', 'Владелец')->setRequired(true);
        // В списке — строковое поле с «—» вместо бейджа «Null»; выбор семьи — только в форме.
        yield TextField::new('uuid', 'Семья')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getFamily()?->getName() ?? '—')
            ->setSortable(false)
            ->hideOnForm();
        yield AssociationField::new('family', 'Семья')
            ->setRequired(false)
            ->setHelp('Контакт станет общим для семьи — владелец должен в ней состоять. Пусто — только у владельца.')
            ->onlyOnForms();
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
        // Строковое свойство + formatValue: так вместо бейджа «Null» у живых контактов выводится «—».
        yield TextField::new('uuid', 'Удалён на устройстве')
            ->formatValue(static fn ($value, Contact $contact) => $contact->getDeletedAt()?->format('d.m.Y H:i') ?? '—')
            ->setSortable(false)
            ->hideOnForm();
        yield TextField::new('uuid', 'UUID')->onlyOnDetail();
    }
}
