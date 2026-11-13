<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;

/**
 * Activation et coupure d'un module pour un établissement OUVERT (profil copié), avec motif obligatoire et journal :
 *  - activer : le module doit être de la liste fermée et son cycle offert par le profil (second cycle, orientation : 422) ;
 *  - couper : le module passe en lecture seule (ses données restent consultables, rien n'est supprimé, D10, EX-SEC-04) ;
 *  - réactiver un module en lecture seule le rend de nouveau modifiable.
 * Un établissement non ouvert répond 409 (l'ouverture, T815, copie d'abord le profil de type). Les routes de l'assistant
 * (T836 à T838) appelleront ce service.
 */
final class ModuleSwitch
{
    public function __construct(private readonly EstablishmentProfiles $profiles, private readonly PdoProfileStore $store)
    {
    }

    public function enable(string $tenantId, string $establishmentId, string $module, string $reason, string $by): EstablishmentProfile
    {
        $profile = $this->opened($tenantId, $establishmentId, $module, $reason);
        if ($profile->isActive($module)) {
            return $profile;
        }
        if (!$profile->canActivate($module)) {
            throw HttpException::validation(['module' => ['Ce module exige un cycle que le profil de l\'établissement n\'offre pas.']]);
        }
        $modules = array_values(array_intersect(Modules::ALL, [...$profile->modules, $module]));
        $readOnly = array_values(array_diff($profile->readOnlyModules, [$module]));
        $this->store->saveModules($tenantId, $establishmentId, $modules, $readOnly, self::state($profile), $reason, $by);

        return $this->profiles->forEstablishment($tenantId, $establishmentId);
    }

    public function disable(string $tenantId, string $establishmentId, string $module, string $reason, string $by): EstablishmentProfile
    {
        $profile = $this->opened($tenantId, $establishmentId, $module, $reason);
        if (!$profile->isActive($module)) {
            return $profile;
        }
        $modules = array_values(array_diff($profile->modules, [$module]));
        $readOnly = array_values(array_intersect(Modules::ALL, [...$profile->readOnlyModules, $module]));
        $this->store->saveModules($tenantId, $establishmentId, $modules, $readOnly, self::state($profile), $reason, $by);

        return $this->profiles->forEstablishment($tenantId, $establishmentId);
    }

    private function opened(string $tenantId, string $establishmentId, string $module, string $reason): EstablishmentProfile
    {
        $errors = [];
        if (!in_array($module, Modules::ALL, true)) {
            $errors['module'] = ['Module inconnu : valeurs possibles ' . implode(', ', Modules::ALL) . '.'];
        }
        $trimmed = trim($reason);
        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            $errors['reason'] = ['Motif obligatoire (255 caractères au plus).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        if (!$profile->stored) {
            throw HttpException::conflict('Le profil de l\'établissement n\'est pas encore ouvert : ouvrez d\'abord l\'application pour cet établissement.');
        }

        return $profile;
    }

    /** @return array{modules: list<string>, read_only_modules: list<string>} */
    private static function state(EstablishmentProfile $profile): array
    {
        return ['modules' => $profile->modules, 'read_only_modules' => $profile->readOnlyModules];
    }
}
