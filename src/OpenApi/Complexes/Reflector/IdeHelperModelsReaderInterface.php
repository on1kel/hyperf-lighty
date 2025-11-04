<?php

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector;

interface IdeHelperModelsReaderInterface
{
    /**
     *
     *  Возвращает карту FQCN → массив свойств из докблоков @property.
     * @return array<string, array<int, array<string,mixed>>>
     *
     */
    public function getPropertiesMap(): array;
}
