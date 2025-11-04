<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use Closure;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\DbConnection\Db;

use function mb_stripos;
use function mb_strlen;
use function mb_substr;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\DestroyAction\Option\DestroyActionOptionsDTO;
use On1kel\HyperfLighty\Models\Attributes\Relationships\RelationshipTypeEnum;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\Destroy\DestroyCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Destroy\DestroyEnded;
use On1kel\HyperfLighty\Services\CRUD\Events\Destroy\DestroyError;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

class DestroyAction extends BaseCRUDAction
{
    /**
     * Удаление сущности.
     *
     * @param DestroyActionOptionsDTO $options
     * @param mixed                   $key
     * @param Closure|null            $closure
     *
     * @return bool
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    public function handle(DestroyActionOptionsDTO $options, mixed $key, ?Closure $closure = null): bool
    {
        $current_model = $this->getModelByKey($options, $key);

        // событие: начали удаление
        $this->dispatchCrud(
            DestroyCalled::class,
            $this->currentModel::class,
            $current_model
        );

        if ($closure) {
            $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::AfterFilling,
                'data' => $current_model,
            ]));
        }

        $this->beginTransaction();

        try {
            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeDeleting,
                    'data' => $current_model,
                ]));
            }

            if ($options->deleted->enable && $options->force) {
                // Жёсткое удаление: чистим связующие таблицы BelongsToMany и
                // обнуляем FK у HasMany (если так задумано вашей моделью).
                foreach ($current_model->getLocalRelations() as $relation_name => $relation_props) {
                    if ($relation_props->type === RelationshipTypeEnum::BelongsToMany) {
                        /** @var mixed $rel */
                        $rel = $current_model->{$relation_name}();
                        if ($rel instanceof BelongsToMany) {
                            Db::table($rel->getTable())
                                ->whereIn($rel->getForeignPivotKeyName(), [$key])
                                ->delete();
                        }
                    }

                    if ($relation_props->type === RelationshipTypeEnum::HasMany) {
                        /** @var mixed $rel */
                        $rel = $current_model->{$relation_name}();
                        if ($rel instanceof HasMany) {
                            // getExistenceCompareKey() обычно возвращает "table.column"
                            $compareKey = $rel->getExistenceCompareKey();
                            $dotPos = mb_stripos($compareKey, '.');
                            if ($dotPos !== false) {
                                $table = mb_substr($compareKey, 0, $dotPos);
                                $foreignKey = mb_substr($compareKey, $dotPos + 1, mb_strlen($compareKey));
                                if ($table !== '' && $foreignKey !== '') {
                                    Db::table($table)
                                        ->whereIn($foreignKey, [$key])
                                        ->update([$foreignKey => null]);
                                }
                            }
                        }
                    }
                }

                $current_model->forceDelete();
            } else {
                // Мягкое удаление (если включено на модели)
                $current_model->delete();
            }

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterDeleting,
                    'data' => $current_model,
                ]));
            }

            $this->commit();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterCommit,
                    'data' => $current_model,
                ]));
            }

            // событие: успешно удалено
            $this->dispatchCrud(
                DestroyEnded::class,
                $this->currentModel::class,
                $current_model
            );

            return true;
        } catch (Throwable $exception) {
            // событие: ошибка удаления
            $this->dispatchCrud(
                DestroyError::class,
                $this->currentModel::class,
                $current_model,
                $exception
            );

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeRollback,
                    'data' => $current_model,
                    'exception' => $exception,
                ]));
            }

            $this->rollback();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterRollback,
                    'data' => $current_model,
                    'exception' => $exception,
                ]));
            }

            throw $exception;
        }
    }
}
