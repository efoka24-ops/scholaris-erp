<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

/** Compte rendu de higher:pilot-setup : lignes lisibles et compteurs par nature d'objet. */
final class SetupReport
{
    public string $establishmentId = '';

    public string $template = '';

    public bool $dryRun = false;

    /** @var list<string> */
    public array $lines = [];

    /** @var array<string, int> */
    public array $created = [];

    /** @var array<string, int> */
    public array $kept = [];

    public function create(string $kind): void
    {
        $this->created[$kind] = ($this->created[$kind] ?? 0) + 1;
    }

    public function keep(string $kind): void
    {
        $this->kept[$kind] = ($this->kept[$kind] ?? 0) + 1;
    }
}
