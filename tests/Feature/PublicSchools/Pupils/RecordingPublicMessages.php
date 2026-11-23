<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;

/** Doublure de PublicMessages : garde les notifications demandées (aucun envoi). */
final class RecordingPublicMessages implements PublicMessages
{
    public bool $failing = false;

    /** @var list<array{kind: string, tenant: string, establishment: string, target: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];

    public function isEssential(string $tenantId, string $templateKey): bool
    {
        return false;
    }

    public function notifyGuardians(string $tenantId, string $establishmentId, string $pupilPersonId, string $templateKey, array $variables = []): int
    {
        if ($this->failing) {
            throw new \RuntimeException('Passerelle indisponible.');
        }
        $this->sent[] = ['kind' => 'guardians', 'tenant' => $tenantId, 'establishment' => $establishmentId, 'target' => $pupilPersonId, 'template' => $templateKey, 'variables' => $variables];

        return 1;
    }

    public function notifyClass(string $tenantId, string $establishmentId, string $structureId, string $templateKey, array $variables = []): int
    {
        $this->sent[] = ['kind' => 'class', 'tenant' => $tenantId, 'establishment' => $establishmentId, 'target' => $structureId, 'template' => $templateKey, 'variables' => $variables];

        return 1;
    }

    public function notifyDirector(string $tenantId, string $establishmentId, string $templateKey, array $variables = []): bool
    {
        $this->sent[] = ['kind' => 'director', 'tenant' => $tenantId, 'establishment' => $establishmentId, 'target' => $establishmentId, 'template' => $templateKey, 'variables' => $variables];

        return true;
    }
}
