<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class DatabaseWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'secret-pass';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
    }

    protected function createUser(string $email, bool $admin = false): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        if ($admin) {
            $user->setRoles([User::ROLE_ADMIN]);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return mixed декодированный JSON ответа
     */
    protected function api(string $method, string $uri, ?array $body = null, ?string $token = null): mixed
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    protected function login(string $email): string
    {
        $response = $this->api('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return $response['token'];
    }
}
