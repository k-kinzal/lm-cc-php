<?php

declare(strict_types=1);

namespace Lmcc\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Generate a default lm-cc.yaml configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetPath = getcwd() . '/lm-cc.yaml';
        $distPath = $this->findDistConfig();

        if ($distPath === null) {
            $output->writeln('<error>Cannot find reference configuration file (config/lm-cc.dist.yaml).</error>');
            return Command::FAILURE;
        }

        if (file_exists($targetPath)) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'lm-cc.yaml already exists. Overwrite? [y/N] ',
                false,
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        $content = file_get_contents($distPath);
        if ($content === false) {
            $output->writeln('<error>Cannot read reference configuration file.</error>');
            return Command::FAILURE;
        }

        file_put_contents($targetPath, $content);
        $output->writeln('<info>Created lm-cc.yaml in current directory.</info>');

        return Command::SUCCESS;
    }

    private function findDistConfig(): ?string
    {
        $candidates = [
            __DIR__ . '/../../config/lm-cc.dist.yaml',
            __DIR__ . '/../../../../config/lm-cc.dist.yaml',
        ];

        foreach ($candidates as $path) {
            $resolved = realpath($path);
            if ($resolved !== false && file_exists($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
