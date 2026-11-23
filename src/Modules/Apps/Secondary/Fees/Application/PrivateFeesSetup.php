<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;

/**
 * Préparation des frais du privé [fees_private] (T830) : pose les préréglages de Billing (règle d'affectation `kind_priority`,
 * ordre des natures, ordre des enfants, relances graduées) au niveau de l'établissement, SANS jamais écraser une valeur déjà posée
 * (SettingsWriter::presetIfAbsent, chaque pose est tracée par le socle). Les grilles par niveau, série ou spécialité se saisissent
 * ensuite par les routes du socle `/fee-items` et `/fee-schedules` (`level` = niveau, `track` = série ou spécialité).
 */
final class PrivateFeesSetup
{
    public function __construct(private readonly SettingsWriter $writer)
    {
    }

    /** @return array<string, array{value: mixed, applied: bool}> clé => valeur proposée, et vrai si elle vient d'être posée */
    public function apply(string $tenantId, string $establishmentId, string $actorId): array
    {
        $result = [];
        foreach (FeesDefaults::PRIVATE_PRESETS as $key => $value) {
            $result[$key] = ['value' => $value, 'applied' => $this->writer->presetIfAbsent($tenantId, $establishmentId, $key, $value, $actorId)];
        }

        return $result;
    }
}
