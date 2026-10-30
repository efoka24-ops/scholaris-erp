<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;

/**
 * Termes à éviter dans les observations et les carnets (US11, MQ-08) : la liste vient de `nursery.dev.discouraged_terms` (vide
 * par défaut : la plateforme n'en impose aucun). Une valeur de la liste, comme mot entier et sans tenir compte de la casse, bloque
 * la validation avec un message qui nomme les termes trouvés.
 */
final class TermsGuard
{
    public function __construct(private readonly NurserySettings $settings)
    {
    }

    /** @return list<string> termes de la liste présents dans le texte */
    public function found(string $tenantId, string $establishmentId, string $text): array
    {
        $found = [];
        foreach ($this->settings->list($tenantId, $establishmentId, NurserySettings::DEV_DISCOURAGED_TERMS) as $term) {
            $term = trim($term);
            if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
                $found[] = $term;
            }
        }

        return $found;
    }

    /** @throws HttpException 422 si un terme à éviter est présent */
    public function assertClean(string $tenantId, string $establishmentId, string $field, string $text): void
    {
        $found = $this->found($tenantId, $establishmentId, $text);
        if ($found !== []) {
            throw HttpException::validation([$field => ['Terme à éviter : ' . implode(', ', $found) . '. Reformulez en termes positifs.']]);
        }
    }
}
