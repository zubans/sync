<?php

namespace App\Doctrine;

use App\Entity\Contact;
use App\Entity\ContactTombstone;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Физическое удаление личного контакта (его делает только администратор: с устройств контакты
 * удаляются мягко) оставляет «надгробие», чтобы контакт удалился и с телефонов пользователя.
 * Работает для любого пути удаления через ORM: карточка, групповое удаление, код.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ContactTombstoneListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ContactTombstone::class);

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof Contact || $entity->getUser() === null) {
                continue;
            }
            $tombstone = new ContactTombstone($entity->getUser(), $entity->getUuid());
            $em->persist($tombstone);
            $uow->computeChangeSet($metadata, $tombstone);
        }
    }
}
