<?php

namespace App\Tests\Api;

use App\Tests\DatabaseWebTestCase;

final class ConnectivityTest extends DatabaseWebTestCase
{
    public function testConnectivityCheckAnswersEmpty204WithoutAuth(): void
    {
        $this->client->request('GET', '/generate_204');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }
}
