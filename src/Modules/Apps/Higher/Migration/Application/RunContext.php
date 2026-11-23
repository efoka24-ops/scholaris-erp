<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;

/** État d'une passe (simulation ou application) : client, établissement, acteur, année, périmètre, clés vues et clés déjà reprises. */
final class RunContext
{
    /** @var array<string, int> clé de reprise => première ligne du fichier */
    public array $seen = [];

    /** @var array<string, mixed> mémos de résolution (filières, niveaux, étudiants) */
    public array $cache = [];

    /** @param array<string, true> $prior clés déjà reprises par un lot appliqué et non annulé */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $actorId,
        public readonly string $yearId,
        public readonly string $batchId,
        public readonly Perimeter $perimeter,
        public readonly array $prior = [],
    ) {
    }

    /** Mémorise la clé ; renvoie la ligne où elle a déjà été vue, null la première fois. */
    public function firstSeen(string $key, int $line): ?int
    {
        if (isset($this->seen[$key])) {
            return $this->seen[$key];
        }
        $this->seen[$key] = $line;

        return null;
    }

    public function alreadyImported(string $key): bool
    {
        return isset($this->prior[$key]);
    }
}
