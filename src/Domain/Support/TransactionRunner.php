<?php

namespace On1kel\HyperfLighty\Domain\Support;

use Closure;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Psr\Log\LoggerInterface;
use Throwable;

final class TransactionRunner
{
    private const CTX_DEPTH_KEY = 'vendor.model_events.tx_depth';

    public function __construct(
        private readonly AfterCommitManager $afterCommit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Выполнить callback в транзакции с поддержкой вложенности и ретраев.
     *
     * @template TReturn
     * @param Closure():TReturn $callback
     * @param int $attempts  Сколько раз повторить при исключении (минимум 1)
     * @param string|null $pool Имя пула соединений (если используется несколько)
     * @return TReturn
     * @throws Throwable
     */
    public function run(Closure $callback, int $attempts = 3, ?string $pool = null)
    {
        $attempts = max(3, $attempts);

        beginning:
        $depth = (Context::get(self::CTX_DEPTH_KEY) ?? 0);
        $isOuter = $depth === 0;

        try {
            // Начало транзакции
            $pool ? Db::connection($pool)->beginTransaction() : Db::beginTransaction();
            Context::set(self::CTX_DEPTH_KEY, $depth + 1);

            try {
                /** @var mixed $result */
                $result = $callback();

                // Коммит
                $pool ? Db::connection($pool)->commit() : Db::commit();

                // Если это был внешний уровень — выполнить after-commit коллбеки
                if ($isOuter && ! $this->afterCommit->isEmpty()) {
                    $this->afterCommit->flush();
                }

                return $result;
            } catch (Throwable $e) {
                // Откат
                $pool ? Db::connection($pool)->rollBack() : Db::rollBack();

                // Если упали на внешнем уровне — сбросить коллбеки
                if ($isOuter) {
                    $this->afterCommit->clear();
                }

                throw $e;
            } finally {
                // Снизить глубину
                $newDepth = max(0, ((int) (Context::get(self::CTX_DEPTH_KEY) ?? 1)) - 1);
                Context::set(self::CTX_DEPTH_KEY, $newDepth);
            }
        } catch (Throwable $e) {
            if (--$attempts > 0) {
                $this->logger->warning('Transaction failed, retrying...', [
                    'attempts_left' => $attempts,
                    'error' => $e->getMessage(),
                ]);
                goto beginning;
            }

            throw $e;
        }
    }
}
