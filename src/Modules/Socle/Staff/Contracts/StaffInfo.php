<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Membre du personnel vu par les autres modules. */
final class StaffInfo
{
    public const PERMANENT = 'permanent';
    public const VACATAIRE = 'vacataire';
    public const INVITE = 'invite';

    public function __construct(
        public readonly string $personId,
        public readonly string $tenantId,
        /** permanent, vacataire ou invite */
        public readonly string $kind,
        public readonly ?string $grade,
        /** active, suspended, left */
        public readonly string $status,
        /** identifiant de la fiche du personnel (staff_members.id), attendu par TaughtHoursRecorder */
        public readonly ?string $staffId = null,
    ) {
    }
}
