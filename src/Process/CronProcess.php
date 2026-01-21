<?php

namespace On1kel\HyperfLighty\Process;

use Hyperf\Crontab\Process\CrontabDispatcherProcess;
use On1kel\HyperfLighty\Attributes\Process\DeployRoles;

#[DeployRoles(['cron'])]
final class CronProcess extends CrontabDispatcherProcess
{
}
