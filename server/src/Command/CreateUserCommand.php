<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Создаёт пользователя или меняет пароль существующему')]
final class CreateUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email (логин)')] string $email,
        #[Option('Выдать роль администратора')] bool $admin = false,
    ): int {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]) ?? (new User())->setEmail($email);
        $password = (string) $io->askHidden('Пароль (не короче 8 символов)');
        $user->setPlainPassword($password);
        if ($admin) {
            $user->setRoles([...$user->getRoles(), User::ROLE_ADMIN]);
        }

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            $io->error((string) $violations);

            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setPlainPassword(null);
        $this->em->persist($user);
        $this->em->flush();

        $io->success(\sprintf('%s: %s', $user->getEmail(), $user->isAdmin() ? 'администратор' : 'пользователь'));

        return Command::SUCCESS;
    }
}
