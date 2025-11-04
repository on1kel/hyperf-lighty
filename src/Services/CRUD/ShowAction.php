<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ShowAction\Option\ShowActionOptionsDTO;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\Events\Show\ShowCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Show\ShowEnded;
use Throwable;

class ShowAction extends BaseCRUDAction
{
    /**
     * Получение одной модели по значению её PrimaryKey.
     *
     * @param ShowActionOptionsDTO $options
     * @param mixed                $key
     * @return Model
     * @throws Throwable
     */
    public function handle(ShowActionOptionsDTO $options, mixed $key): Model
    {
        // Событие: запрос модели начат
        $this->dispatchCrud(
            ShowCalled::class,
            $this->currentModel::class,
            $key
        );

        $result = $this->getModelByKey($options, $key);

        // Событие: запрос модели завершён
        $this->dispatchCrud(
            ShowEnded::class,
            $this->currentModel::class,
            $key
        );

        return $result;
    }
}
