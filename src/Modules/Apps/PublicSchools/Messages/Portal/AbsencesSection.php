<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Portal;

use Scholaris\Modules\Apps\PublicSchools\Messages\Application\MessageClock;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/** Section « absences » du portail famille : synthèse d'assiduité de l'enfant sur la fenêtre de consultation, par école. */
final class AbsencesSection extends FamilySections implements PortalSection
{
    public function __construct(
        EnrollmentDirectory $enrollments,
        WriteGate $gate,
        SettingsReader $settings,
        MessageClock $clock,
        private readonly AttendanceSummaryProvider $attendance,
    ) {
        parent::__construct($enrollments, $gate, $settings, $clock);
    }

    public function key(): string
    {
        return 'public_schools_absences';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        $establishments = $this->establishmentsOf($context);
        if ($establishments === []) {
            return null;
        }
        $from = $this->since($context->tenantId)->format('Y-m-d');
        $to = $this->clock->today();
        $schools = [];
        foreach ($establishments as $establishmentId) {
            $summary = $this->attendance->forLearner($context->tenantId, $establishmentId, $context->subjectPersonId, $from, $to);
            $schools[] = [
                'establishment_id' => $establishmentId,
                'sessions' => $summary->sessions,
                'absences' => $summary->absences,
                'unjustified_absences' => $summary->unjustifiedAbsences,
                'lates' => $summary->lates,
            ];
        }

        return ['from' => $from, 'to' => $to, 'schools' => $schools];
    }
}
