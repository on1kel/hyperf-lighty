<?php

namespace On1kel\HyperfLighty\OpenApi\SecuritySchemes;

use On1kel\HyperfFlyDocs\Generator\Contracts\SecuritySchemesContainerContract;
use On1kel\OAS\Builder\Security\SecurityRequirement;
use On1kel\OAS\Builder\Security\SecurityScheme;

final class SecuritySchemeContainer implements SecuritySchemesContainerContract
{
    public static function getSecuritySchemes(): array
    {
        return [
            'ApiAuthSecurityScheme' => SecurityScheme::httpBearer('JWT'),
        ];
    }

    public static function getDefaultSecurity(): array
    {
        return [
            SecurityRequirement::create()->add('ApiAuthSecurityScheme'),
        ];
    }
}
