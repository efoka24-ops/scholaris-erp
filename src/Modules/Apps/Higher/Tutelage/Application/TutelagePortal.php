<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageStore;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Accès en lecture de la tutelle (R22, EX-TUT-04, Could) : désactivé tant que `higher.tutelage.portal_enabled` est faux (404,
 * decisions R4) ; limité aux filières du périmètre du compte ; chaque consultation est journalisée (hi_tutelage_consultations
 * et journal d'audit).
 */
final class TutelagePortal
{
    public const TABLE = 'hi_tutelage_consultations';

    public function __construct(
        private readonly TutelageStore $store,
        private readonly TutelageAccess $access,
        private readonly TutelageContext $context,
        private readonly EnrolledList $enrolled,
        private readonly SettingsReader $settings,
        private readonly TutelageClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function enrolments(Principal $principal, string $establishmentId, string $programId, string $academicYearId): array
    {
        if ($this->settings->value($principal->tenantId, $establishmentId, TutelagePermissions::SETTING_PORTAL, false) !== true) {
            throw HttpException::notFound('Accès de la tutelle non ouvert.');
        }
        $perimeter = $this->access->require($principal, $establishmentId, [TutelagePermissions::EXTERNAL_READ]);
        $program = $this->context->program($principal->tenantId, $establishmentId, $programId);
        $this->access->assertReadable($perimeter, ['school_id' => null, 'department_id' => null, 'program_id' => $program->id]);
        [, $tutelage] = $this->context->resolve($principal->tenantId, $establishmentId, $programId, $academicYearId);
        $records = $this->enrolled->records($principal->tenantId, $establishmentId, $program, $tutelage, null);
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'user_id' => $principal->userId, 'program_id' => $programId, 'academic_year_id' => $academicYearId,
            'resource_kind' => 'enrolments', 'rows_count' => count($records), 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'higher.tutelage.consulted', 'program', $programId, null, ['academic_year_id' => $academicYearId, 'rows' => count($records)]);

        return ['convention' => $this->context->convention($program, $tutelage), 'data' => $records];
    }
}
