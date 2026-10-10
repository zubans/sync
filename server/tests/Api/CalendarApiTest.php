<?php

namespace App\Tests\Api;

use App\Entity\Family;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CalendarApiTest extends DatabaseWebTestCase
{
    private const EVENT = '0192f0c1-3333-7000-8000-000000000001';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testDefaultCalendarIsCreatedOnFirstList(): void
    {
        $list = $this->api('GET', '/api/calendars', token: $this->token);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $list['calendars']);
        self::assertSame('Личный', $list['calendars'][0]['name']);
        self::assertTrue($list['calendars'][0]['mine']);

        // Второй запрос не плодит календари.
        self::assertCount(1, $this->api('GET', '/api/calendars', token: $this->token)['calendars']);
    }

    public function testEventLifecycleThroughRestApi(): void
    {
        $calendar = $this->defaultCalendar();

        $created = $this->api('POST', "/api/calendars/$calendar/events", [
            'title' => 'Созвон по sync',
            'start' => '2026-10-10T15:00:00+03:00',
            'end' => '2026-10-10T15:30:00+03:00',
            'location' => 'Zoom',
        ], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-10-10T12:00:00Z', $created['start']);
        self::assertSame('2026-10-10T12:30:00Z', $created['end']);
        self::assertSame('anna@example.com', $created['updatedBy']);

        $id = $created['id'];
        $updated = $this->api('PUT', "/api/events/$id", ['title' => 'Созвон по календарю', 'start' => '2026-10-10T12:00:00Z',
            'end' => '2026-10-10T13:00:00Z', 'baseRevision' => $created['revision']], $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame('Созвон по календарю', $updated['title']);
        self::assertNull($updated['location']);

        // Правка от устаревшей ревизии — конфликт с текущей версией.
        $stale = $this->api('PUT', "/api/events/$id", ['title' => 'Старое', 'start' => '2026-10-10T12:00:00Z',
            'end' => '2026-10-10T13:00:00Z', 'baseRevision' => $created['revision']], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Созвон по календарю', $stale['current']['title']);

        self::assertSame('Созвон по календарю', $this->api('GET', "/api/events/$id", token: $this->token)['title']);

        $this->api('DELETE', "/api/events/$id", token: $this->token);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', "/api/events/$id", token: $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAllDayEventUsesDatesWithExclusiveEnd(): void
    {
        $calendar = $this->defaultCalendar();

        $event = $this->api('POST', "/api/calendars/$calendar/events",
            ['title' => 'ДР мамы', 'start' => '2026-10-11', 'end' => '2026-10-12', 'allDay' => true], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-10-11', $event['start']);
        self::assertSame('2026-10-12', $event['end']);

        $this->api('POST', "/api/calendars/$calendar/events",
            ['title' => 'Пусто', 'start' => '2026-10-11', 'end' => '2026-10-11', 'allDay' => true], $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeWithoutOffsetIsRejected(): void
    {
        $calendar = $this->defaultCalendar();

        $this->api('POST', "/api/calendars/$calendar/events",
            ['title' => 'Встреча', 'start' => '2026-10-10T15:00:00', 'end' => '2026-10-10T16:00:00'], $this->token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPeriodQueryReturnsOverlappingAndRecurringEvents(): void
    {
        $calendar = $this->defaultCalendar();
        $this->push($calendar, [
            ['id' => self::EVENT, 'title' => 'В периоде', 'start' => '2026-10-12T09:00:00Z', 'end' => '2026-10-12T10:00:00Z'],
            ['id' => '0192f0c1-3333-7000-8000-000000000002', 'title' => 'Через период', 'start' => '2026-10-09T09:00:00Z', 'end' => '2026-10-20T10:00:00Z'],
            ['id' => '0192f0c1-3333-7000-8000-000000000003', 'title' => 'После', 'start' => '2026-11-01T09:00:00Z', 'end' => '2026-11-01T10:00:00Z'],
            ['id' => '0192f0c1-3333-7000-8000-000000000004', 'title' => 'Планёрка', 'start' => '2026-09-01T07:00:00Z', 'end' => '2026-09-01T07:15:00Z', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO'],
        ]);

        $events = $this->api('GET', '/api/events?from=2026-10-10&to=2026-10-17', token: $this->token)['events'];

        self::assertSame(['Планёрка', 'Через период', 'В периоде'], array_column($events, 'title'));

        $this->api('GET', '/api/events?from=2026-10-10&to=2028-10-10', token: $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSyncPullsChangesAndTombstonesSinceRevision(): void
    {
        $calendar = $this->defaultCalendar();
        $first = $this->push($calendar, [
            ['id' => self::EVENT, 'title' => 'Первое', 'start' => '2026-10-12T09:00:00Z', 'end' => '2026-10-12T10:00:00Z'],
            ['id' => '0192f0c1-3333-7000-8000-000000000002', 'title' => 'Второе', 'start' => '2026-10-13T09:00:00Z', 'end' => '2026-10-13T10:00:00Z'],
        ]);
        self::assertSame(['ok', 'ok'], array_column($first['results'], 'status'));
        $since = $first['revision'];

        $this->push($calendar, [['id' => self::EVENT, 'baseRevision' => $first['results'][0]['revision'], 'deleted' => true]]);

        self::assertCount(2, $this->api('GET', "/api/calendars/$calendar/events", token: $this->token)['events']);
        $changes = $this->api('GET', "/api/calendars/$calendar/events?since=$since", token: $this->token);
        self::assertCount(1, $changes['events']);
        self::assertSame(self::EVENT, $changes['events'][0]['id']);
        self::assertTrue($changes['events'][0]['deleted']);
        self::assertNull($changes['events'][0]['title']);
        self::assertSame($changes['revision'], $changes['events'][0]['revision']);
    }

    public function testCreatingExistingIdIsConflict(): void
    {
        $calendar = $this->defaultCalendar();
        $this->push($calendar, [['id' => self::EVENT, 'title' => 'Первое', 'start' => '2026-10-12T09:00:00Z', 'end' => '2026-10-12T10:00:00Z']]);

        $again = $this->push($calendar, [['id' => self::EVENT, 'title' => 'Другое', 'start' => '2026-10-12T09:00:00Z', 'end' => '2026-10-12T10:00:00Z']]);

        self::assertSame('conflict', $again['results'][0]['status']);
        self::assertSame('Первое', $again['results'][0]['current']['title']);
    }

    public function testFamilyCalendarIsSharedButOnlyOwnerManagesIt(): void
    {
        $family = (new Family())->setName('Зюбаны');
        $this->em->persist($family);
        $this->userByEmail('anna@example.com')->setFamily($family);
        $this->createUser('boris@example.com')->setFamily($family);
        $this->createUser('vera@example.com');
        $this->em->flush();

        $shared = $this->api('POST', '/api/calendars', ['name' => 'Семья', 'color' => '#34a853', 'familyShared' => true], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('#34A853', $shared['color']);
        $this->api('POST', '/api/calendars', ['name' => 'Работа'], $this->token);

        $borisToken = $this->login('boris@example.com');
        $borisCalendars = $this->api('GET', '/api/calendars', token: $borisToken)['calendars'];
        self::assertEqualsCanonicalizing(['Семья', 'Личный'], array_column($borisCalendars, 'name'));

        // Член семьи добавляет событие в семейный календарь…
        $this->api('POST', "/api/calendars/{$shared['id']}/events",
            ['title' => 'Дача', 'start' => '2026-10-17', 'end' => '2026-10-19', 'allDay' => true], $borisToken);
        self::assertResponseStatusCodeSame(201);
        // …но переименовать или удалить календарь не может.
        $this->api('PUT', "/api/calendars/{$shared['id']}", ['name' => 'Моя семья', 'familyShared' => true], $borisToken);
        self::assertResponseStatusCodeSame(403);
        $this->api('DELETE', "/api/calendars/{$shared['id']}", token: $borisToken);
        self::assertResponseStatusCodeSame(403);

        // Чужой человек семейный календарь не видит.
        $vera = $this->login('vera@example.com');
        $this->api('GET', "/api/calendars/{$shared['id']}/events", token: $vera);
        self::assertResponseStatusCodeSame(404);

        // Борис вышел из семьи — семейный календарь Анны ему больше не виден.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(User::class)->findOneBy(['email' => 'boris@example.com'])->setFamily(null);
        $em->flush();
        $this->api('GET', "/api/calendars/{$shared['id']}/events", token: $borisToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerDeletesCalendarWithEvents(): void
    {
        $work = $this->api('POST', '/api/calendars', ['name' => 'Работа'], $this->token);
        $this->push($work['id'], [['id' => self::EVENT, 'title' => 'Ревью', 'start' => '2026-10-12T09:00:00Z', 'end' => '2026-10-12T10:00:00Z']]);

        $this->api('DELETE', "/api/calendars/{$work['id']}", token: $this->token);
        self::assertResponseStatusCodeSame(204);

        $this->api('GET', '/api/events/'.self::EVENT, token: $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCalendarApiRequiresToken(): void
    {
        $this->api('GET', '/api/calendars');

        self::assertResponseStatusCodeSame(401);
    }

    private function defaultCalendar(): string
    {
        return $this->api('GET', '/api/calendars', token: $this->token)['calendars'][0]['id'];
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return array<string, mixed>
     */
    private function push(string $calendar, array $changes): array
    {
        $result = $this->api('POST', "/api/calendars/$calendar/changes", ['changes' => $changes], $this->token);
        self::assertResponseIsSuccessful();

        return $result;
    }

    private function userByEmail(string $email): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
