<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

/** `backup:restore` : restauration d'une sauvegarde chiffrée. */
final class BackupRestoreCommand
{
    public function __construct(private readonly BackupCommands $commands)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        return $this->commands->restore($args);
    }
}
