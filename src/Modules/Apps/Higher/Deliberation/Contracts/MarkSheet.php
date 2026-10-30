<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Notes d'un groupe pour une période et une session (voir MarkSource).
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class MarkSheet
{
    /**
     * @param array<string, list<Unit>> $units personne => unités (UE et EC avec leurs épreuves)
     * @param list<string> $unlocked intitulés des épreuves encore modifiables (non verrouillées)
     */
    public function __construct(
        public readonly array $units,
        public readonly array $unlocked = [],
    ) {
    }
}
