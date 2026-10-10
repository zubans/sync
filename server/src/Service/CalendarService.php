<?php

namespace App\Service;

use App\Dto\EventInput;
use App\Entity\Calendar;
use App\Entity\CalendarEvent;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\CalendarRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Календари и события.
 *
 * Доступ: свой календарь — всегда; семейный (familyShared) — всем членам семьи владельца,
 * они его видят и правят, но переименовать, перекрасить или удалить календарь может только владелец.
 *
 * Правки событий — с оптимистичной блокировкой: клиент присылает ревизию, от которой правил.
 * Если событие с тех пор изменили, правка не применяется — клиент получает конфликт и текущую
 * версию. Удаление оставляет «надгробие», чтобы оно дошло до всех устройств.
 */
final class CalendarService
{
    public const DEFAULT_NAME = 'Личный';
    /** Предел событий в календаре, считая удалённые. */
    public const MAX_EVENTS = 20000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventRepository $events,
    ) {
    }

    /**
     * Календари пользователя; если своих нет — создаём «Личный», чтобы устройству было куда писать.
     *
     * @return list<Calendar>
     */
    public function visibleCalendars(User $user): array
    {
        $visible = $this->calendars->findVisibleTo($user);
        if (!array_filter($visible, static fn (Calendar $c) => $c->getOwner() === $user)) {
            $own = new Calendar($user, self::DEFAULT_NAME);
            $this->em->persist($own);
            $this->em->flush();
            array_unshift($visible, $own);
        }

        return $visible;
    }

    public function canAccess(User $user, Calendar $calendar): bool
    {
        $owner = $calendar->getOwner();

        return $owner === $user
            || ($calendar->isFamilyShared() && $owner->getFamily() !== null && $owner->getFamily() === $user->getFamily());
    }

    /**
     * @param list<EventInput> $changes
     *
     * @return list<array<string, mixed>> результат по каждой правке: ok | conflict | rejected
     */
    public function apply(Calendar $calendar, User $user, array $changes): array
    {
        return $this->em->wrapInTransaction(function () use ($calendar, $user, $changes): array {
            // Правки с разных устройств сериализуются на строке календаря: счётчик ревизий общий.
            $this->em->lock($calendar, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($calendar);

            $ids = array_values(array_filter(array_unique(array_map(static fn (EventInput $c) => $c->id, $changes))));
            $existing = $this->events->findByUuids($ids);
            $total = $this->events->count(['calendar' => $calendar]);
            $results = [];

            foreach ($changes as $change) {
                $id = $change->id ?? Uuid::v7()->toRfc4122();
                $event = $existing[$id] ?? null;

                if ($event !== null && $event->getCalendar() !== $calendar) {
                    // Тот же id в другом календаре: переносить события между календарями API не умеет.
                    $results[] = ['id' => $id, 'status' => 'rejected', 'error' => 'Событие принадлежит другому календарю.'];
                    continue;
                }
                if ($event === null && $change->deleted) {
                    // Удаление события, которого сервер не видел: делать нечего.
                    $results[] = ['id' => $id, 'status' => 'ok', 'revision' => 0];
                    continue;
                }
                if ($event === null) {
                    if ($total >= self::MAX_EVENTS) {
                        $results[] = ['id' => $id, 'status' => 'rejected', 'error' => 'В календаре слишком много событий.'];
                        continue;
                    }
                    $event = new CalendarEvent($calendar, $id);
                    $this->em->persist($event);
                    $existing[$id] = $event;
                    ++$total;
                } elseif ($change->baseRevision !== null && $change->baseRevision !== $event->getRevision()) {
                    $results[] = ['id' => $id, 'status' => 'conflict', 'current' => self::view($event)];
                    continue;
                } elseif ($change->baseRevision === null && !$change->deleted && !$event->isDeleted()) {
                    // Создание с уже занятым id — это другое событие или повтор; не затираем молча.
                    $results[] = ['id' => $id, 'status' => 'conflict', 'current' => self::view($event)];
                    continue;
                }

                if ($change->deleted) {
                    $event->markDeleted($user);
                } else {
                    $this->fill($event, $change);
                    $event->touch($user);
                }
                $results[] = ['id' => $id, 'status' => 'ok', 'revision' => $event->getRevision(), 'event' => self::view($event)];
            }

            $this->em->flush();

            return $results;
        });
    }

    private function fill(CalendarEvent $event, EventInput $input): void
    {
        $event
            ->setTitle(trim($input->title))
            ->setAllDay($input->allDay)
            ->setStartAt(EventTime::parse($input->start, $input->allDay) ?? throw new \LogicException('Время проверено валидатором.'))
            ->setEndAt(EventTime::parse($input->end, $input->allDay) ?? throw new \LogicException('Время проверено валидатором.'))
            ->setLocation(self::blankToNull($input->location))
            ->setDescription(self::blankToNull($input->description))
            ->setColor($input->color)
            ->setRrule(self::blankToNull($input->rrule));
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    public static function calendarView(Calendar $calendar, User $viewer): array
    {
        return [
            'id' => $calendar->getUuid(),
            'name' => $calendar->getName(),
            'color' => $calendar->getColor(),
            'familyShared' => $calendar->isFamilyShared(),
            'owner' => $calendar->getOwner()->getEmail(),
            'mine' => $calendar->getOwner() === $viewer,
            'revision' => $calendar->getRevision(),
        ];
    }

    /** @return array<string, mixed> */
    public static function view(CalendarEvent $event): array
    {
        $deleted = $event->isDeleted();

        return [
            'id' => $event->getUuid(),
            'calendarId' => $event->getCalendar()->getUuid(),
            'revision' => $event->getRevision(),
            'deleted' => $deleted,
            'title' => $deleted ? null : $event->getTitle(),
            'start' => $deleted ? null : EventTime::format($event->getStartAt(), $event->isAllDay()),
            'end' => $deleted ? null : EventTime::format($event->getEndAt(), $event->isAllDay()),
            'allDay' => $event->isAllDay(),
            'location' => $deleted ? null : $event->getLocation(),
            'description' => $deleted ? null : $event->getDescription(),
            'color' => $event->getColor(),
            'rrule' => $deleted ? null : $event->getRrule(),
            'updatedBy' => $event->getUpdatedBy()?->getEmail(),
            'updatedAt' => $event->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
