<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Models\Attributes\Relationships;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Relationship
{
    /**
     * @param  string  $related
     * @param  RelationshipTypeEnum  $type
     * @param  array<string>  $aliases
     */
    public function __construct(
        public string $related,
        public RelationshipTypeEnum $type,
        public array $aliases = [],
    ) {
    }
}
