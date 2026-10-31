<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Console;

use FriendsOfRedaxo\AiPlatform\Service;
use Redaxo\Core\Console\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Schickt einen Prompt an ein Textprofil und gibt die Antwort aus -- über denselben Weg wie
 * `Service::generateText()`, also mit System-Prompt und Optionen des Profils.
 *
 * Usage:
 *   bin/console ai:ask "Fasse REDAXO in einem Satz zusammen."
 *   bin/console ai:ask "…" --profile=4 --system="Antworte auf Englisch."
 */
#[AsCommand(name: 'ai:ask', description: 'Fragt ein Textprofil (Vorgabe: das Standardprofil für Text)')]
final class AskCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'Der Prompt')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'ID des Profils')
            ->addOption('system', 's', InputOption::VALUE_REQUIRED, 'System-Prompt statt dem des Profils');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile = $input->getOption('profile');
        $system = $input->getOption('system');

        try {
            $answer = Service::getInstance()->generateText(
                (string) $input->getArgument('prompt'),
                null === $system ? null : (string) $system,
                null === $profile ? null : (int) $profile,
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln($answer);

        return Command::SUCCESS;
    }
}
