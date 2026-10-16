<?php

namespace App\Tests\Command;

use App\Entity\NewsSource;
use App\Service\AgyAccounts;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AgyStatusCommandTest extends DatabaseWebTestCase
{
    public function testShowsRestingModelsAndQueue(): void
    {
        $this->em->persist(new NewsSource('digest', 'Сводка', '', NewsSource::FORMAT_DIGEST));
        $this->em->flush();
        $home = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/agy';
        @mkdir($home, 0o777, true);
        @unlink($home.'/agy-state.json');
        (new AgyAccounts($home))->markModelExhausted($home, 'Gemini 3.8 Flash (Medium)', time(), 7200);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:agy:status'));
        $tester->execute([]);
        $out = $tester->getDisplay();
        @unlink($home.'/agy-state.json');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('основной — вход не выполнен', $out);
        self::assertStringContainsString('отдыхает «Gemini 3.8 Flash (Medium)» до', $out);
        self::assertStringContainsString('заголовки к переводу (за сутки): 0', $out);
        self::assertStringContainsString('последняя: ещё не было', $out);
    }
}
