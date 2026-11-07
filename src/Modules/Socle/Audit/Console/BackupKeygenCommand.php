<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

/** `backup:keygen` : génère une clé de sauvegarde. */
final class BackupKeygenCommand
{
    public function __construct(private readonly BackupCommands $commands)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        return $this->commands->keygen();
    }
}
