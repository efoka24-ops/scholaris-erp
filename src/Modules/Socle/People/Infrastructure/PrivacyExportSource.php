<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;
use Throwable;

/**
 * Source d'export complet du module People (tenant:export). Liste blanche de colonnes. Les données de santé n'apparaissent
 * QUE dans cet export (déchiffrées pour être lisibles par le responsable du client) : ni dans les demandes de personne
 * concernée, ni dans aucune autre sortie.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function __construct(ConnectionFactory $connections, private readonly HealthCipher $cipher)
    {
        parent::__construct($connections);
    }

    public function name(): string
    {
        return 'people';
    }

    protected function tables(): array
    {
        return [
            'people' => self::table('id tenant_id first_name last_name phone email locale created_at birth_date birth_place gender nationality photo_path national_id updated_at'),
            'person_contacts' => self::table('id tenant_id person_id kind label value created_at'),
            'person_identifiers' => self::table('id tenant_id person_id kind establishment_id value created_at'),
            'person_relations' => self::table('id tenant_id person_id related_person_id kind created_by created_at'),
            'person_documents' => self::table('id tenant_id person_id kind storage_path size_bytes mime_type expires_on uploaded_by created_at'),
            'consents' => self::table('id tenant_id person_id purpose granted_at granted_by revoked_at revoked_by'),
            'schooling_history' => self::table('id tenant_id person_id enrollment_id school_name level_label year_label outcome source created_at'),
            'enrollments' => self::table('id tenant_id person_id establishment_id structure_id academic_year_id status internal_number completeness documents_due_on created_by validated_at created_at updated_at'),
            'health_records' => self::table('id tenant_id person_id payload_enc updated_by created_at updated_at', ['row' => $this->decryptHealth(...)]),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decryptHealth(array $row): array
    {
        $health = null;
        try {
            $decoded = json_decode($this->cipher->decrypt((string) $row['payload_enc']), true);
            $health = is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            $health = null;
        }
        unset($row['payload_enc']);
        $row['health'] = $health;

        return $row;
    }
}
