<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use function array_fill;
use function count;

use Hyperf\DbConnection\Db;

use function implode;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Option\SetPositionActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Payload\SetPositionActionRequestPayloadDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\SetPosition\SetPositionCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\SetPosition\SetPositionEnded;
use On1kel\HyperfLighty\Services\CRUD\Events\SetPosition\SetPositionError;

use function sprintf;

use Throwable;

class SetPositionAction extends BaseCRUDAction
{
    /**
     * Массовая установка позиций по упорядоченному списку ID.
     *
     * @throws Throwable
     */
    public function handle(SetPositionActionOptionsDTO $options, SetPositionActionRequestPayloadDTO $data): bool
    {
        // событие: начало операции
        $this->dispatchCrud(
            SetPositionCalled::class,
            $this->currentModel::class,
            $data
        );

        $this->beginTransaction();

        try {
            $table = $this->currentModel->getTable();
            $column = $options->position_column;
            $primaryColumn = $this->currentModel->getKeyName();

            // CASE pk WHEN ? THEN 0 WHEN ? THEN 1 ... END
            $case = sprintf('CASE %s', $primaryColumn);
            foreach ($data->ids as $i => $id) {
                $case .= " WHEN ? THEN {$i}";
            }
            $case .= ' END';

            // плейсхолдеры для IN (?, ?, ...)
            $inPlaceholders = implode(',', array_fill(0, count($data->ids), '?'));

            // итоговый SQL
            $sql = sprintf(
                'UPDATE %s SET %s = %s WHERE %s IN (%s)',
                $table,
                $column,
                $case,
                $primaryColumn,
                $inPlaceholders
            );

            // биндинги: сначала для WHEN ?, затем для IN (?)
            $bindings = [];
            // для WHEN ? (кол-во = count(ids))
            foreach ($data->ids as $id) {
                $bindings[] = $id;
            }
            // для IN (?) (ещё раз столько же)
            foreach ($data->ids as $id) {
                $bindings[] = $id;
            }

            // выполняем
            Db::update($sql, $bindings);

            $this->commit();

            // событие: успешно завершено
            $this->dispatchCrud(
                SetPositionEnded::class,
                $this->currentModel::class,
                $data
            );

            return true;
        } catch (Throwable $exception) {
            // событие: ошибка
            $this->dispatchCrud(
                SetPositionError::class,
                $this->currentModel::class,
                $data,
                $exception
            );

            $this->rollback();

            throw $exception;
        }
    }
}
