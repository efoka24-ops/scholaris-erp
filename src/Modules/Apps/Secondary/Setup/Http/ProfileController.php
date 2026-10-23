<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Profil effectif de l'établissement (US1, EX-SEC-01 à 03) : copie faite à l'ouverture, ou valeurs de départ du profil de type
 * avant l'ouverture (`stored = false`, « à confirmer »). État de chaque module : active, read_only, inactive, unavailable.
 */
final class ProfileController extends Controller
{
    public const READ = ['secondary.profile.read', 'secondary.profile.manage', 'secondary.settings.manage'];

    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly EstablishmentProfiles $profiles)
    {
        parent::__construct($responder, $access, $store);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);
        $profile = $this->profiles->forEstablishment($principal->tenantId, $establishmentId);

        return $this->json(self::resource($profile));
    }

    /** @return array<string, mixed> */
    public static function resource(EstablishmentProfile $profile): array
    {
        $modules = [];
        foreach (Modules::ALL as $module) {
            $modules[] = [
                'code' => $module,
                'state' => $profile->moduleState($module),
                'default_for_type' => in_array($module, $profile->defaultModules, true),
            ];
        }

        return [
            'establishment_id' => $profile->establishmentId,
            'subtype' => $profile->typeCode,
            'stored' => $profile->stored,
            'profile_version' => $profile->profileVersion,
            'confirmation' => $profile->confirmation,
            'ownership' => $profile->ownership,
            'ownership_options' => $profile->ownershipOptions,
            'cycles' => $profile->cycles,
            'teaching_kinds' => $profile->teachingKinds,
            'sections' => $profile->sections,
            'head_title' => $profile->headTitle,
            'modules' => $modules,
        ];
    }
}
