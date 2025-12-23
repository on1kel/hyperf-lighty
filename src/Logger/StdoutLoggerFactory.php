<?php
declare(strict_types=1);

namespace On1kel\HyperfLighty\Logger;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Logger\LoggerFactory;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;


final class StdoutLoggerFactory
{
    public function __invoke(ContainerInterface $container): StdoutLoggerInterface
    {

        if ($container->has(LoggerFactory::class)) {
            $factory = $container->get(LoggerFactory::class);

            $logger = $factory->get('sys', 'default');

            return $this->asStdoutLogger($logger);
        }


        return $this->asStdoutLogger($this->buildFallbackMonolog());
    }

    private function asStdoutLogger(LoggerInterface $logger): StdoutLoggerInterface
    {

        if ($logger instanceof StdoutLoggerInterface) {
            return $logger;
        }

        return new class($logger) implements StdoutLoggerInterface {
            public function __construct(private LoggerInterface $inner) {}

            public function emergency($message, array $context = []): void { $this->inner->emergency($message, $context); }
            public function alert($message, array $context = []): void     { $this->inner->alert($message, $context); }
            public function critical($message, array $context = []): void  { $this->inner->critical($message, $context); }
            public function error($message, array $context = []): void     { $this->inner->error($message, $context); }
            public function warning($message, array $context = []): void   { $this->inner->warning($message, $context); }
            public function notice($message, array $context = []): void    { $this->inner->notice($message, $context); }
            public function info($message, array $context = []): void      { $this->inner->info($message, $context); }
            public function debug($message, array $context = []): void     { $this->inner->debug($message, $context); }

            public function log($level, $message, array $context = []): void
            {
                $this->inner->log($level, $message, $context);
            }
        };
    }

    private function buildFallbackMonolog(): LoggerInterface
    {
        $logger = new Logger('sys');

        $stdout = new StreamHandler('php://stdout', Level::Info);
        $stdout->setFormatter(new JsonFormatter());

        $stderr = new StreamHandler('php://stderr', Level::Warning);
        $stderr->setFormatter(new JsonFormatter());

        $logger->pushHandler($stdout);
        $logger->pushHandler($stderr);

        return $logger;
    }
}
