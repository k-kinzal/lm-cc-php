<?php

declare(strict_types=1);

namespace Lmcc\Tests\Command;

use Lmcc\Command\InitCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lmcc_test_' . uniqid();
        mkdir($this->tmpDir);
        chdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/lm-cc.yaml';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application('lm-cc', '0.1.0');
        $application->add(new InitCommand());

        return new CommandTester($application->find('init'));
    }

    #[Test]
    public function creates_config_file(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Created lm-cc.yaml', $tester->getDisplay());
        self::assertFileExists($this->tmpDir . '/lm-cc.yaml');

        $content = file_get_contents($this->tmpDir . '/lm-cc.yaml');
        self::assertStringContainsString('backend:', $content);
        self::assertStringContainsString('alpha:', $content);
    }

    #[Test]
    public function existing_file_abort_preserves_original(): void
    {
        file_put_contents($this->tmpDir . '/lm-cc.yaml', 'original content');

        $tester = $this->createCommandTester();
        $tester->setInputs(['no']);
        $tester->execute([]);

        self::assertSame('original content', file_get_contents($this->tmpDir . '/lm-cc.yaml'));
    }
}
