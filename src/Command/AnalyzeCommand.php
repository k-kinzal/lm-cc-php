<?php

declare(strict_types=1);

namespace Lmcc\Command;

use Lmcc\Config;
use Lmcc\LmccAnalyzer;
use Lmcc\LmccResult;
use Lmcc\Llm\LlmClientInterface;
use Lmcc\Llm\OllamaClient;
use Lmcc\Llm\OpenAiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\HttpClient;

final class AnalyzeCommand extends Command
{
    private ?LlmClientInterface $llmClient;

    public function __construct(?LlmClientInterface $llmClient = null)
    {
        $this->llmClient = $llmClient;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('analyze')
            ->setDescription('Compute LM-CC scores for PHP files')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories to analyze', ['.'])
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to YAML config file')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: text or json')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'LLM backend: ollama or openai')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'LLM model name')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for OpenAI backend')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'LLM API base URL')
            ->addOption('alpha', null, InputOption::VALUE_REQUIRED, 'Weighting parameter (0.0-1.0)')
            ->addOption('tau', null, InputOption::VALUE_REQUIRED, 'Fixed entropy threshold override')
            ->addOption('percentile', null, InputOption::VALUE_REQUIRED, 'Percentile for dynamic tau (0-100)')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Fail (exit 1) if any file exceeds this score')
            ->addOption('exclude', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Glob patterns to exclude');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $cliOverrides = $this->buildCliOverrides($input);
            $config = Config::load($input->getOption('config'), $cliOverrides);

            if (!in_array($config->format, ['text', 'json'], true)) {
                throw new \InvalidArgumentException("Invalid format: '{$config->format}'. Must be 'text' or 'json'.");
            }

            if (!in_array($config->backend, ['ollama', 'openai'], true)) {
                throw new \InvalidArgumentException("Invalid backend: '{$config->backend}'. Must be 'ollama' or 'openai'.");
            }

            $llmClient = $this->llmClient ?? $this->createLlmClient($config);
            $analyzer = new LmccAnalyzer(
                llmClient: $llmClient,
                alpha: $config->alpha,
                tauOverride: $config->tauOverride,
                percentile: $config->percentile,
            );

            $paths = $input->getArgument('paths');
            $files = $this->discoverFiles($paths, $config->exclude);

            if ($files === []) {
                $output->writeln('<comment>No PHP files found to analyze.</comment>');
                return Command::SUCCESS;
            }

            $results = [];
            $errors = [];
            $total = count($files);

            // Use stderr for progress so stdout stays clean for piping/JSON
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : null;
            $showProgress = $stderr !== null && $stderr->isDecorated();

            foreach ($files as $i => $file) {
                $num = $i + 1;
                if ($showProgress) {
                    $stderr->write(sprintf("\r\033[K  [%d/%d] %s", $num, $total, $file));
                }

                try {
                    $results[] = $analyzer->analyzeFile($file);
                } catch (\Throwable $e) {
                    $errors[] = ['file' => $file, 'error' => $e->getMessage()];
                }
            }

            if ($showProgress) {
                $stderr->write("\r\033[K");
            }

            if ($results === [] && $errors !== []) {
                foreach ($errors as $err) {
                    $output->writeln(sprintf('<error>Error: %s: %s</error>', $err['file'], $err['error']));
                }
                return 2;
            }

            if ($config->format === 'json') {
                $this->outputJson($output, $results, $config, $errors);
            } else {
                $this->outputText($output, $results, $config, $errors);
            }

            if ($config->threshold !== null) {
                $failedFiles = array_filter($results, fn(LmccResult $r) => $r->exceedsThreshold($config->threshold));
                if ($failedFiles !== []) {
                    return 1;
                }
            }

            return $errors !== [] ? 2 : Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return 2;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCliOverrides(InputInterface $input): array
    {
        $overrides = [];

        $map = [
            'format' => 'format',
            'backend' => 'backend',
            'model' => 'model',
            'api-key' => 'api_key',
            'base-url' => 'base_url',
            'alpha' => 'alpha',
            'tau' => 'tau',
            'percentile' => 'percentile',
            'threshold' => 'threshold',
        ];

        foreach ($map as $option => $key) {
            $val = $input->getOption($option);
            if ($val !== null) {
                $overrides[$key] = $val;
            }
        }

        $exclude = $input->getOption('exclude');
        if ($exclude !== []) {
            $overrides['exclude'] = $exclude;
        }

        return $overrides;
    }

    private function createLlmClient(Config $config): LlmClientInterface
    {
        $httpClient = HttpClient::create();

        return match ($config->backend) {
            'openai' => new OpenAiClient($httpClient, $config->baseUrl, $config->apiKey, $config->model),
            default => new OllamaClient($httpClient, $config->baseUrl, $config->model),
        };
    }

    /**
     * @param string[] $paths
     * @param string[] $exclude
     * @return string[]
     */
    private function discoverFiles(array $paths, array $exclude): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                if (str_ends_with($path, '.php')) {
                    $files[] = $path;
                }
                continue;
            }

            if (!is_dir($path)) {
                throw new \RuntimeException("Path not found: {$path}");
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($path);

            foreach ($exclude as $pattern) {
                $finder->notPath($pattern);
            }

            foreach ($finder as $file) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * @param LmccResult[] $results
     * @param array<int, array{file: string, error: string}> $errors
     */
    private function outputText(OutputInterface $output, array $results, Config $config, array $errors = []): void
    {
        $output->writeln('');
        $output->writeln('LM-CC Analysis Results');
        $output->writeln('======================');
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['File', 'LM-CC', 'Tokens', 'Units', 'MaxDepth']);

        foreach ($results as $result) {
            $table->addRow([
                $result->filePath,
                number_format($result->score, 2),
                $result->tokenCount,
                $result->unitCount,
                $result->maxCompLevel,
            ]);
        }

        $table->addRow(new TableSeparator());

        $totalScore = array_sum(array_map(fn(LmccResult $r) => $r->score, $results));
        $totalTokens = array_sum(array_map(fn(LmccResult $r) => $r->tokenCount, $results));
        $totalUnits = array_sum(array_map(fn(LmccResult $r) => $r->unitCount, $results));
        $maxDepthOverall = max(array_map(fn(LmccResult $r) => $r->maxCompLevel, $results));
        $sumMaxDepth = array_sum(array_map(fn(LmccResult $r) => $r->maxCompLevel, $results));
        $count = count($results);

        $table->addRow([
            'TOTAL',
            number_format($totalScore, 2),
            $totalTokens,
            $totalUnits,
            $maxDepthOverall,
        ]);
        $table->addRow([
            'AVERAGE',
            number_format($totalScore / $count, 2),
            (int) round($totalTokens / $count),
            (int) round($totalUnits / $count),
            number_format($sumMaxDepth / $count, 1),
        ]);

        $table->render();
        $output->writeln('');

        if ($config->threshold !== null) {
            $output->writeln(sprintf('Threshold: %.2f', $config->threshold));
            $failedFiles = array_filter($results, fn(LmccResult $r) => $r->exceedsThreshold($config->threshold));
            if ($failedFiles !== []) {
                $output->writeln(sprintf('<error>FAIL: %d file(s) exceed threshold:</error>', count($failedFiles)));
                foreach ($failedFiles as $r) {
                    $output->writeln(sprintf('  - %s: %.2f', $r->filePath, $r->score));
                }
            } else {
                $output->writeln('<info>OK: All files within threshold.</info>');
            }
            $output->writeln('');
        }

        if ($errors !== []) {
            $output->writeln(sprintf('<error>%d file(s) failed:</error>', count($errors)));
            foreach ($errors as $err) {
                $output->writeln(sprintf('  - %s: %s', $err['file'], $err['error']));
            }
            $output->writeln('');
        }
    }

    /**
     * @param LmccResult[] $results
     * @param array<int, array{file: string, error: string}> $errors
     */
    private function outputJson(OutputInterface $output, array $results, Config $config, array $errors = []): void
    {
        $totalScore = array_sum(array_map(fn(LmccResult $r) => $r->score, $results));
        $maxScore = max(array_map(fn(LmccResult $r) => $r->score, $results));
        $count = count($results);

        $failedFiles = $config->threshold !== null
            ? count(array_filter($results, fn(LmccResult $r) => $r->exceedsThreshold($config->threshold)))
            : 0;

        $passed = $config->threshold === null || $failedFiles === 0;

        $filesData = [];
        foreach ($results as $result) {
            $arr = $result->toArray();
            if ($config->threshold !== null) {
                $arr['exceedsThreshold'] = $result->exceedsThreshold($config->threshold);
            }
            $filesData[] = $arr;
        }

        $data = [
            'version' => '0.1.0',
            'config' => [
                'alpha' => $config->alpha,
                'percentile' => $config->percentile,
                'backend' => $config->backend,
                'model' => $config->model,
            ],
            'summary' => [
                'totalScore' => round($totalScore, 2),
                'fileCount' => $count,
                'averageScore' => round($totalScore / $count, 2),
                'maxScore' => round($maxScore, 2),
                'threshold' => $config->threshold,
                'passed' => $passed,
                'failedFiles' => $failedFiles,
            ],
            'files' => $filesData,
        ];

        if ($errors !== []) {
            $data['errors'] = array_map(
                fn(array $e) => ['file' => $e['file'], 'error' => $e['error']],
                $errors,
            );
        }

        $output->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }
}
