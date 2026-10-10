<?php

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\CalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /** @return list<CalendarEvent> события, изменённые после ревизии $since, включая удалённые */
    public function findChangedSince(Calendar $calendar, int $since): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.calendar = :calendar')
            ->andWhere('e.revision > :since')
            ->setParameter('calendar', $calendar)
            ->setParameter('since', $since)
            ->orderBy('e.revision', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * События, пересекающиеся с периодом [from, to). Повторяющиеся сервер не раскрывает:
     * отдаёт все, что начались до конца периода, — вхождения считает клиент по RRULE.
     *
     * @param list<Calendar> $calendars
     *
     * @return list<CalendarEvent>
     */
    public function findBetween(array $calendars, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($calendars === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->where('e.calendar IN (:calendars)')
            ->andWhere('e.deleted = false')
            ->andWhere('e.startAt < :to')
            ->andWhere('e.endAt > :from OR e.rrule IS NOT NULL')
            ->setParameter('calendars', $calendars)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUuid(string $uuid): ?CalendarEvent
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, CalendarEvent>
     */
    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return $this->createQueryBuilder('e', 'e.uuid')
            ->where('e.uuid IN (:uuids)')
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getResult();
    }
}
