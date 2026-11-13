<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/**
 * Journal de la liste nationale (RG-EXN-2, T332) : chaque événement est écrit dans hi_national_list_events et au journal
 * d'audit ; l'export et toute modification APRÈS export sont signalés au directeur général (R02) par e-mail urgent.
 * Un échec d'envoi n'interrompt jamais l'opération (Messenger rend « blocked »).
 */
final class ListJournal
{
    public const TABLE = 'hi_national_list_events';
    /** Modifications de la liste elle-même (RG-EXN-2) ; convocations et résultats sont journalisés sans alerte. */
    public const LIST_CHANGES = ['added', 'updated', 'withdrawn'];

    public function __construct(
        private readonly NationalExamsStore $store,
        private readonly NationalExamsAccess $access,
        private readonly RoleDirectory $roles,
        private readonly Messenger $messenger,
        private readonly NationalExamsClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $detail
     */
    public function record(Principal $principal, array $session, ?string $candidateId, string $kind, array $detail): void
    {
        $afterExport = (int) $session['export_count'] > 0;
        $alerted = 0;
        if ($kind === 'exported' || ($afterExport && in_array($kind, self::LIST_CHANGES, true))) {
            $alerted = $this->alertGeneralDirector($principal, $session, $kind === 'exported' ? NationalExamsPermissions::ALERT_EXPORTED : NationalExamsPermissions::ALERT_CHANGED, $kind, $detail);
        }
        $this->store->insert(self::TABLE, $principal->tenantId, (string) $session['establishment_id'], [
            'id' => (string) new Ulid(), 'session_id' => (string) $session['id'], 'candidate_id' => $candidateId, 'event_kind' => $kind,
            'after_export' => $afterExport ? 1 : 0, 'detail' => json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'actor_id' => $principal->userId, 'alerted_count' => $alerted, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'higher.national_list.' . $kind, 'national_session', (string) $session['id'], null, $detail + ['candidate_id' => $candidateId, 'after_export' => $afterExport]);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $detail
     */
    private function alertGeneralDirector(Principal $principal, array $session, string $template, string $kind, array $detail): int
    {
        $label = strtoupper((string) $session['exam_type']) . ' ' . (string) $session['specialty_label'];
        $details = $kind . ' ' . mb_substr((string) json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 250);
        $sent = 0;
        foreach ($this->roles->personIdsWithRole($principal->tenantId, (string) $session['establishment_id'], [NationalExamsPermissions::ROLE_GENERAL_DIRECTOR]) as $personId) {
            $result = $this->messenger->send($principal->tenantId, (string) $session['establishment_id'], $personId, $template, [
                'label' => mb_substr($label, 0, 200), 'reference' => (string) $session['id'], 'details' => $details,
            ], ['email'], true);
            if ($result->status !== DeliveryResult::BLOCKED) {
                ++$sent;
            }
        }

        return $sent;
    }
}
