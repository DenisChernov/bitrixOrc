<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(
    name: 'env:create',
    description: 'Создает новое Docker окружение для Bitrix.',
)]
class CreateEnvCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $io->ask('Введите название каталога для проекта (например, my-bitrix-site)');

        if (!$name) {
            $io->error('Название проекта обязательно.');
            return Command::FAILURE;
        }

        // Очистка имени от потенциально опасных символов
        $name = preg_replace('/[^a-z0-9\-_]/i', '', $name);
        $targetDir = getcwd() . DIRECTORY_SEPARATOR . $name;

        if (is_dir($targetDir)) {
            $io->error(sprintf('Каталог "%s" уже существует.', $name));
            return Command::FAILURE;
        }

        $io->section(sprintf('Создание проекта: %s', $name));

        // 1. Клонирование репозитория
        $io->text('Клонирование репозитория bitrix-tools/env-docker...');
        $process = $this->runProcess(['git', 'clone', 'https://github.com/bitrix-tools/env-docker.git', $targetDir]);

        if (!$process->isSuccessful()) {
            $io->error('Ошибка при клонировании репозитория.');
            $io->text($process->getErrorOutput());
            return Command::FAILURE;
        }

        // 2. Очистка git инфраструктуры
        $this->runProcess(['rm', '-rf', $targetDir . DIRECTORY_SEPARATOR . '.git']);

        // 3. Работа с исходным кодом проекта (Git или restore.php)
        $repoUrl = $io->ask('Введите URL Git-репозитория проекта (оставьте пустым для использования restore.php)');
        $wwwDir = $targetDir . DIRECTORY_SEPARATOR . 'www';

        if ($repoUrl) {
            $io->text(sprintf('Клонирование репозитория проекта в www: %s...', $repoUrl));
            $process = $this->runProcess(['git', 'clone', $repoUrl, $wwwDir], null, 600);

            if (!$process->isSuccessful()) {
                $io->error('Ошибка при клонировании репозитория проекта.');
                $io->text($process->getErrorOutput());
            } else {
                $io->text('Репозиторий проекта успешно склонирован.');
            }
        }

        // В любом случае подготавливаем папку www и скачиваем restore.php
        if (!is_dir($wwwDir)) {
            mkdir($wwwDir, 0775, true);
        }

        $io->text('Скачивание restore.php в папку www...');
        try {
            $response = $this->httpClient->request('GET', 'https://www.1c-bitrix.ru/download/scripts/restore.php');
            file_put_contents($wwwDir . DIRECTORY_SEPARATOR . 'restore.php', $response->getContent());
            $io->text('restore.php успешно скачан.');
        } catch (\Exception $e) {
            $io->warning('Не удалось скачать restore.php: ' . $e->getMessage());
        }

        // 4. Настройка .env
        $envFile = $targetDir . DIRECTORY_SEPARATOR . '.env';
        $envExample = $targetDir . DIRECTORY_SEPARATOR . '.env.example';

        if (!file_exists($envFile) && file_exists($envExample)) {
            copy($envExample, $envFile);
        }

        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            // Устанавливаем уникальное имя проекта для COMPOSE_PROJECT_NAME
            if (str_contains($envContent, 'COMPOSE_PROJECT_NAME=')) {
                $envContent = preg_replace('/^COMPOSE_PROJECT_NAME=.*$/m', 'COMPOSE_PROJECT_NAME=' . $name, $envContent);
            } else {
                $envContent .= "\nCOMPOSE_PROJECT_NAME=" . $name . "\n";
            }
            file_put_contents($envFile, $envContent);
        }

        // 5. Информационные сообщения
        $io->success('Окружение успешно подготовлено!');
        $io->note([
            'Инфраструктура Docker (MySQL, Nginx, PHP-FPM) настроена.',
            'Убедитесь, что в будущем файле .env.local (или dbconn.php) внутри кода Bitrix',
            'указаны верные данные для подключения к MySQL.',
            'Настройки MySQL находятся в файле: ' . realpath($envFile),
        ]);

        // 7. Запрос на запуск
        $question = new ConfirmationQuestion('Запустить docker-compose up -d сейчас? (y/n) ', false);

        if ($io->askQuestion($question)) {
            $io->text('Запуск контейнеров...');
            // Пытаемся найти docker-compose или docker compose
            $cmd = ['docker-compose', 'up', '-d'];
            $process = $this->runProcess($cmd, $targetDir, 600, function ($type, $buffer) use ($output) {
                $output->write($buffer);
            });

            if ($process->isSuccessful()) {
                $io->success('Контейнеры запущены!');
            } else {
                $io->error('Ошибка при запуске контейнеров. Возможно, docker-compose не установлен или заняты порты.');
            }
        } else {
            $io->info('Проект готов. Для запуска выполните: cd ' . $name . ' && docker-compose up -d');
        }

        return Command::SUCCESS;
    }

    protected function runProcess(array $command, string $cwd = null, int $timeout = 600, ?callable $callback = null): Process
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);
        $process->run($callback);
        return $process;
    }
}
