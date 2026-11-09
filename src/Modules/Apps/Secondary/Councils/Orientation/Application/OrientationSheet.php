<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Émission de la fiche d'orientation DOC-16 (EX-PAS-03) par le conseiller d'orientation, dans son périmètre de classes : contenu tiré
 * du dossier d'orientation (vœux dans l'ordre, avis du conseiller, date d'entretien, décision du conseil ou recours accepté et son
 * motif). Sans décision, la fiche le dit (« en attente »). Chaque émission est journalisée ; le numéro vient du socle Documents.
 */
final class OrientationSheet
{
    public const ISSUERS = ['secondary.orientation.manage'];

    public function __construct(
        private readonly OrientationService $orientation,
        private readonly SecondaryDocuments $documents,
        private readonly ClassProfiles $classes,
        private readonly Access $access,
        private readonly Store $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function issue(Principal $principal, string $establishmentId, string $personId, string $academicYearId, ?string $locale): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::ISSUERS);
        $case = $this->orientation->show($principal, $establishmentId, $personId, $academicYearId);
        $this->access->assertWritableClass($principal, $perimeter, (string) $case['class_id'], 'se_orientation', $personId);
        $tenantId = $principal->tenantId;
        $class = $this->classes->find($tenantId, $establishmentId, (string) $case['class_id']);
        $wishes = [];
        foreach ((array) $case['wishes'] as $rank => $streamId) {
            $wishes[] = sprintf('%d. %s', $rank + 1, $this->streamLabel($tenantId, $establishmentId, (string) $streamId));
        }
        $decision = is_array($case['latest_decision'] ?? null) ? $case['latest_decision'] : null;
        $final = $this->store->select('se_orientations', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'created_at DESC', 1)[0] ?? null;
        $streamId = is_string($final['final_stream_id'] ?? null) ? (string) $final['final_stream_id'] : ($decision === null ? null : (string) $decision['stream_id']);
        $document = $this->documents->issue($tenantId, $establishmentId, OrientationDocumentTypes::ORIENTATION_SHEET, $personId, [
            'academic_year' => $class->academicYearId ?? $academicYearId, 'class_name' => $class->label ?? '-',
            'wishes_block' => $wishes === [] ? '-' : implode("\n", $wishes),
            'counsellor_opinion' => is_string($case['counsellor_opinion'] ?? null) && $case['counsellor_opinion'] !== '' ? (string) $case['counsellor_opinion'] : '-',
            'interview_on' => is_string($case['interview_on'] ?? null) ? (string) $case['interview_on'] : '-',
            'decision_label' => $streamId === null ? '-' : $this->streamLabel($tenantId, $establishmentId, $streamId),
            'decision_reason' => is_string($final['council_opinion'] ?? null) ? (string) $final['council_opinion'] : ($decision === null ? '-' : (string) $decision['reason']),
        ], $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.orientation.sheet.issued', 'se_orientation', $personId, null, ['document_id' => $document->id, 'number' => $document->number]);

        return ['person_id' => $personId, 'document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
    }

    private function streamLabel(string $tenantId, string $establishmentId, string $streamId): string
    {
        $stream = $this->store->find('se_streams', $tenantId, $establishmentId, $streamId);

        return $stream === null ? $streamId : trim((string) $stream['code'] . ' ' . (string) $stream['label']);
    }
}
