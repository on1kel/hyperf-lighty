<?php

namespace On1kel\HyperfLighty\Attributes\Process;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class DeployRoles
{
    /** @param string[] $roles */
    public function __construct(public array $roles) {}
}