<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Общее для списков контактов:
 * - «Удалить» отправляет в корзину: контакт удаляется с телефонов, но его можно восстановить
 *   в течение Contact::TRASH_DAYS дней. Окончательно удаляют только из корзины;
 * - правка в админке уходит на телефоны владельца (ContactSyncService отдаёт её в updates).
 */
trait ManagesContacts
{
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Contact);
        // Сравниваем с загруженным из БД напрямую: computeChangeSets() до flush() сбросил бы исходные
        // данные, и flush() записал бы только номер правки.
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        foreach (Contact::SYNCED_FIELDS as $field) {
            if (($original[$field] ?? null) !== $entityManager->getClassMetadata(Contact::class)->getFieldValue($entityInstance, $field)) {
                $entityInstance->markEditedOnServer();
                break;
            }
        }
        $entityManager->flush();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Contact);
        $entityInstance->moveToTrash();
        $entityManager->flush();
    }

    private static function trashLabels(Actions $actions): Actions
    {
        $toTrash = static fn (Action $a) => $a->setLabel('В корзину')->setIcon('fa fa-trash');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::DELETE, $toTrash)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $toTrash)
            ->update(Crud::PAGE_INDEX, Action::BATCH_DELETE, $toTrash);
    }
}
