<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Alertes de sécurité (NOT-MAT-02 à 05) par le canal essentiel (SafetyMessenger) : un parent qui a refusé les SMS non essentiels
 * les reçoit quand même (RG-MAT-9). Une alerte non remise n'est JAMAIS silencieuse : le résultat de chaque envoi est compté, une
 * erreur d'envoi est journalisée et l'appelant reçoit le compte-rendu (`sent`, `queued`, `blocked`, `failed`).
 */
final class AlertSender
{
    public const DIRECTOR_ROLES = ['directeur', 'nursery_directeur'];

    public function __construct(
        private readonly SafetyMessenger $messenger,
        private readonly PersonDirectory $people,
        private readonly RoleDirectory $roles,
        private readonly EstablishmentDirectory $establishments,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, scalar> $variables
     * @return array{sent: int, queued: int, blocked: int, failed: int}
     */
    public function toParents(Principal $actor, string $establishmentId, string $learnerId, string $template, array $variables, ?string $excludedPersonId = null): array
    {
        $summary = ['sent' => 0, 'queued' => 0, 'blocked' => 0, 'failed' => 0];
        $recipients = [];
        foreach ($this->people->guarantorsOf($actor->tenantId, $learnerId) as $guardian) {
            if ($excludedPersonId !== null && $guardian->id === $excludedPersonId) {
                continue; // la personne visée par une restriction ne reçoit aucune alerte
            }
            $recipients[] = $guardian->id;
        }

        return $this->deliver($actor, $establishmentId, $recipients, $template, $variables, $summary);
    }

    /**
     * @param array<string, scalar> $variables
     * @return array{sent: int, queued: int, blocked: int, failed: int}
     */
    public function toDirection(Principal $actor, string $establishmentId, string $template, array $variables): array
    {
        $summary = ['sent' => 0, 'queued' => 0, 'blocked' => 0, 'failed' => 0];

        return $this->deliver($actor, $establishmentId, $this->roles->personIdsWithRole($actor->tenantId, $establishmentId, self::DIRECTOR_ROLES), $template, $variables, $summary);
    }

    /** @return array<string, scalar> variables communes d'un message sur un enfant */
    public function variables(string $tenantId, string $establishmentId, string $learnerId, string $day, string $time, string $details, string $reference): array
    {
        $child = $this->people->find($tenantId, $learnerId);

        return [
            'child_name' => $child !== null ? $child->fullName() : '',
            'establishment_name' => $this->establishments->find($tenantId, $establishmentId)->name ?? '',
            'date' => $day,
            'time' => $time,
            'details' => $details,
            'reference' => $reference,
        ];
    }

    /**
     * @param list<string> $recipients
     * @param array<string, scalar> $variables
     * @param array{sent: int, queued: int, blocked: int, failed: int} $summary
     * @return array{sent: int, queued: int, blocked: int, failed: int}
     */
    private function deliver(Principal $actor, string $establishmentId, array $recipients, string $template, array $variables, array $summary): array
    {
        foreach (array_values(array_unique($recipients)) as $personId) {
            try {
                $result = $this->messenger->send($actor->tenantId, $personId, $template, $variables);
            } catch (\Throwable $error) {
                ++$summary['failed'];
                $this->access->audit($actor, 'nursery.alert.failed', 'person', $personId, null, ['template' => $template, 'error' => $error::class]);
                continue;
            }
            match ($result->status) {
                DeliveryResult::SENT => ++$summary['sent'],
                DeliveryResult::QUEUED => ++$summary['queued'],
                default => ++$summary['blocked'],
            };
            if ($result->status === DeliveryResult::BLOCKED) {
                $this->access->audit($actor, 'nursery.alert.undelivered', 'person', $personId, null, ['template' => $template, 'reason' => $result->reason]);
            }
        }

        return $summary;
    }
}
