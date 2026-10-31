<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Console;

use FriendsOfRedaxo\AiPlatform\AiPlatformAddon;
use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Console\AbstractCommand;
use Redaxo\Core\Database\Sql;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * Schickt die kleinste Anfrage, die zeigt, dass ein Profil funktioniert -- derselbe Test wie der Knopf im Backend.
 *
 * Usage:
 *   bin/console ai:test 3
 *   bin/console ai:test "Embeddings lokal"
 *   bin/console ai:test --type=embedding        (das Standardprofil des Typs)
 */
#[AsCommand(name: 'ai:test', description: 'Testet die Verbindung eines KI-Profils')]
final class TestCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'ID oder Name des Profils')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Statt eines Profils das Standardprofil dieses Typs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $service = Service::getInstance();

        $argument = (string) $input->getArgument('profile');
        $type = (string) $input->getOption('type');
        if ('' !== $argument) {
            $rows = Sql::factory()->getArray('SELECT id FROM ' . AiPlatformAddon::TABLE_PROFILE . ' WHERE id = ? OR name = ?', [(int) $argument, $argument]);
            $profileId = (int) ($rows[0]['id'] ?? 0);
        } elseif ('' !== $type) {
            $profileId = (int) $this->addon->getConfig('default_' . $type . '_profile', 0);
        } else {
            $io->error('Bitte ein Profil (ID oder Name) oder --type angeben.');

            return Command::INVALID;
        }

        $profile = $service->getProfile($profileId);
        if (null === $profile) {
            $io->error('Kein aktives Profil gefunden.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Profil <info>%s</info> (%s, %s, %s)', $profile['name'], $profile['type'], $profile['provider'], $profile['model']));

        try {
            $io->success($service->testConnection($profileId));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
