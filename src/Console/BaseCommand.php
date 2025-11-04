<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console;

use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @property SymfonyStyle $output
 * @property InputInterface $input
 */
abstract class BaseCommand extends HyperfCommand
{
    public function __construct(string $name)
    {
        $this->eventDispatcher = null;
        parent::__construct($name);
    }
}
