<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Description minimale d'une application spécialisée : type d'établissement desservi et vocabulaire de l'interface. */
final class ApplicationDescriptor
{
    /** @param array<string, string> $vocabulary terme générique => terme affiché (learner, grouping, period, document) */
    public function __construct(
        public readonly string $establishmentType,
        public readonly string $label,
        public readonly array $vocabulary,
    ) {
    }
}
