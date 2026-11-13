<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/** Un conflit détecté : enseignant, salle, structure (classe ou groupe) ou capacité de la salle. */
final class Conflict
{
    public const TEACHER = 'teacher';
    public const ROOM = 'room';
    public const STRUCTURE = 'structure';
    public const CAPACITY = 'capacity';

    /** @param list<string> $structureIds structures concernées (conflit de structure) */
    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly ?string $slotId = null,
        public readonly array $structureIds = [],
    ) {
    }

    /** @return array{kind: string, message: string, slot_id: string|null, structure_ids: list<string>} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'message' => $this->message, 'slot_id' => $this->slotId, 'structure_ids' => $this->structureIds];
    }
}
