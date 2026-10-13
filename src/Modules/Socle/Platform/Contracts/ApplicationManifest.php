<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Contracts;

use InvalidArgumentException;

/**
 * Manifeste d'une application spécialisée, déclaré par l'application dans SON definitions.php sous la clé de conteneur
 * `platform.applications.<clé>` (une clé par application : le chargeur fusionne les definitions.php par array_merge,
 * une liaison unique partagée serait écrasée). La valeur est un ApplicationManifest ou une liste de manifestes.
 *
 * Le manifeste fait foi pour ce que le code sait (cible, modules du socle requis, version, libellé) ; le catalogue en base
 * (table platform_applications, amorcée par migration) fait foi pour le commercial (statut, tarif, essai, délai de grâce),
 * les valeurs ci-dessous ne servant que de valeurs par défaut quand la base n'a pas encore de ligne pour l'application.
 */
final class ApplicationManifest
{
    public const AVAILABLE = 'available';
    public const COMING_SOON = 'coming_soon';
    public const RETIRED = 'retired';
    public const STATUSES = [self::AVAILABLE, self::COMING_SOON, self::RETIRED];
    public const TARGETS = ['higher', 'secondary', 'primary', 'nursery'];

    /** @param list<string> $requiredModules modules du socle à activer pour les établissements de la cible */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        /** type d'établissement desservi : higher, secondary, primary, nursery */
        public readonly string $target,
        public readonly string $status,
        public readonly string $version,
        public readonly array $requiredModules,
        /** tarif mensuel par défaut, entier en XAF (ex. 60000) */
        public readonly int $monthlyPriceXaf = 60000,
        public readonly int $trialDays = 90,
        public readonly int $graceDays = 15,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,19}$/', $key) !== 1) {
            throw new InvalidArgumentException('Clé d\'application invalide : ' . $key);
        }
        if (!in_array($target, self::TARGETS, true) || !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Cible ou statut d\'application invalide pour ' . $key);
        }
        if ($monthlyPriceXaf < 0 || $trialDays < 0 || $graceDays < 0) {
            throw new InvalidArgumentException('Tarif, essai et délai de grâce sont des entiers positifs.');
        }
    }
}
