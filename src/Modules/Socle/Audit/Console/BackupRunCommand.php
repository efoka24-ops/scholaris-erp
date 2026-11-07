<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

/** `backup:run` : sauvegarde chiffrée de la base. */
final class BackupRunCommand
{
    public function __construct(private readonly BackupCommands $commands)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        return $this->commands->runBackup($args);
    }
}
