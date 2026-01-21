<?php

namespace On1kel\HyperfLighty\Process;

use Hyperf\AsyncQueue\Process\ConsumerProcess;
use On1kel\HyperfLighty\Attributes\Process\DeployRoles;

#[DeployRoles(['queue'])]
final class QueueConsumerProcess extends ConsumerProcess
{
}
