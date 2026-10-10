<?php

namespace App\Controller\Api;

use App\Dto\CalendarInput;
use App\Dto\EventChangesInput;
use App\Dto\EventInput;
use App\Entity\Calendar;
use App\Entity\CalendarEvent;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\CalendarRepository;
use App\Service\CalendarService;
use App\Service\EventTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Календарь: REST API для приложения и внешних клиентов (по токену API).
 * Синхронизация устройств — `GET …/events?since=` и `POST …/changes`; разовые правки — `/api/events/{id}`.
 */
#[Route('/api')]
final class CalendarController extends AbstractController
{
    /** Самый длинный период в `GET /api/events`. */
    private const MAX_PERIOD_DAYS = 366;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarRepository $calendars,
        private readonly CalendarEventRepository $events,
        private readonly CalendarService $service,
    ) {
    }

    #[Route('/calendars', name: 'api_calendars', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'calendars' => array_map(
                static fn (Calendar $c) => CalendarService::calendarView($c, $user),
                $this->service->visibleCalendars($user),
            ),
        ]);
    }

    #[Route('/calendars', name: 'api_calendar_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CalendarInput $input): JsonResponse
    {
        $calendar = (new Calendar($user, trim($input->name)))->setFamilyShared($input->familyShared);
        if ($input->color !== null) {
            $calendar->setColor($input->color);
        }
        $this->em->persist($calendar);
        $this->em->flush();

        return $this->json(CalendarService::calendarView($calendar, $user), Response::HTTP_CREATED);
    }

    #[Route('/calendars/{id}', name: 'api_calendar_update', methods: ['PUT'])]
    public function update(#[CurrentUser] User $user, string $id, #[MapRequestPayload] CalendarInput $input): JsonResponse
    {
        $calendar = $this->ownCalendar($user, $id);
        $calendar->setName(trim($input->name))->setFamilyShared($input->familyShared);
        if ($input->color !== null) {
            $calendar->setColor($input->color);
        }
        $this->em->flush();

        return $this->json(CalendarService::calendarView($calendar, $user));
    }

    /** Календарь удаляется вместе с событиями; устройства уберут его, не найдя в списке. */
    #[Route('/calendars/{id}', name: 'api_calendar_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, string $id): JsonResponse
    {
        $this->em->remove($this->ownCalendar($user, $id));
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** События, изменённые после ревизии since, включая удалённые, — для синхронизации. */
    #[Route('/calendars/{id}/events', name: 'api_calendar_events', methods: ['GET'])]
    public function changes(#[CurrentUser] User $user, string $id, #[MapQueryParameter] int $since = 0): JsonResponse
    {
        $calendar = $this->accessibleCalendar($user, $id);

        return $this->json([
            'revision' => $calendar->getRevision(),
            'events' => array_map(CalendarService::view(...), $this->events->findChangedSince($calendar, $since)),
        ]);
    }

    #[Route('/calendars/{id}/changes', name: 'api_calendar_push', methods: ['POST'])]
    public function push(#[CurrentUser] User $user, string $id, #[MapRequestPayload] EventChangesInput $input): JsonResponse
    {
        $calendar = $this->accessibleCalendar($user, $id);
        $results = $this->service->apply($calendar, $user, $input->changes);

        return $this->json(['revision' => $calendar->getRevision(), 'results' => $results]);
    }

    #[Route('/calendars/{id}/events', name: 'api_event_create', methods: ['POST'])]
    public function createEvent(#[CurrentUser] User $user, string $id, #[MapRequestPayload] EventInput $input): JsonResponse
    {
        $calendar = $this->accessibleCalendar($user, $id);
        $change = new EventInput($input->id, null, false, $input->title, $input->start, $input->end, $input->allDay,
            $input->location, $input->description, $input->color, $input->rrule);

        return $this->single($this->service->apply($calendar, $user, [$change])[0], Response::HTTP_CREATED);
    }

    /**
     * События всех доступных календарей (или одного — `calendar`) за период [from, to).
     * from и to — дата или дата-время ISO 8601 со смещением.
     */
    #[Route('/events', name: 'api_events', methods: ['GET'])]
    public function between(
        #[CurrentUser] User $user,
        #[MapQueryParameter] string $from = '',
        #[MapQueryParameter] string $to = '',
        #[MapQueryParameter] ?string $calendar = null,
    ): JsonResponse {
        $start = EventTime::parse($from, true) ?? EventTime::parse($from, false);
        $end = EventTime::parse($to, true) ?? EventTime::parse($to, false);
        if ($start === null || $end === null || $end <= $start) {
            return $this->json(['error' => 'Нужен период: from и to — дата YYYY-MM-DD или время ISO 8601, to позже from.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($start->diff($end)->days > self::MAX_PERIOD_DAYS) {
            return $this->json(['error' => sprintf('Период не больше %d дней.', self::MAX_PERIOD_DAYS)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $calendars = $calendar !== null ? [$this->accessibleCalendar($user, $calendar)] : $this->service->visibleCalendars($user);

        return $this->json(['events' => array_map(CalendarService::view(...), $this->events->findBetween($calendars, $start, $end))]);
    }

    #[Route('/events/{id}', name: 'api_event_get', methods: ['GET'])]
    public function getEvent(#[CurrentUser] User $user, string $id): JsonResponse
    {
        $event = $this->accessibleEvent($user, $id);
        if ($event->isDeleted()) {
            throw $this->createNotFoundException('Событие удалено.');
        }

        return $this->json(CalendarService::view($event));
    }

    /** Без baseRevision правка применяется к текущей версии; с ней — только если событие не меняли. */
    #[Route('/events/{id}', name: 'api_event_update', methods: ['PUT'])]
    public function updateEvent(#[CurrentUser] User $user, string $id, #[MapRequestPayload] EventInput $input): JsonResponse
    {
        $event = $this->accessibleEvent($user, $id);
        if ($event->isDeleted()) {
            throw $this->createNotFoundException('Событие удалено.');
        }
        $change = new EventInput($id, $input->baseRevision ?? $event->getRevision(), false, $input->title, $input->start,
            $input->end, $input->allDay, $input->location, $input->description, $input->color, $input->rrule);

        return $this->single($this->service->apply($event->getCalendar(), $user, [$change])[0]);
    }

    #[Route('/events/{id}', name: 'api_event_delete', methods: ['DELETE'])]
    public function deleteEvent(#[CurrentUser] User $user, string $id, #[MapQueryParameter] ?int $baseRevision = null): JsonResponse
    {
        $event = $this->accessibleEvent($user, $id);
        if (!$event->isDeleted()) {
            $result = $this->service->apply($event->getCalendar(), $user, [new EventInput($id, $baseRevision, true)])[0];
            if ($result['status'] !== 'ok') {
                return $this->single($result);
            }
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed> $result */
    private function single(array $result, int $okStatus = Response::HTTP_OK): JsonResponse
    {
        return match ($result['status']) {
            'ok' => $this->json($result['event'] ?? null, $okStatus),
            'conflict' => $this->json(['error' => 'Событие изменилось с тех пор.', 'current' => $result['current']], Response::HTTP_CONFLICT),
            default => $this->json(['error' => $result['error'] ?? 'Правка отклонена.'], Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }

    private function accessibleCalendar(User $user, string $id): Calendar
    {
        $calendar = $this->calendars->findOneByUuid($id);
        if ($calendar === null || !$this->service->canAccess($user, $calendar)) {
            throw $this->createNotFoundException('Календарь не найден.');
        }

        return $calendar;
    }

    private function ownCalendar(User $user, string $id): Calendar
    {
        $calendar = $this->accessibleCalendar($user, $id);
        if ($calendar->getOwner() !== $user) {
            throw $this->createAccessDeniedException('Менять календарь может только владелец.');
        }

        return $calendar;
    }

    private function accessibleEvent(User $user, string $id): CalendarEvent
    {
        $event = $this->events->findOneByUuid($id);
        if ($event === null || !$this->service->canAccess($user, $event->getCalendar())) {
            throw $this->createNotFoundException('Событие не найдено.');
        }

        return $event;
    }
}
