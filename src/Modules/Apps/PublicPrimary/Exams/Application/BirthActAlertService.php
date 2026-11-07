<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * NOT-08 (RG-EXA-1, RG-ELV-2) : dès le mois paramétré de l'année d'examen (janvier par défaut), la famille de chaque candidat dont
 * l'acte de naissance n'est pas vérifié est prévenue, une seule fois par session, et le directeur reçoit un récapitulatif.
 * Deux déclencheurs : le directeur (bouton de l'écran) ou la tâche quotidienne `public-primary:exam-birth-acts`.
 */
final class BirthActAlertService
{
    public const TEMPLATE = 'pub.not08.parent';

    public function __construct(
        private readonly PdoExams $repository,
        private readonly ExamAccess $access,
        private readonly ExamSupport $support,
        private readonly PublicMessages $messages,
        private readonly ApplicationAccess $applications,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array{alerted: int, messages: int} */
    public function forSchool(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_list', $sessionId);
        $session = $this->support->session($principal->tenantId, $sessionId);
        $rows = $this->repository->candidatesIn($principal->tenantId, $sessionId, [$establishmentId]);
        if ($rows === []) {
            throw HttpException::conflict('Aucun candidat pour cette école.');
        }
        $result = $this->alert($principal->tenantId, $establishmentId, $session, $rows);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.exams.birth_act_alerts', 'pp_exam_session', $sessionId, null, $result);

        return $result;
    }

    /**
     * Tâche planifiée : toutes les écoles des clients dont l'application est ouverte en écriture, à partir du mois d'alerte de l'année
     * d'examen ; un client suspendu est sauté.
     *
     * @return array{alerted: int, messages: int, skipped_tenants: int}
     */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        $groups = [];
        foreach ($this->repository->candidatesToAlert() as $row) {
            $groups[(string) $row['tenant_id']][(string) $row['establishment_id'] . '|' . (string) $row['session_id']][] = $row;
        }
        $total = ['alerted' => 0, 'messages' => 0, 'skipped_tenants' => 0];
        foreach ($groups as $tenantId => $bySchool) {
            if (!$this->applications->allowsWrites((string) $tenantId, 'public_primary')) {
                ++$total['skipped_tenants'];
                continue;
            }
            foreach ($bySchool as $key => $rows) {
                [$establishmentId, $sessionId] = explode('|', (string) $key, 2);
                $session = $this->repository->session((string) $tenantId, $sessionId);
                if ($session === null) {
                    continue;
                }
                $month = max(1, min(12, $this->rules->int((string) $tenantId, $establishmentId, 'public_primary.exams.birth_act_alert_month')));
                if ($today < sprintf('%04d-%02d-01', ExamSupport::examYear((string) $session['year_label']), $month)) {
                    continue;
                }
                $result = $this->alert((string) $tenantId, $establishmentId, $session, $rows);
                $total['alerted'] += $result['alerted'];
                $total['messages'] += $result['messages'];
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $session
     * @param list<array<string, mixed>> $rows
     * @return array{alerted: int, messages: int}
     */
    private function alert(string $tenantId, string $establishmentId, array $session, array $rows): array
    {
        $alerted = 0;
        $messages = 0;
        $now = $this->clock->now();
        foreach ($rows as $row) {
            if ($row['birth_act_alerted_at'] !== null || $row['candidate_state'] === 'withdrawn') {
                continue;
            }
            $pupil = $this->support->pupil($tenantId, (string) $row['person_id']);
            if ($pupil === null || $pupil->birthActVerified) {
                continue;
            }
            $messages += $this->messages->notifyGuardians($tenantId, $establishmentId, (string) $row['person_id'], self::TEMPLATE, ['exam_label' => $this->support->examLabel($session)]);
            $this->repository->update('pp_exam_candidates', $tenantId, (string) $row['id'], ['birth_act_alerted_at' => $now, 'updated_at' => $now]);
            ++$alerted;
        }
        if ($alerted > 0 && $this->messages->notifyDirector($tenantId, $establishmentId, self::TEMPLATE, ['child_name' => $alerted . ' candidat(s)', 'exam_label' => $this->support->examLabel($session)])) {
            ++$messages;
        }
        return ['alerted' => $alerted, 'messages' => $messages];
    }
}
