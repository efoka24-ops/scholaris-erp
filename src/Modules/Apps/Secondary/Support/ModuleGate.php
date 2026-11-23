<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Garde des modules activables (D8, D10, RG-SEC-3, EX-SEC-03, EX-SEC-04) pour l'établissement de la requête :
 *  - module actif dans le profil : la route répond ;
 *  - module coupé après usage (profil `read_only_modules`) : lectures permises, écritures 409 (rien n'est supprimé) ;
 *  - module jamais activé : 409 `module_not_enabled`, en lecture comme en écriture.
 * Le profil vient du contrat Setup (EstablishmentProfiles) : la garde ne lit jamais le type d'établissement.
 */
final class ModuleGate
{
    public const TYPE = 'https://api.scholaris.cm/errors/module-not-enabled';
    public const CODE = 'module_not_enabled';

    public function __construct(private readonly EstablishmentProfiles $profiles)
    {
    }

    public function isActive(string $tenantId, string $establishmentId, string $module): bool
    {
        self::known($module);

        return $this->profiles->forEstablishment($tenantId, $establishmentId)->isActive($module);
    }

    public function assertAvailable(string $tenantId, string $establishmentId, string $module, bool $write): void
    {
        self::known($module);
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        if ($profile->isActive($module)) {
            return;
        }
        if (!$write && $profile->isReadOnly($module)) {
            return;
        }
        $detail = $profile->isReadOnly($module)
            ? sprintf('Le module « %s » a été coupé : ses données restent consultables, aucune modification n\'est permise.', $module)
            : sprintf('Le module « %s » n\'est pas activé pour cet établissement.', $module);

        throw new HttpException(409, 'Module non activé', $detail, self::TYPE, extensions: ['code' => self::CODE, 'module' => $module]);
    }

    private static function known(string $module): void
    {
        if (!in_array($module, Modules::ALL, true)) {
            throw new \LogicException('Module inconnu : ' . $module);
        }
    }
}
