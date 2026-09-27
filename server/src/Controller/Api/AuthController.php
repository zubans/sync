<?php

namespace App\Controller\Api;

use App\Dto\CredentialsInput;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/auth/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] CredentialsInput $input,
        Request $request,
        ValidatorInterface $validator,
        #[Target('api_register.limiter')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['error' => 'Слишком много попыток, попробуйте позже.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = (new User())->setEmail($input->email);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));

        $violations = $validator->validate($user);
        if (\count($violations) > 0) {
            return $this->json(['error' => $violations[0]->getMessage()], Response::HTTP_CONFLICT);
        }

        $this->em->persist($user);

        return $this->json($this->issueToken($user), Response::HTTP_CREATED);
    }

    #[Route('/auth/login', name: 'api_login', methods: ['POST'])]
    public function login(
        #[MapRequestPayload] CredentialsInput $input,
        Request $request,
        UserRepository $users,
        #[Target('api_login.limiter')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $email = mb_strtolower(trim($input->email));
        $limit = $limiter->create($request->getClientIp().'|'.$email);
        if (!$limit->consume()->isAccepted()) {
            return $this->json(['error' => 'Слишком много попыток, попробуйте позже.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $users->findOneBy(['email' => $email]);
        if ($user === null || !$this->hasher->isPasswordValid($user, $input->password)) {
            return $this->json(['error' => 'Неверный email или пароль.'], Response::HTTP_UNAUTHORIZED);
        }
        $limit->reset();

        if ($this->hasher->needsRehash($user)) {
            $user->setPassword($this->hasher->hashPassword($user, $input->password));
        }

        return $this->json($this->issueToken($user));
    }

    #[Route('/auth/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request, ApiTokenRepository $tokens): JsonResponse
    {
        $plain = substr((string) $request->headers->get('Authorization'), \strlen('Bearer '));
        $token = $tokens->findOneBy(['tokenHash' => ApiToken::hash($plain)]);
        if ($token !== null) {
            $this->em->remove($token);
            $this->em->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(self::userView($user));
    }

    /** @return array<string, mixed> */
    private function issueToken(User $user): array
    {
        [$token, $plain] = ApiToken::issue($user);
        $this->em->persist($token);
        $this->em->flush();

        return ['token' => $plain, 'user' => self::userView($user)];
    }

    /** @return array<string, mixed> */
    private static function userView(User $user): array
    {
        $family = $user->getFamily();

        return [
            'email' => $user->getEmail(),
            'family' => $family === null ? null : ['id' => $family->getId(), 'name' => $family->getName()],
        ];
    }
}
