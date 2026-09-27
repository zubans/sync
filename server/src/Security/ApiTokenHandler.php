<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    /** Как часто обновлять lastUsedAt, чтобы не писать в БД на каждый запрос. */
    private const TOUCH_INTERVAL = 'PT1H';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findOneBy(['tokenHash' => ApiToken::hash($accessToken)]);
        if ($token === null) {
            throw new BadCredentialsException('Invalid token.');
        }

        if ($token->getLastUsedAt() < (new \DateTimeImmutable())->sub(new \DateInterval(self::TOUCH_INTERVAL))) {
            $token->markUsed();
            $this->em->flush();
        }

        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
