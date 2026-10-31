<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Console;

use FriendsOfRedaxo\AiPlatform\AiPlatformAddon;
use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Console\AbstractCommand;
use Redaxo\Core\Database\Sql;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Listet die Profile mit der Umgebungsvariable ihres Schlüssels und ob sie auf dieser Instanz gesetzt ist.
 *
 * Gedacht für Server ohne Backend-Zugang: nach einem Deploy oder einer Datenbankkopie zeigt der Befehl, welche
 * Variablen in `.env.local` noch fehlen. Der Schlüssel selbst wird nie ausgegeben.
 *
 * Usage:
 *   bin/console ai:profiles
 */
#[AsCommand(name: 'ai:profiles', description: 'Listet die KI-Profile und ob ihr Schlüssel in der Umgebung steht')]
final class ProfilesCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = Sql::factory()->getArray('SELECT * FROM ' . AiPlatformAddon::TABLE_PROFILE . ' ORDER BY type, name');

        if ([] === $rows) {
            $io->note('Noch kein Profil angelegt (Backend: AI Platform > Profile).');

            return Command::SUCCESS;
        }

        $defaults = [];
        foreach (array_keys(Service::getTypes()) as $type) {
            $defaults[(int) $this->addon->getConfig('default_' . $type . '_profile', 0)][] = $type;
        }

        $missing = [];
        $table = [];
        foreach ($rows as $row) {
            $needsKey = ProviderRegistry::requiresApiKey($row);
            $env = ProviderRegistry::apiKeyEnv($row);
            $hasKey = ProviderRegistry::hasApiKey($row);
            if ($needsKey && !$hasKey && 1 === (int) $row['status']) {
                $missing[$env] = true;
            }
            $table[] = [
                $row['id'],
                $row['name'],
                $row['type'],
                $row['provider'],
                $row['model'],
                1 === (int) $row['status'] ? 'aktiv' : 'inaktiv',
                implode(', ', $defaults[(int) $row['id']] ?? []),
                $env . ' ' . ($hasKey ? '✔' : ($needsKey ? '✘' : '–')),
            ];
        }

        $io->table(['ID', 'Name', 'Typ', 'Anbieter', 'Modell', 'Status', 'Standard für', 'Schlüssel'], $table);

        if ([] !== $missing) {
            $io->warning('In der Umgebung fehlen: ' . implode(', ', array_keys($missing)) . '. Eintragen in ".env.local".');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
