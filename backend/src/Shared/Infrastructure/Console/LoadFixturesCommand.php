<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Security\SecurityUser;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsCommand(name: 'app:fixtures:load', description: 'Vide la base et charge un jeu de donnees jouable')]
final class LoadFixturesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IdGeneratorInterface $idGenerator,
        private readonly ClockInterface $clock,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now()->format(\DateTimeInterface::ATOM);
        $hash = $this->hasherFactory->getPasswordHasher(SecurityUser::class)->hash('password');

        $this->connection->executeStatement('TRUNCATE messages, conversation_members, conversations, users CASCADE');

        // Identifiants nommes plutot qu'un tableau rempli en boucle : le reste
        // de la commande s'y refere, et une cle construite dynamiquement ne se
        // verifie pas statiquement.
        $alice = $this->idGenerator->generate();
        $bob = $this->idGenerator->generate();
        $carol = $this->idGenerator->generate();

        foreach ([[$alice, 'alice', 'Alice'], [$bob, 'bob', 'Bob'], [$carol, 'carol', 'Carol']] as [$id, $username, $displayName]) {
            $this->connection->executeStatement(
                'INSERT INTO users (id, username, display_name, email, password_hash, provider, created_at)
                 VALUES (:id, :username, :display_name, :email, :password_hash, :provider, :created_at)',
                [
                    'id' => $id,
                    'username' => $username,
                    'display_name' => $displayName,
                    'email' => $username . '@example.test',
                    'password_hash' => $hash,
                    'provider' => 'local',
                    'created_at' => $now,
                ],
            );
        }

        $direct = $this->idGenerator->generate();

        // La cle d'un direct est la paire d'identifiants triee : elle rend
        // l'unicite de la conversation independante de qui l'a creee.
        $pair = [$alice, $bob];
        sort($pair);

        $this->connection->executeStatement(
            'INSERT INTO conversations (id, type, created_by, direct_key, created_at)
             VALUES (:id, :type, :created_by, :direct_key, :created_at)',
            [
                'id' => $direct,
                'type' => 'direct',
                'created_by' => $alice,
                'direct_key' => implode(':', $pair),
                'created_at' => $now,
            ],
        );

        $group = $this->idGenerator->generate();

        $this->connection->executeStatement(
            'INSERT INTO conversations (id, type, title, created_by, created_at)
             VALUES (:id, :type, :title, :created_by, :created_at)',
            [
                'id' => $group,
                'type' => 'group',
                'title' => 'Equipe projet',
                'created_by' => $alice,
                'created_at' => $now,
            ],
        );

        $memberships = [
            [$direct, $alice, 'admin'],
            [$direct, $bob, 'member'],
            [$group, $alice, 'admin'],
            [$group, $bob, 'member'],
            [$group, $carol, 'member'],
        ];

        foreach ($memberships as [$conversationId, $userId, $role]) {
            $this->connection->executeStatement(
                'INSERT INTO conversation_members (conversation_id, user_id, role, joined_at)
                 VALUES (:conversation_id, :user_id, :role, :joined_at)',
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'role' => $role,
                    'joined_at' => $now,
                ],
            );
        }

        $io->success('Fixtures chargees : alice, bob, carol (mot de passe : password).');

        return Command::SUCCESS;
    }
}
