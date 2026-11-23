<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;

/**
 * Paramètres des bulletins du secondaire (SS5) : types de document par nature de période (`assessment.report_card_types`, préréglé
 * à la demande sans écraser une valeur déjà fixée), groupes de matières et en-tête (`secondary.report_card.*`, lus ici, saisis par
 * les paramètres de l'établissement).
 */
final class ReportCardSettings
{
    public const TYPES = 'assessment.report_card_types';
    public const READ = ['secondary.council.prepare', 'secondary.council.preside', 'secondary.reportcard.read', 'secondary.reportcard.validate', 'secondary.settings.read', 'secondary.settings.manage'];
    public const MANAGE = ['secondary.settings.manage', 'secondary.council.preside'];

    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SettingsWriter $writer,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->values($principal->tenantId, $establishmentId);
    }

    /** @return array<string, mixed> */
    public function preset(Principal $principal, string $establishmentId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_report_card_settings');
        $applied = $this->writer->presetIfAbsent($principal->tenantId, $establishmentId, self::TYPES, Defaults::REPORT_CARD_TYPES, $principal->userId);
        $this->access->audit($principal, 'secondary.reportcard.settings.preset', 'setting', self::TYPES, null, ['applied' => $applied]);

        return ['applied' => $applied] + $this->values($principal->tenantId, $establishmentId);
    }

    /** @return array<string, mixed> */
    private function values(string $tenantId, string $establishmentId): array
    {
        return [
            'report_card_types' => $this->settings->value($tenantId, $establishmentId, self::TYPES, null),
            'expected_types' => Defaults::REPORT_CARD_TYPES,
            'subject_groups' => $this->settings->value($tenantId, $establishmentId, ReportCardBuilder::GROUPS, null),
            'letterhead' => $this->settings->value($tenantId, $establishmentId, ReportCardBuilder::LETTERHEAD, null),
        ];
    }
}
