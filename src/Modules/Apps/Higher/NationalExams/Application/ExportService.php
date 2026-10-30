<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Export de la liste nationale (API-17, EX-EXN-04, T332) : 2FA récente exigée, dossiers complets seulement, format par modèle
 * (types `minesup.bts` et `minesup.hnd` à colonnes paramétrables, CSV ou XLSX ; gabarit MINESUP exact à confirmer),
 * filigrane nominatif, empreinte SHA-256, journal et alerte au directeur général. Convocations : centre et numéros (EX-EXN-05).
 */
final class ExportService
{
    public const SECOND_FACTOR_TYPE = 'https://api.scholaris.cm/errors/second-factor-required';
    public const FORMATS = ['csv', 'xlsx'];
    private const LISTED = [CandidateLifecycle::COMPLETE, CandidateLifecycle::EXPORTED, CandidateLifecycle::CONVENED];

    public function __construct(
        private readonly NationalExamsStore $store,
        private readonly NationalExamsAccess $access,
        private readonly SessionService $sessions,
        private readonly ListJournal $journal,
        private readonly TabularExporter $exporter,
        private readonly PersonDirectory $people,
        private readonly RecentSecondFactor $secondFactor,
        private readonly SettingsReader $settings,
        private readonly NationalExamsClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(Principal $principal, string $establishmentId, string $sessionId, ?string $format): array
    {
        $session = $this->sessions->writable($principal, $establishmentId, $sessionId, NationalExamsPermissions::EXPORT);
        $tenantId = $principal->tenantId;
        $maxAge = (int) $this->settings->value($tenantId, $establishmentId, NationalExamsPermissions::SETTING_SECOND_FACTOR_AGE, 900);
        if (!$this->secondFactor->hasRecent($tenantId, $principal->userId, max(60, $maxAge))) {
            $this->access->audit($principal, 'higher.national_list.export_refused', 'national_session', $sessionId, null, ['reason' => 'second_factor']);
            throw new HttpException(403, 'Double authentification requise', 'Validez votre double authentification avant d\'exporter la liste nationale (T332).', self::SECOND_FACTOR_TYPE);
        }
        $format ??= (string) $this->settings->value($tenantId, $establishmentId, NationalExamsPermissions::SETTING_FORMAT, 'csv');
        if (!in_array($format, self::FORMATS, true)) {
            throw HttpException::validation(['format' => ['Format attendu : csv ou xlsx (gabarit PDF MINESUP à confirmer).']]);
        }
        $candidates = array_values(array_filter(
            $this->store->select(CandidateService::TABLE, $tenantId, $establishmentId, ['session_id' => $sessionId], 'matricule, id', 10000),
            static fn (array $c): bool => in_array($c['status'], self::LISTED, true),
        ));
        if ($candidates === []) {
            throw HttpException::conflict('Aucun dossier complet à exporter (inscription, assiduité, frais et pièces).');
        }
        $now = $this->clock->now();
        $watermark = sprintf('Exporté par %s le %s - document nominatif, diffusion restreinte', $principal->userId, $now);
        $file = $this->exporter->render($tenantId, $establishmentId, 'minesup.' . $session['exam_type'], $this->records($tenantId, $session, $candidates, $watermark), $format);
        $fingerprint = hash('sha256', $file->content);
        $this->store->transaction(function () use ($tenantId, $establishmentId, $sessionId, $session, $principal, $now, $fingerprint, $candidates): void {
            $this->store->update(SessionService::TABLE, $tenantId, $establishmentId, [
                'status' => $session['status'] === CandidateLifecycle::SESSION_CLOSED ? CandidateLifecycle::SESSION_CLOSED : CandidateLifecycle::SESSION_EXPORTED,
                'export_count' => (int) $session['export_count'] + 1, 'last_exported_at' => $now, 'last_exported_by' => $principal->userId,
                'export_fingerprint' => $fingerprint, 'updated_at' => $now,
            ], ['id' => $sessionId]);
            foreach ($candidates as $candidate) {
                if ($candidate['status'] === CandidateLifecycle::COMPLETE) {
                    $this->store->update(CandidateService::TABLE, $tenantId, $establishmentId, ['status' => CandidateLifecycle::EXPORTED, 'updated_at' => $now], ['id' => (string) $candidate['id']]);
                }
            }
        });
        $this->journal->record($principal, $session, null, 'exported', ['rows' => $file->rows, 'format' => $format, 'fingerprint' => $fingerprint, 'export_number' => (int) $session['export_count'] + 1]);

        return [
            'session_id' => $sessionId, 'watermark' => $watermark, 'fingerprint' => $fingerprint,
            'file' => ['filename' => $file->filename, 'format' => $file->format, 'content_type' => $file->contentType, 'rows' => $file->rows,
                'columns' => $file->columns, 'content_base64' => base64_encode($file->content)],
        ];
    }

    /** @return array{convened: int} */
    public function convene(Principal $principal, string $establishmentId, string $sessionId, string $centre, string $prefix): array
    {
        $session = $this->sessions->writable($principal, $establishmentId, $sessionId);
        if ((int) $session['export_count'] === 0) {
            throw HttpException::conflict('Les convocations suivent l\'export de la liste.');
        }
        $now = $this->clock->now();
        $count = 0;
        $exported = $this->store->select(CandidateService::TABLE, $principal->tenantId, $establishmentId, ['session_id' => $sessionId, 'status' => CandidateLifecycle::EXPORTED], 'matricule, id', 10000);
        foreach ($exported as $index => $candidate) {
            $number = is_string($candidate['candidate_number']) && $candidate['candidate_number'] !== '' ? $candidate['candidate_number'] : sprintf('%s%04d', $prefix, $index + 1);
            $this->store->update(CandidateService::TABLE, $principal->tenantId, $establishmentId, [
                'status' => CandidateLifecycle::CONVENED, 'candidate_number' => $number, 'exam_centre' => $centre, 'convened_at' => $now, 'updated_at' => $now,
            ], ['id' => (string) $candidate['id']]);
            ++$count;
        }
        $this->journal->record($principal, $session, null, 'convened', ['count' => $count, 'exam_centre' => $centre]);

        return ['convened' => $count];
    }

    /**
     * @param array<string, mixed> $session
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, scalar|null>>
     */
    private function records(string $tenantId, array $session, array $candidates, string $watermark): array
    {
        $people = [];
        foreach ($this->people->findMany($tenantId, array_map(static fn (array $c): string => (string) $c['person_id'], $candidates)) as $person) {
            $people[$person->id] = $person;
        }
        $records = [];
        foreach ($candidates as $c) {
            $person = $people[(string) $c['person_id']] ?? null;
            $records[] = [
                'candidate_number' => $c['candidate_number'], 'matricule' => $c['matricule'], 'last_name' => $person?->lastName, 'first_names' => $person?->firstName,
                'gender' => $person?->gender, 'specialty' => (string) $session['specialty_label'], 'exam_type' => strtoupper((string) $session['exam_type']),
                'academic_year' => (string) $session['academic_year_id'], 'presence_percent' => (string) $c['presence_percent'], 'fee_receipt' => $c['fee_receipt'],
                'exam_centre' => $c['exam_centre'], 'watermark' => $watermark,
            ];
        }

        return $records;
    }
}
