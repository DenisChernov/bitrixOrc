<?php

namespace App\Tests\Command;

use App\Command\CreateEnvCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class CreateEnvCommandTest extends TestCase
{
    private string $tempDir;
    private Filesystem $fs;

    public function publicCreateMock(string $originalClassName): \PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock($originalClassName);
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/bitrix_orc_test_' . uniqid();
        $this->fs->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->fs->exists($this->tempDir)) {
            $this->fs->remove($this->tempDir);
        }
    }

    public function testExecuteDownloadsRestorePhpEvenWithRepo()
    {
        $mockResponse = new MockResponse('<?php echo "restore content";');
        $httpClient = new MockHttpClient($mockResponse);

        $testCase = $this;
        // Используем анонимный класс для подмены runProcess, чтобы не зависеть от git и сети
        $command = new class($httpClient, $testCase) extends CreateEnvCommand {
            public array $commandsRun = [];

            public function __construct($httpClient, private readonly CreateEnvCommandTest $test)
            {
                parent::__construct($httpClient);
            }

            protected function runProcess(array $command, string $cwd = null, int $timeout = 600, ?callable $callback = null): Process
            {
                $this->commandsRun[] = $command;
                
                // Имитируем поведение git clone (создание файлов инфраструктуры)
                if ($command[0] === 'git' && $command[1] === 'clone' && str_contains($command[2], 'env-docker')) {
                    $target = $command[3];
                    if (!is_dir($target)) {
                        mkdir($target, 0775, true);
                    }
                    file_put_contents($target . '/.env.example', 'COMPOSE_PROJECT_NAME=example');
                }

                $process = $this->test->publicCreateMock(Process::class);
                $process->method('isSuccessful')->willReturn(true);
                return $process;
            }
        };

        $commandTester = new CommandTester($command);

        $currentDir = getcwd();
        chdir($this->tempDir);

        try {
            $commandTester->setInputs([
                'test-project',          // Название проекта
                'https://github.com/user/repo.git', // URL репозитория
                'n',                     // Запустить docker-compose? (нет)
            ]);

            $commandTester->execute([]);

            $output = $commandTester->getDisplay();
            
            // Проверяем, что в выводе есть сообщения
            $this->assertStringContainsString('Создание проекта: test-project', $output);
            $this->assertStringContainsString('Клонирование репозитория проекта в www', $output);
            $this->assertStringContainsString('restore.php успешно скачан', $output);

            // Проверяем файловую структуру
            $projectDir = $this->tempDir . DIRECTORY_SEPARATOR . 'test-project';
            $this->assertDirectoryExists($projectDir);
            $this->assertDirectoryExists($projectDir . DIRECTORY_SEPARATOR . 'www');
            
            // ГЛАВНАЯ ПРОВЕРКА: restore.php должен быть скачан даже если был указан репозиторий
            $this->assertFileExists($projectDir . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'restore.php');
            $this->assertEquals('<?php echo "restore content";', file_get_contents($projectDir . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'restore.php'));

            // Проверяем настройку .env
            $this->assertFileExists($projectDir . DIRECTORY_SEPARATOR . '.env');
            $this->assertStringContainsString('COMPOSE_PROJECT_NAME=test-project', file_get_contents($projectDir . DIRECTORY_SEPARATOR . '.env'));

        } finally {
            chdir($currentDir);
        }
    }

    public function testExecuteWithoutRepo()
    {
        $mockResponse = new MockResponse('<?php echo "restore content";');
        $httpClient = new MockHttpClient($mockResponse);

        $testCase = $this;
        $command = new class($httpClient, $testCase) extends CreateEnvCommand {
            public function __construct($httpClient, private readonly CreateEnvCommandTest $test)
            {
                parent::__construct($httpClient);
            }

            protected function runProcess(array $command, string $cwd = null, int $timeout = 600, ?callable $callback = null): Process
            {
                if ($command[0] === 'git' && $command[1] === 'clone' && str_contains($command[2], 'env-docker')) {
                    $target = $command[3];
                    if (!is_dir($target)) {
                        mkdir($target, 0775, true);
                    }
                    file_put_contents($target . '/.env.example', 'COMPOSE_PROJECT_NAME=example');
                }

                $process = $this->test->publicCreateMock(Process::class);
                $process->method('isSuccessful')->willReturn(true);
                return $process;
            }
        };

        $commandTester = new CommandTester($command);

        $currentDir = getcwd();
        chdir($this->tempDir);

        try {
            $commandTester->setInputs([
                'no-repo-project',
                '',   // Без репозитория
                'n',
            ]);

            $commandTester->execute([]);

            $projectDir = $this->tempDir . DIRECTORY_SEPARATOR . 'no-repo-project';
            $this->assertFileExists($projectDir . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'restore.php');

        } finally {
            chdir($currentDir);
        }
    }
}
