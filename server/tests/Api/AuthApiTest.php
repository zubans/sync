<?php

namespace App\Tests\Api;

use App\Tests\DatabaseWebTestCase;

final class AuthApiTest extends DatabaseWebTestCase
{
    public function testRegisterReturnsWorkingToken(): void
    {
        $response = $this->api('POST', '/api/auth/register', ['email' => 'New@Example.com', 'password' => 'password1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('new@example.com', $response['user']['email']);
        self::assertNull($response['user']['family']);

        $me = $this->api('GET', '/api/me', token: $response['token']);
        self::assertResponseIsSuccessful();
        self::assertSame('new@example.com', $me['email']);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->createUser('anna@example.com');

        $this->api('POST', '/api/auth/register', ['email' => 'ANNA@example.com', 'password' => 'password1']);

        self::assertResponseStatusCodeSame(409);
    }

    public function testRegisterValidatesInput(): void
    {
        $this->api('POST', '/api/auth/register', ['email' => 'not-an-email', 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->createUser('anna@example.com');

        $this->api('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => 'wrong-password']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testApiRequiresToken(): void
    {
        $this->api('GET', '/api/contacts');
        self::assertResponseStatusCodeSame(401);

        $this->api('GET', '/api/contacts', token: 'garbage');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesToken(): void
    {
        $this->createUser('anna@example.com');
        $token = $this->login('anna@example.com');

        $this->api('POST', '/api/auth/logout', token: $token);
        self::assertResponseStatusCodeSame(204);

        $this->api('GET', '/api/me', token: $token);
        self::assertResponseStatusCodeSame(401);
    }
}
