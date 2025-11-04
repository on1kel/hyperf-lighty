<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use Closure;
use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\DbConnection\Db;

use function mb_stripos;
use function mb_strlen;
use function mb_substr;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Option\BulkDestroyActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Payload\BulkDestroyActionRequestPayloadDTO;
use On1kel\HyperfLighty\Models\Attributes\Relationships\RelationshipTypeEnum;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\BulkDestroy\BulkDestroyCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\BulkDestroy\BulkDestroyEnded;
use On1kel\HyperfLighty\Services\CRUD\Events\BulkDestroy\BulkDestroyError;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

class BulkDestroyAction extends BaseCRUDAction
{
    /**
     * Массовое удаление.
     *
     * @param BulkDestroyActionOptionsDTO          $options
     * @param BulkDestroyActionRequestPayloadDTO   $data
     * @param Closure|null                         $closure
     * @return bool
     * @throws Throwable
     * @throws UnknownProperties
     * @throws ReflectionException
     */
    public function handle(
        BulkDestroyActionOptionsDTO $options,
        BulkDestroyActionRequestPayloadDTO $data,
        ?Closure $closure = null
    ): bool {
        // событие: начало
        $this->dispatchCrud(
            BulkDestroyCalled::class,
            $this->currentModel::class,
            $data
        );

        $this->beginTransaction();

        try {
            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeDeleting,
                    'data' => $data,
                ]));
            }

            if ($options->force) {
                // Жёсткое удаление: подчистим связи BelongsToMany и FK у HasMany
                foreach ($this->currentModel->getLocalRelations() as $relation_name => $relation_props) {
                    if ($relation_props->type === RelationshipTypeEnum::BelongsToMany) {
                        /** @var mixed $rel */
                        $rel = $this->currentModel->{$relation_name}();
                        if ($rel instanceof BelongsToMany) {
                            Db::table($rel->getTable())
                                ->whereIn($rel->getForeignPivotKeyName(), $data->ids)
                                ->delete();
                        }
                    }

                    if ($relation_props->type === RelationshipTypeEnum::HasMany) {
                        /** @var mixed $rel */
                        $rel = $this->currentModel->{$relation_name}();
                        if ($rel instanceof HasMany) {
                            // Обычно "table.column"
                            $compareKey = $rel->getExistenceCompareKey();
                            $dotPos = mb_stripos($compareKey, '.');
                            if ($dotPos !== false) {
                                $table = mb_substr($compareKey, 0, $dotPos);
                                $foreignKey = mb_substr($compareKey, $dotPos + 1, mb_strlen($compareKey));
                                if ($table !== '' && $foreignKey !== '') {
                                    Db::table($table)
                                        ->whereIn($foreignKey, $data->ids)
                                        ->update([$foreignKey => null]);
                                }
                            }
                        }
                    }
                }

                // Удаление «в лоб» (минуя soft delete)
                /** @var EloquentBuilder $builder */
                $builder = $this->currentModel::query();
                $builder->whereIn($this->currentModel->getKeyName(), $data->ids)->forceDelete();
            } else {
                // Мягкое массовое удаление
                $this->currentModel::destroy($data->ids);
            }

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterDeleting,
                    'data' => $data,
                ]));
            }

            $this->commit();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterCommit,
                    'data' => $data,
                ]));
            }

            // событие: успешно
            $this->dispatchCrud(
                BulkDestroyEnded::class,
                $this->currentModel::class,
                $data
            );

            return true;
        } catch (Throwable $exception) {
            // событие: ошибка
            $this->dispatchCrud(
                BulkDestroyError::class,
                $this->currentModel::class,
                $data,
                $exception
            );

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeRollback,
                    'data' => $data,
                    'exception' => $exception,
                ]));
            }

            $this->rollback();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterRollback,
                    'data' => $data,
                    'exception' => $exception,
                ]));
            }

            throw $exception;
        }
    }
}
