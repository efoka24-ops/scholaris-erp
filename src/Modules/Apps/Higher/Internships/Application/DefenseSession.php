<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Internships\Documents\InternshipDocumentTypes;
use Scholaris\Modules\Apps\Higher\Internships\Domain\Grade;
use Scholaris\Modules\Apps\Higher\Internships\Domain\JuryRules;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Throwable;

/**
 * Déroulement d'une soutenance : convocation (DOC-21 et NOT-13 à l'étudiant), note du jury (président du jury ou responsable des
 * stages, à partir du jour de la soutenance), PV de soutenance (DOC-22) qui clôt la soutenance. La note reste dans le module :
 * voir Contracts\DefenseResults. Un échec d'envoi du message n'interrompt jamais la convocation.
 */
final class DefenseSession
{
    public const TEMPLATE = 'higher.not13.defense_convocation';
    public const PASS_MARK_KEY = 'higher.defense.pass_mark';

    public function __construct(
        private readonly InternshipStore $store,
        private readonly InternshipAccess $access,
        private readonly InternshipClock $clock,
        private readonly DefenseService $defenses,
        private readonly InternshipDocuments $documents,
        private readonly Messenger $messenger,
        private readonly OrgUnitSettingsReader $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function convene(Principal $principal, string $establishmentId, string $id): array
    {
        $defense = $this->defenses->writable($principal, $establishmentId, $id, InternshipAccess::DEFENSE);
        if ($defense['status'] !== DefenseService::SCHEDULED) {
            throw HttpException::conflict('Seule une soutenance programmée (non encore convoquée) peut être convoquée.');
        }
        $internship = $this->internship($principal->tenantId, $establishmentId, $defense);
        $documentId = $this->documents->issue($principal->tenantId, $establishmentId, InternshipDocumentTypes::CONVOCATION, $internship, $this->defenseData($principal->tenantId, $establishmentId, $defense, $internship), $principal->userId);
        $this->store->update(DefenseService::TABLE, $principal->tenantId, $establishmentId, [
            'status' => DefenseService::CONVENED, 'convocation_document_id' => $documentId, 'convened_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
        ], ['id' => $id]);
        try {
            $this->messenger->send($principal->tenantId, $establishmentId, (string) $defense['person_id'], self::TEMPLATE, [
                'subject' => (string) ($internship['thesis_title'] ?? $internship['subject']), 'date' => (string) $defense['held_on'],
                'time' => (string) $defense['start_time'], 'label' => (string) $defense['room'], 'reference' => 'STG-' . substr((string) $internship['id'], -8),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('NOT-13 non envoyée : ' . $e->getMessage());
        }
        $this->access->audit($principal, 'higher.defense.convened', 'hi_defense', $id, null, ['document_id' => $documentId]);

        return $this->defenses->row($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function grade(Principal $principal, string $establishmentId, string $id, int $hundredths, ?string $remarks): array
    {
        $defense = $this->juryOrManager($principal, $establishmentId, $id);
        if (!in_array($defense['status'], [DefenseService::CONVENED, DefenseService::GRADED], true)) {
            throw HttpException::conflict('La note se saisit sur une soutenance convoquée et non clôturée.');
        }
        if ($this->clock->today() < (string) $defense['held_on']) {
            throw HttpException::conflict('La note ne peut être saisie avant le jour de la soutenance (' . $defense['held_on'] . ').');
        }
        $now = $this->clock->now();
        $this->store->update(DefenseService::TABLE, $principal->tenantId, $establishmentId, [
            'status' => DefenseService::GRADED, 'grade_hundredths' => $hundredths, 'remarks' => $remarks, 'graded_by' => $principal->userId, 'graded_at' => $now, 'updated_at' => $now,
        ], ['id' => $id]);
        $this->access->audit($principal, 'higher.defense.graded', 'hi_defense', $id, ['grade_hundredths' => $defense['grade_hundredths']], ['grade_hundredths' => $hundredths]);

        return $this->defenses->row($principal->tenantId, $establishmentId, $id);
    }

    /**
     * PV de soutenance (DOC-22) : clôt la soutenance notée.
     *
     * @return array<string, mixed>
     */
    public function close(Principal $principal, string $establishmentId, string $id): array
    {
        $defense = $this->juryOrManager($principal, $establishmentId, $id);
        if ($defense['status'] !== DefenseService::GRADED) {
            throw HttpException::conflict('Le PV ne s\'établit que pour une soutenance notée et non clôturée.');
        }
        $internship = $this->internship($principal->tenantId, $establishmentId, $defense);
        $hundredths = (int) $defense['grade_hundredths'];
        $pass = $this->settings->valueForOrgUnit($principal->tenantId, $establishmentId, (string) $defense['school_id'], self::PASS_MARK_KEY, 10);
        $passed = $hundredths >= (int) round((is_numeric($pass) ? (float) $pass : 10.0) * 100);
        $documentId = $this->documents->issue($principal->tenantId, $establishmentId, InternshipDocumentTypes::MINUTES, $internship, $this->defenseData($principal->tenantId, $establishmentId, $defense, $internship) + [
            'grade_label' => Grade::label($hundredths), 'result_label' => $passed ? 'Admis(e)' : 'Ajourné(e)', 'remarks' => (string) ($defense['remarks'] ?? ''),
        ], $principal->userId);
        $now = $this->clock->now();
        $this->store->update(DefenseService::TABLE, $principal->tenantId, $establishmentId, [
            'status' => DefenseService::CLOSED, 'minutes_document_id' => $documentId, 'closed_at' => $now, 'updated_at' => $now,
        ], ['id' => $id]);
        $this->access->audit($principal, 'higher.defense.closed', 'hi_defense', $id, null, ['document_id' => $documentId, 'grade_hundredths' => $hundredths]);

        return $this->defenses->row($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Responsable des stages dans son périmètre, ou président du jury de cette soutenance (personne liée au compte).
     *
     * @return array<string, mixed>
     */
    private function juryOrManager(Principal $principal, string $establishmentId, string $id): array
    {
        $defense = $this->defenses->row($principal->tenantId, $establishmentId, $id);
        if ($this->access->canWrite($principal, $establishmentId, InternshipAccess::DEFENSE, $defense)) {
            return $defense;
        }
        $me = $this->access->personOf($principal);
        foreach ($this->defenses->jury($principal->tenantId, $establishmentId, $id) as $member) {
            if ($me !== null && $member['person_id'] === $me) {
                if ($member['jury_role'] === JuryRules::PRESIDENT) {
                    return $defense;
                }
                throw HttpException::forbidden('Seul le président du jury saisit la note et établit le PV.');
            }
        }

        throw HttpException::notFound('Soutenance introuvable.');
    }

    /**
     * @param array<string, mixed> $defense
     * @return array<string, mixed>
     */
    private function internship(string $tenantId, string $establishmentId, array $defense): array
    {
        return $this->store->findOrFail(ConventionService::TABLE, $tenantId, $establishmentId, (string) $defense['internship_id']);
    }

    /**
     * @param array<string, mixed> $defense
     * @param array<string, mixed> $internship
     * @return array<string, scalar|null>
     */
    private function defenseData(string $tenantId, string $establishmentId, array $defense, array $internship): array
    {
        $jury = $this->defenses->jury($tenantId, $establishmentId, (string) $defense['id']);
        $president = array_values(array_filter($jury, static fn (array $m): bool => $m['jury_role'] === JuryRules::PRESIDENT));

        return [
            'thesis_title' => (string) ($internship['thesis_title'] ?? ''), 'defense_date' => (string) $defense['held_on'],
            'defense_time' => $defense['start_time'] . '-' . $defense['end_time'], 'defense_room' => (string) $defense['room'],
            'jury_line' => implode(', ', array_column($jury, 'display_name')), 'president_name' => $president[0]['display_name'] ?? '',
        ];
    }
}
