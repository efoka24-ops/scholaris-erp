<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Fonction pouvant valider une étape : un droit (permission) et, si le droit est partagé par plusieurs rôles, le rôle précis
 * attendu. Le périmètre (école, département...) reste celui de l'affectation, jamais choisi par le circuit.
 */
final class Actor
{
    public function __construct(
        public readonly string $key,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly string $permission,
        public readonly ?string $role = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label_fr' => $this->labelFr, 'label_en' => $this->labelEn, 'permission' => $this->permission, 'role' => $this->role];
    }
}
