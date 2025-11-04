<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Requests\CRUD;

use On1kel\HyperfLighty\Http\Requests\BaseRequest;

class BulkDestroyRequest extends BaseRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
        ];
    }
}
