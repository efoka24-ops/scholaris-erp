<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;
use Scholaris\Modules\Apps\Higher\Tutelage\Domain\TransmissionRules;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageExportTypes;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageStore;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;

/**
 * Transmissions à l'université de tutelle (EX-TUT-01/02) : liste des inscrits (DOC-18, fichier au format de l'université),
 * notes, relevés et PV pour validation (seulement après signature du PV de la période). Chaque lot porte une empreinte SHA-256,
 * est journalisé, puis reçoit la réponse de la tutelle (accusé ou rejet).
 */
final class TransmissionService
{
    public const TABLE = 'hi_tutelage_transmissions';

    public function __construct(
        private readonly TutelageStore $store,
        private readonly TutelageAccess $access,
        private readonly TutelageContext $context,
        private readonly EnrolledList $enrolled,
        private readonly TabularExporter $exporter,
        private readonly DeliberationRecords $deliberations,
        private readonly TutelageClock $clock,
    ) {
    }

    /**
     * @param array{kind: string, program_id: string, academic_year_id: string, level_id: ?string, period_id: ?string, format: string, note: ?string} $data
     * @return array<string, mixed>
     */
    public function transmit(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL);
        $tenantId = $principal->tenantId;
        [$program, $tutelage] = $this->context->resolve($tenantId, $establishmentId, $data['program_id'], $data['academic_year_id']);
        $id = (string) new Ulid();
        $row = ['school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $data['level_id']];
        $this->access->assertWritable($principal, $perimeter, $row, 'tutelage_transmission', $id);
        $file = null;
        if ($data['kind'] === TransmissionRules::LISTS) {
            $records = $this->enrolled->records($tenantId, $establishmentId, $program, $tutelage, $data['level_id']);
            if ($records === []) {
                throw HttpException::conflict('Aucun inscrit validé à transmettre pour cette filière et cette année.');
            }
            $file = $this->exporter->render($tenantId, $establishmentId, TutelageExportTypes::KEY, $records, $data['format']);
            $count = $file->rows;
            $fingerprint = hash('sha256', $file->content);
        } else {
            if ($data['level_id'] === null || $data['period_id'] === null) {
                throw HttpException::validation(['period_id' => ['Niveau et période requis pour transmettre notes, relevés ou PV.']]);
            }
            if (!$this->deliberations->minutesSigned($tenantId, $establishmentId, $data['level_id'], $data['period_id'])) {
                throw HttpException::conflict('Notes, relevés et PV ne se transmettent qu\'après la signature du PV de délibération de la période (RG-DEL-3).');
            }
            $count = 1;
            $fingerprint = hash('sha256', json_encode([$data['kind'], $program->id, $data['level_id'], $data['period_id'], $tutelage->id], JSON_THROW_ON_ERROR));
        }
        $now = $this->clock->now();
        $values = $row + [
            'id' => $id, 'academic_year_id' => $tutelage->academicYearId, 'period_id' => $data['period_id'], 'tutelage_id' => $tutelage->id,
            'university_name' => $tutelage->universityName, 'transmission_kind' => $data['kind'], 'status' => TransmissionRules::SENT, 'items_count' => $count,
            'fingerprint' => $fingerprint, 'file_name' => $file?->filename, 'note' => $data['note'], 'sent_by' => $principal->userId, 'sent_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $values);
        $this->access->audit($principal, 'higher.tutelage.transmitted', 'tutelage_transmission', $id, null, $values);
        $result = ['transmission' => $values];
        if ($file !== null) {
            $result['file'] = ['filename' => $file->filename, 'format' => $file->format, 'content_type' => $file->contentType, 'rows' => $file->rows,
                'columns' => $file->columns, 'content_base64' => base64_encode($file->content)];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function answer(Principal $principal, string $establishmentId, string $id, string $status, ?string $note): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL);
        $row = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Transmission introuvable.');
        $this->access->assertReadable($perimeter, $row);
        $this->access->assertWritable($principal, $perimeter, $row, 'tutelage_transmission', $id);
        if ($row['status'] !== TransmissionRules::SENT) {
            throw HttpException::conflict('Réponse de la tutelle déjà enregistrée.');
        }
        $now = $this->clock->now();
        $changes = ['status' => $status, 'answered_at' => $now, 'answered_by' => $principal->userId, 'note' => $note ?? $row['note'], 'updated_at' => $now];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'higher.tutelage.answered', 'tutelage_transmission', $id, $row, $changes);

        return $changes + $row;
    }
}
