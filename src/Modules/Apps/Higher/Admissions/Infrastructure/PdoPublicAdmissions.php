<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Infrastructure;

use PDO;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsClock;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicAdmissions;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicInstitute;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;

/** Contrat PublicAdmissions sur hi_public_links et hi_campaigns ; libellés par AcademicLabels, nom de l'institut par l'annuaire du socle. */
final class PdoPublicAdmissions implements PublicAdmissions
{
    /** Forme d'un jeton de lien : refusée avant toute lecture en base si elle n'est pas respectée. */
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly EstablishmentDirectory $establishments,
        private readonly AcademicLabels $labels,
        private readonly WriteGate $gate,
        private readonly AdmissionsClock $clock,
    ) {
    }

    public function resolve(string $slug): ?PublicInstitute
    {
        if (strlen($slug) < 8 || strlen($slug) > 40 || preg_match(self::SLUG, $slug) !== 1) {
            return null;
        }
        $statement = $this->store->pdo()->prepare('SELECT tenant_id, establishment_id FROM hi_public_links WHERE slug = ? AND enabled = 1');
        $statement->execute([$slug]);
        $link = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($link)) {
            return null;
        }
        $tenantId = (string) $link['tenant_id'];
        $establishmentId = (string) $link['establishment_id'];
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        if ($establishment === null || $establishment->type !== 'higher' || !$this->gate->allowsWrites($tenantId)) {
            return null;
        }

        return new PublicInstitute($tenantId, $establishmentId, $establishment->name);
    }

    public function openCampaigns(string $tenantId, string $establishmentId): array
    {
        $today = $this->clock->today();
        $rows = array_values(array_filter(
            $this->store->select('hi_campaigns', $tenantId, $establishmentId, ['status' => 'open'], 'closes_on, id', 100),
            static fn (array $row): bool => $row['opens_on'] <= $today && $today <= $row['closes_on'],
        ));
        $names = [];
        foreach ([AcademicLabels::SCHOOL => 'school_id', AcademicLabels::PROGRAM => 'program_id', AcademicLabels::LEVEL => 'level_id'] as $kind => $column) {
            $names[$kind] = $this->labels->names($tenantId, $establishmentId, $kind, array_values(array_unique(array_map(static fn (array $r): string => (string) $r[$column], $rows))));
        }

        return array_map(static function (array $row) use ($names): array {
            $documents = [];
            $decoded = json_decode((string) $row['required_documents_json'], true);
            foreach (is_array($decoded) ? $decoded : [] as $document) {
                if (is_array($document) && isset($document['code'], $document['label'])) {
                    $documents[] = ['code' => (string) $document['code'], 'label' => (string) $document['label'], 'blocking' => (bool) ($document['blocking'] ?? false)];
                }
            }

            return [
                'id' => (string) $row['id'],
                'title' => (string) $row['title'],
                'school' => $names[AcademicLabels::SCHOOL][$row['school_id']] ?? '',
                'program' => $names[AcademicLabels::PROGRAM][$row['program_id']] ?? '',
                'level' => $names[AcademicLabels::LEVEL][$row['level_id']] ?? '',
                'opens_on' => (string) $row['opens_on'],
                'closes_on' => (string) $row['closes_on'],
                'seats' => (int) $row['seats'],
                'application_fee_xaf' => (int) $row['application_fee_xaf'],
                'selection_mode' => (string) $row['selection_mode'],
                'required_documents' => $documents,
            ];
        }, $rows);
    }
}
