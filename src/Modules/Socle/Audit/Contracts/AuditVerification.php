<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/** Résultat d'une vérification de chaîne : intacte, ou première rupture détectée. */
final class AuditVerification
{
    public function __construct(
        public readonly bool $intact,
        public readonly int $checked,
        public readonly ?int $brokenAt = null,
    ) {
    }
}
