<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;
use Scholaris\Modules\Socle\Reporting\Contracts\TodoProvider;

/** Approbations en attente de CETTE personne : statut revérifié via Approvals::find, jamais ses propres demandes. */
final class ApprovalTodoProvider implements TodoProvider
{
    private const TITLES = [
        'payment.reversal' => 'Annulation de paiement à approuver',
        'grade.correction' => 'Correction de note à approuver',
    ];

    public function __construct(private readonly PendingApprovalLister $lister, private readonly Approvals $approvals)
    {
    }

    public function forUser(string $tenantId, string $userId): array
    {
        $items = [];
        foreach (array_slice($this->lister->candidateIds($tenantId, $userId), 0, 100) as $id) {
            $approval = $this->approvals->find($tenantId, $id);
            if ($approval === null || ($approval['status'] ?? null) !== Approvals::PENDING || ($approval['requested_by'] ?? null) === $userId) {
                continue;
            }
            $kind = (string) ($approval['kind'] ?? '');
            $items[] = [
                'kind' => 'approval',
                'title' => self::TITLES[$kind] ?? 'Validation en attente',
                'due_at' => isset($approval['created_at']) ? (string) $approval['created_at'] : null,
                'link' => '/approvals/' . $id,
            ];
        }

        return $items;
    }
}
