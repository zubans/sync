<?php

namespace App\Tests\Api;

use App\Entity\BrowsingVisit;
use App\Tests\DatabaseWebTestCase;

final class BrowsingApiTest extends DatabaseWebTestCase
{
    private const DEVICE = ['installId' => 'install-browsing-01', 'model' => 'Pixel'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testVisitsAreSavedAndDeduplicatedOnReupload(): void
    {
        $visits = [
            ['url' => 'example.com', 'title' => 'Example', 'browser' => 'com.android.chrome', 'visitedAt' => 1_700_000_000_000],
            ['url' => 'news.example.com/article', 'browser' => 'com.android.chrome', 'visitedAt' => 1_700_000_100_000],
        ];

        self::assertSame(2, $this->upload($visits)['saved']);

        // Повторная выгрузка тех же посещений (ретрай) ничего не добавляет.
        self::assertSame(0, $this->upload($visits)['saved']);

        // То же время, другой адрес — отдельное посещение.
        $more = [['url' => 'other.example.com', 'browser' => 'com.android.chrome', 'visitedAt' => 1_700_000_000_000]];
        self::assertSame(1, $this->upload($more)['saved']);

        $saved = $this->em->getRepository(BrowsingVisit::class)->findAll();
        self::assertCount(3, $saved);
    }

    public function testVisitsAreScopedToDevice(): void
    {
        $this->upload([['url' => 'example.com', 'browser' => 'com.android.chrome', 'visitedAt' => 1_700_000_000_000]]);

        $otherDevice = ['installId' => 'install-browsing-02', 'model' => 'Poco'];
        $response = $this->api('POST', '/api/browsing', [
            'device' => $otherDevice,
            'visits' => [['url' => 'example.com', 'browser' => 'com.android.chrome', 'visitedAt' => 1_700_000_000_000]],
        ], $this->token);

        // То же посещение с другого устройства — своя запись.
        self::assertSame(1, $response['saved']);
        self::assertCount(2, $this->em->getRepository(BrowsingVisit::class)->findAll());
    }

    public function testRequiresAuth(): void
    {
        $this->api('POST', '/api/browsing', ['device' => self::DEVICE, 'visits' => []]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<array<string, mixed>> $visits
     *
     * @return array<string, mixed>
     */
    private function upload(array $visits): array
    {
        $response = $this->api('POST', '/api/browsing', ['device' => self::DEVICE, 'visits' => $visits], $this->token);
        self::assertResponseIsSuccessful();

        return $response;
    }
}
