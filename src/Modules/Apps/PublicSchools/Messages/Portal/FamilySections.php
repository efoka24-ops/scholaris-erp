<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Portal;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\MessageClock;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Base des sections du portail famille des écoles publiques (T641, EX-COM-04) : établissements où l'enfant est inscrit, accès plein
 * (consentement du garant) et fenêtre de consultation `public_schools.portal.window_days` (30 jours par défaut). La section « reçus
 * APEE » est fournie par le sous-module Apee, sous la même clé de liste `portals.sections.public_schools`.
 */
abstract class FamilySections
{
    private const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        protected readonly EnrollmentDirectory $enrollments,
        protected readonly WriteGate $gate,
        protected readonly SettingsReader $settings,
        protected readonly MessageClock $clock,
    ) {
    }

    /** @return list<string> */
    final protected function establishmentsOf(PortalSectionContext $context): array
    {
        if (!$context->full || !$this->gate->allowsReads($context->tenantId)) {
            return [];
        }
        $establishments = [];
        foreach ($this->activeEnrollments($context) as $enrollment) {
            $establishments[$enrollment->establishmentId] = $enrollment->establishmentId;
        }

        return array_values($establishments);
    }

    /** @return list<EnrollmentInfo> */
    final protected function activeEnrollments(PortalSectionContext $context): array
    {
        if (!$context->full || !$this->gate->allowsReads($context->tenantId)) {
            return [];
        }

        return array_values(array_filter(
            $this->enrollments->forPerson($context->tenantId, $context->subjectPersonId),
            static fn (EnrollmentInfo $enrollment): bool => $enrollment->tenantId === $context->tenantId
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true),
        ));
    }

    final protected function windowDays(string $tenantId): int
    {
        $value = $this->settings->value($tenantId, null, 'public_schools.portal.window_days', self::DEFAULT_WINDOW_DAYS);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : self::DEFAULT_WINDOW_DAYS;
    }

    final protected function since(string $tenantId): DateTimeImmutable
    {
        return $this->clock->moment()->setTime(0, 0)->modify(sprintf('-%d days', $this->windowDays($tenantId) - 1));
    }
}
