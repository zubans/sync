<?php

namespace App\Doctrine;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * «Семья» у контакта — лишь отметка общего доступа, сам контакт принадлежит пользователю. Поэтому:
 * - пользователь вышел из семьи — его контакты больше не общие для неё;
 * - семью удалили — её контакты остаются у владельцев, снимается только отметка
 *   (иначе сработал бы каскад по внешнему ключу и контакты удалились бы вместе с семьёй).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FamilySharingListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $unshare = static fn (string $where, array $params) => $em->createQuery(
            'UPDATE '.Contact::class.' c SET c.family = NULL WHERE '.$where,
        )->execute($params);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof User && isset($uow->getEntityChangeSet($entity)['family'])) {
                $oldFamily = $uow->getEntityChangeSet($entity)['family'][0];
                if ($oldFamily !== null) {
                    $unshare('c.user = :user AND c.family = :family', ['user' => $entity, 'family' => $oldFamily]);
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Family && $entity->getId() !== null) {
                $unshare('c.family = :family', ['family' => $entity]);
            }
        }
    }
}
