<?php

declare(strict_types=1);

namespace Stead\Bootstrap;

use Monolog\Logger;
use Stead\Config\Configuration;
use Stead\Config\Validator;
use Stead\Database\Connection;
use Stead\Exception\ExceptionHandler;
use Stead\Exception\SafeException;
use Stead\Logging\LoggerFactory;
use Stead\Support\ProjectRoot;

final class Application
{
    private Configuration $config;
    private Logger $logger;
    private ?Connection $connection = null;
    private ExceptionHandler $exceptionHandler;

    public static function bootstrap(?string $projectRoot = null, ?string $environment = null): self
    {
        $projectRoot ??= ProjectRoot::resolve();
        $configPath = $projectRoot . '/.env';
        if (is_file($configPath)) {
            \Stead\Config\Dotenv::load($configPath);
        }
        $environment ??= $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if (!in_array($environment, ['production', 'development', 'test'], true)) {
            throw new SafeException('Unsupported application environment.');
        }

        $config = Configuration::fromProjectRoot($projectRoot, $environment);
        Validator::validate($config);
        return new self($config);
    }

    public function __construct(Configuration $config)
    {
        $this->config = $config;
        $this->logger = LoggerFactory::create($config);
        $debug = $config->isDevelopment() || $config->getBool('app.debug', false);
        $this->exceptionHandler = new ExceptionHandler($this->logger, $config, $debug);
    }

    public function config(): Configuration
    {
        return $this->config;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function exceptionHandler(): ExceptionHandler
    {
        return $this->exceptionHandler;
    }

    public function database(): Connection
    {
        if ($this->connection === null) {
            $this->connection = new Connection($this->config);
        }
        return $this->connection;
    }
}
