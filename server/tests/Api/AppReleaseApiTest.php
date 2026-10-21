<?php

namespace App\Tests\Api;

use App\Entity\AppRelease;
use App\Repository\AppReleaseRepository;
use App\Service\AppReleaseService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AppReleaseApiTest extends DatabaseWebTestCase
{
    public function testNoReleaseYet(): void
    {
        $this->client->request('GET', '/api/app/version');

        self::assertResponseStatusCodeSame(404);
    }

    public function testVersionAndDownloadWorkWithoutLogin(): void
    {
        $apk = $this->apk('сборка 12');
        $this->service()->publish($apk, 12, '1.12', 'tree-12');

        $version = $this->api('GET', '/api/app/version');
        self::assertResponseIsSuccessful();
        self::assertSame([12, '1.12', hash_file('sha256', $apk), filesize($apk)], [$version['versionCode'], $version['versionName'], $version['sha256'], $version['size']]);
        self::assertStringEndsWith('/api/app/download/12', $version['url']);
        self::assertStringEndsWith('/api/app/download', $version['latestUrl']);

        foreach (['/api/app/download/12', '/api/app/download'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertResponseHeaderSame('Content-Type', 'application/vnd.android.package-archive');
            self::assertStringContainsString('contactsync-1.12.apk', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('сборка 12', $this->client->getInternalResponse()->getContent());
        }

        $this->client->request('GET', '/api/app/download/13');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNewerBuildBecomesActiveAndOlderCannotReplaceIt(): void
    {
        $this->service()->publish($this->apk('a'), 12, '1.12', 'tree-12');
        $this->service()->publish($this->apk('b'), 14, '1.14', 'tree-14');

        self::assertSame(14, $this->api('GET', '/api/app/version')['versionCode']);
        self::assertSame('tree-14', $this->revision());

        $this->expectExceptionMessage('Активна более новая версия');
        $this->service()->publish($this->apk('c'), 13, '1.13', 'tree-13');
    }

    public function testOnlyLastBuildsAreKept(): void
    {
        foreach (range(1, AppReleaseService::KEEP + 2) as $code) {
            $this->service()->publish($this->apk('v'.$code), $code, '1.'.$code, 'tree-'.$code);
        }

        $kept = array_map(static fn (AppRelease $r) => $r->getVersionCode(), $this->releases()->findNewestFirst());
        self::assertSame(range(AppReleaseService::KEEP + 2, 3, -1), $kept);
        $this->client->request('GET', '/api/app/download/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSwitchesActiveVersion(): void
    {
        $this->service()->publish($this->apk('a'), 12, '1.12', 'tree-12');
        $this->service()->publish($this->apk('b'), 14, '1.14', 'tree-14');
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $this->client->request('GET', '/admin/app-release');
        self::assertSelectorTextContains('table', '1.14');
        $this->client->request('POST', $this->client->getCrawler()->filter('form[action*="/activate"]')->attr('action'));
        self::assertResponseRedirects('/admin/app-release');

        self::assertSame(12, $this->api('GET', '/api/app/version')['versionCode']);
        $this->em->clear();
        self::assertCount(1, $this->releases()->findBy(['active' => true]));
    }

    public function testActivationRequiresValidToken(): void
    {
        $this->service()->publish($this->apk('a'), 12, '1.12', 'tree-12', activate: false);
        $release = $this->releases()->findOneBy(['versionCode' => 12]);
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $this->client->request('POST', '/admin/app-release/'.$release->getId().'/activate?token=wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->releases()->findActive());
    }

    public function testPublishCommand(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:release:publish'));
        $tester->execute(['apk' => $this->apk('cmd'), '--version-code' => '20', '--version-name' => '1.20', '--revision' => 'tree-20']);

        $tester->assertCommandIsSuccessful();
        self::assertSame('tree-20', $this->revision());
    }

    private function apk(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'apk');
        file_put_contents($file, $content);

        return $file;
    }

    private function revision(): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:release:revision'));
        $tester->execute([]);

        return trim($tester->getDisplay());
    }

    private function service(): AppReleaseService
    {
        return self::getContainer()->get(AppReleaseService::class);
    }

    private function releases(): AppReleaseRepository
    {
        return self::getContainer()->get(AppReleaseRepository::class);
    }
}
