<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Application;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\CarteScolaireDefaults;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\IdentityKey;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres `secondary.carte_scolaire.*` (héritage plateforme, client, établissement) ; valeurs de départ « à confirmer ». */
final class CarteScolaireSettings
{
    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** Expression complète du matricule ; un paramètre illisible retombe sur la valeur de départ. */
    public function nationalIdRegex(string $tenantId, string $establishmentId): string
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.national_id_pattern', null);
        if (is_string($raw) && $raw !== '' && strlen($raw) <= 200 && !str_contains($raw, '/')) {
            $candidate = '/^(?:' . $raw . ')$/';
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($candidate, '') !== false;
            } finally {
                restore_error_handler();
            }
            if ($valid) {
                return $candidate;
            }
        }

        return '/^(?:' . CarteScolaireDefaults::NATIONAL_ID_PATTERN . ')$/';
    }

    public function pattern(string $tenantId, string $establishmentId): string
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.national_id_pattern', null);

        return is_string($raw) && $raw !== '' ? $raw : CarteScolaireDefaults::NATIONAL_ID_PATTERN;
    }

    public function delimiter(string $tenantId, string $establishmentId): string
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.csv_delimiter', null);

        return is_string($raw) && in_array($raw, [';', ',', "\t", '|'], true) ? $raw : CarteScolaireDefaults::DELIMITER;
    }

    /**
     * @param array<string, string> $default
     * @return array<string, string> champ => en-tête attendu
     */
    public function columns(string $tenantId, string $establishmentId, string $kind, array $default): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.columns.' . $kind, null);
        $columns = $default;
        if (is_array($raw)) {
            foreach ($raw as $field => $header) {
                if (is_string($field) && array_key_exists($field, $default) && is_string($header) && $header !== '') {
                    $columns[$field] = $header;
                }
            }
        }

        return $columns;
    }

    /** @return array<string, string> libellé normalisé => contribution ou exam_fee */
    public function paymentKinds(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.payment_kinds', null);
        $map = is_array($raw) ? $raw : CarteScolaireDefaults::PAYMENT_KINDS;
        $clean = [];
        foreach ($map as $label => $kind) {
            if (is_string($label) && in_array($kind, ['contribution', 'exam_fee'], true)) {
                $clean[IdentityKey::text($label)] = $kind;
            }
        }

        return $clean;
    }

    /** @return list<string> niveaux d'entrée normalisés */
    public function entryLevels(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.entry_levels', null);
        $levels = is_array($raw) ? array_filter($raw, 'is_string') : CarteScolaireDefaults::ENTRY_LEVELS;

        return array_values(array_unique(array_map(static fn (string $l): string => IdentityKey::name($l), $levels)));
    }
}
