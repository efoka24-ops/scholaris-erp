<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;

/** Doublure du journal des consultations nominatives : garde les lectures enregistrées. */
final class RecordingNamedAccessJournal implements NamedAccessJournal
{
    /** @var list<array{tenant: string, establishment: string, actor: string, type: string, id: string, purpose: string}> */
    public array $entries = [];

    public function record(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $recordType,
        string $recordId,
        string $purpose,
        ?string $ip = null,
        ?string $device = null,
    ): void {
        $this->entries[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'actor' => $actorId, 'type' => $recordType, 'id' => $recordId, 'purpose' => $purpose];
    }
}
