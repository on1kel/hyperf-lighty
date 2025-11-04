<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD;

use Carbon\Carbon;

use function class_basename;

use Closure;

use function count;

use JsonException;
use On1kel\HyperfLighty\Http\Controllers\Api\ApiController;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ApiCRUDControllerActionInitDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ApiCRUDControllerMetaDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Option\BulkDestroyActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Payload\BulkDestroyActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\DestroyAction\Option\DestroyActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsExportExportTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsReturnTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Option\SetPositionActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Payload\SetPositionActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ShowAction\Option\ShowActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\StoreAction\Option\StoreActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\UpdateAction\Option\UpdateActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\Exceptions\UndefinedActionClassException;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\Exceptions\UndefinedReturnTypeException;
use On1kel\HyperfLighty\Http\Requests\BaseRequest;
use On1kel\HyperfLighty\Http\Resources\CollectionResource;
use On1kel\HyperfLighty\Http\Resources\JsonResource;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\BaseCRUDAction;
use On1kel\HyperfLighty\Services\CRUD\BulkDestroyAction;
use On1kel\HyperfLighty\Services\CRUD\DestroyAction;
use On1kel\HyperfLighty\Services\CRUD\IndexAction;
use On1kel\HyperfLighty\Services\CRUD\SetPositionAction;
use On1kel\HyperfLighty\Services\CRUD\ShowAction;
use On1kel\HyperfLighty\Services\CRUD\StoreAction;
use On1kel\HyperfLighty\Services\CRUD\UpdateAction;
use On1kel\HyperfLighty\Transaction\WithDBTransactionInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;
use RuntimeException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

/**
 * @method static mixed withTrashed(bool $withTrashed = true)
 * @method static mixed onlyTrashed()
 * @method static mixed withoutTrashed()
 * @method static mixed forceDelete()
 */
abstract class ApiCRUDController extends ApiController implements WithDBTransactionInterface
{
    /**
     * Модель, для которой выполняется действие.
     *
     * @var class-string<Model>|Model
     */
    protected mixed $current_model;

    /**
     * Список разрешенных отношений для загрузки.
     *
     * @var array<string>
     */
    protected array $allowed_relationships = [];

    protected readonly ApiCRUDControllerMetaDTO $controller_meta;
    protected string $single_resource;
    protected string $collection_resource;

    public function __construct(ApiCRUDControllerMetaDTO $controller_meta_dto)
    {
        //        parent::__construct(
        //            container: $this->container,    // <- если базовый Controller получает через DI — оставьте DI-конструктор;
        //            request: $this->request,        // иначе уберите параметры и оставьте parent::__construct() пустым.
        //            response: $this->response,
        //            config: $this->config,
        //            validator: $this->validator
        //        );

        $this->controller_meta = $controller_meta_dto;
        $this->current_model = $controller_meta_dto->model_class;
        $this->setSingleResource($controller_meta_dto->single_resource_class);
        $this->setCollectionResource($controller_meta_dto->collection_resource_class);

        if ($controller_meta_dto->hasAllowedRelationships()) {
            $this->allowed_relationships = $controller_meta_dto->allowed_relationships;
        }
    }

    /**
     * Get collection of models resource
     */
    protected function getCollectionResource(): string
    {
        return $this->collection_resource;
    }

    /**
     * Set collection of models resource.
     */
    protected function setCollectionResource(string $collection_resource): void
    {
        $this->collection_resource = $collection_resource;
    }

    /**
     * Get single model resource.
     */
    protected function getSingleResource(): string
    {
        return $this->single_resource;
    }

    /**
     * Set single model resource.
     */
    protected function setSingleResource(string $resource): void
    {
        $this->single_resource = $resource;
    }

    /**
     * Инициализация опций действия.
     */
    protected function initFunction(ApiCRUDControllerActionInitDTO $action_init_dto): BaseCRUDOptionDTO
    {
        $action_options_dto = $action_init_dto->getActionOptionDTO($this->controller_meta);
        $this->setOptions($action_options_dto->toArray());
        $this->setCurrentAction($action_init_dto->action_name);

        return $action_options_dto;
    }

    /**
     * Получить экземпляр CRUD-действия.
     *
     * @param  class-string  $action_class
     */
    protected function getAction(string $action_class): BaseCRUDAction
    {
        if (! is_a($action_class, BaseCRUDAction::class, true)) {
            throw new UndefinedActionClassException();
        }

        return new $action_class($this->current_model);
    }

    /**
     * INDEX
     *
     * @param BaseRequest $request
     * @param mixed|null $builder Eloquent/Query builder (Hyperf совместимый)
     * @param IndexActionOptionsDTO|array<string,mixed> $options
     * @param Closure|null $closure
     *
     * @return ResponseInterface
     * @throws JsonException
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    protected function indexAction(
        BaseRequest $request,
        mixed $builder = null,
        IndexActionOptionsDTO|array $options = [],
        ?Closure $closure = null
    ): ResponseInterface {
        /** @var IndexActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'index',
            'action_options' => $options,
            'action_option_class' => IndexActionOptionsDTO::class,
        ]));

        $current_request = new IndexActionRequestPayloadDTO($request->all());

        /** @var IndexAction $index_action */
        $index_action = $this->getAction(IndexAction::class);
        $index_action->setAllowedRelationships($this->allowed_relationships);

        $items = $index_action->handle(
            builder: $builder,
            options: $current_options,
            data: $current_request,
            closure: $closure
        );

        switch ($current_options->getReturnTypeByRequestPayload($current_request)) {
            case IndexActionOptionsReturnTypeEnum::Resource:
                $resource = $this->getCollectionResource();

                /** @var CollectionResource $result */
                $result = $current_options->single_resource_class
                    ? new $resource($items, $current_options->single_resource_class)
                    : new $resource($items);

                return $this->respondDto(
                    $this->buildActionResponseDTO(data: $result)
                );

                //            case IndexActionOptionsReturnTypeEnum::Export:
                //                $export_type = $current_request->export->export_type
                //                    ? IndexActionOptionsExportExportTypeEnum::from($current_request->export->export_type)
                //                    : $current_options->export->default_export_type;
                //
                //                $export_columns = $current_request->getExportColumns();
                //                if (! count($export_columns)) {
                //                    throw new RuntimeException('Requires specifying columns for export.');
                //                }
                //
                //                $file_name = $this->resolveExportFileName($request->export['file_name'] ?? null, $export_type);
                //
                //                return $this->exportDownload(
                //                    exporterClass: $current_options->export->exporter_class,
                //                    items: $items,
                //                    columns: $export_columns,
                //                    fileName: $file_name,
                //                    format: $export_type
                //                );

            default:
                throw new UndefinedReturnTypeException();
        }
    }

    protected function resolveExportFileName(?string $requested, IndexActionOptionsExportExportTypeEnum $type): string
    {
        $ext = strtolower($type === IndexActionOptionsExportExportTypeEnum::CSV ? 'csv' : 'xlsx');
        if ($requested && $requested !== '') {
            return sprintf('%s.%s', $requested, $ext);
        }

        return $this->getExportFileName($ext);

    }

    protected function getExportFileName(string $return_type = 'xlsx'): string
    {
        $model_name = class_basename($this->current_model);
        $date = Carbon::now()->format('_Y-M-d'); // Hyperf\Support helper (или \Carbon\Carbon::now())

        return helper_string_ucfirst((string) helper_string_plural((string) helper_string_snake($model_name))) . $date . '.' . $return_type;
    }

    /**
     * SET POSITIONS
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    protected function setPositionAction(BaseRequest $request, SetPositionActionOptionsDTO|array $options = []): ResponseInterface
    {
        /** @var SetPositionActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'set_positions',
            'action_options' => $options,
            'action_option_class' => SetPositionActionOptionsDTO::class,
        ]));

        $current_request = new SetPositionActionRequestPayloadDTO($request->all());

        /** @var SetPositionAction $set_position_action */
        $set_position_action = $this->getAction(SetPositionAction::class);
        $set_position_action->handle($current_options, $current_request);

        return $this->respondDto(
            $this->buildActionResponseDTO(data: ['status' => 'ok'])
        );
    }

    /**
     * SHOW
     *
     * @throws UnknownProperties
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     */
    protected function showAction(mixed $key, ShowActionOptionsDTO|array $options = []): ResponseInterface
    {
        /** @var ShowActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'show',
            'action_options' => $options,
            'action_option_class' => ShowActionOptionsDTO::class,
        ]));

        /** @var ShowAction $show_action */
        $show_action = $this->getAction(ShowAction::class);
        $model = $show_action->handle($current_options, $key);

        $resource = $this->getSingleResource();
        /** @var JsonResource $result */
        $result = new $resource($model, true);

        return $this->respondDto(
            $this->buildActionResponseDTO(data: $result)
        );
    }

    /**
     * STORE
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    protected function storeAction(BaseRequest $request, StoreActionOptionsDTO|array $options = [], ?Closure $closure = null): ResponseInterface
    {
        /** @var StoreActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'store',
            'action_options' => $options,
            'action_option_class' => StoreActionOptionsDTO::class,
        ]));

        /** @var StoreAction $store_action */
        $store_action = $this->getAction(StoreAction::class);
        $model = $store_action->handle(
            options: $current_options,
            data: $request->validated(),
            closure: $closure,
        );

        $resource = $this->getSingleResource();
        /** @var JsonResource $result */
        $result = new $resource($model, true);

        return $this->respondDto(
            $this->buildActionResponseDTO(data: $result)
        );
    }

    /**
     * UPDATE
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    protected function updateAction(BaseRequest $request, mixed $key, UpdateActionOptionsDTO|array $options = [], ?Closure $closure = null): ResponseInterface
    {
        /** @var UpdateActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'update',
            'action_options' => $options,
            'action_option_class' => UpdateActionOptionsDTO::class,
        ]));

        /** @var UpdateAction $update_action */
        $update_action = $this->getAction(UpdateAction::class);
        $updated_model = $update_action->handle(
            options: $current_options,
            key: $key,
            data: $request->validated(),
            closure: $closure
        );

        $resource = $this->getSingleResource();
        /** @var JsonResource $result */
        $result = new $resource($updated_model, true);

        return $this->respondDto(
            $this->buildActionResponseDTO(data: $result)
        );
    }

    /**
     * DESTROY
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    protected function destroyAction(mixed $key, DestroyActionOptionsDTO|array $options = [], ?Closure $closure = null): ResponseInterface
    {
        /** @var DestroyActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'destroy',
            'action_options' => $options,
            'action_option_class' => DestroyActionOptionsDTO::class,
        ]));

        /** @var DestroyAction $destroy_action */
        $destroy_action = $this->getAction(DestroyAction::class);
        $destroy_action->handle(
            options: $current_options,
            key: $key,
            closure: $closure,
        );

        return $this->respondDto(
            $this->buildActionResponseDTO(data: ['status' => 'ok'])
        );
    }

    /**
     * BULK DESTROY
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    protected function bulkDestroyAction(BaseRequest $request, BulkDestroyActionOptionsDTO|array $options = [], ?Closure $closure = null): ResponseInterface
    {
        /** @var BulkDestroyActionOptionsDTO $current_options */
        $current_options = $this->initFunction(new ApiCRUDControllerActionInitDTO([
            'action_name' => 'bulk_destroy',
            'action_options' => $options,
            'action_option_class' => BulkDestroyActionOptionsDTO::class,
        ]));

        $current_request = new BulkDestroyActionRequestPayloadDTO($request->all());

        /** @var BulkDestroyAction $bulk_destroy_action */
        $bulk_destroy_action = $this->getAction(BulkDestroyAction::class);
        $bulk_destroy_action->handle(
            options: $current_options,
            data: $current_request,
            closure: $closure
        );

        return $this->respondDto(
            $this->buildActionResponseDTO(data: ['status' => 'ok'])
        );
    }

    /* ===============================================================
       Экспорт — единая точка. Здесь НЕТ Laravel Excel.
       Реализуйте через box/spout, fast-excel или свой экспортёр.
       =============================================================== */

    /**
     * @param class-string $exporterClass
     * @param mixed        $items
     * @param array<int,string> $columns
     * @param string       $fileName
     * @param IndexActionOptionsExportExportTypeEnum $format
     */
    protected function exportDownload(
        string $exporterClass,
        mixed $items,
        array $columns,
        string $fileName,
        IndexActionOptionsExportExportTypeEnum $format
    ): ResponseInterface {
        // Вариант А: если у вашего экспортёра есть статический фабричный метод под Hyperf:
        // if (method_exists($exporterClass, 'download')) {
        //     /** @var ResponseInterface $resp */
        //     $resp = $exporterClass::download($items, $columns, $fileName, $format);
        //     return $resp;
        // }

        // Вариант Б: если экспортёр умеет отдать PSR-7 StreamInterface:
        // $exporter = new $exporterClass($items, $columns, false);
        // if (method_exists($exporter, 'toStream')) {
        //     $stream = $exporter->toStream($format); // Psr\Http\Message\StreamInterface
        //     return $this->response
        //         ->stream($stream)
        //         ->withHeader('Content-Type', $format === CSV ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        //         ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        // }

        // Пока реализации нет — честно сообщаем.
        throw new RuntimeException(
            'Export is not configured for Hyperf. ' .
            'Replace Laravel Excel usage with a Hyperf-compatible exporter (e.g., box/spout, fast-excel) ' .
            'and implement exportDownload() to return a streamed PSR-7 response.'
        );
    }
}
