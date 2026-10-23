<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentSubtypes;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * SEUL lecteur du type d'établissement de l'application secondaire (D9, RG-SEC-3) : le type, lu sur l'établissement par le
 * contrat d'Organisation (`EstablishmentInfo::$subtype`) ou sur la copie faite à l'ouverture, ne sert qu'à choisir la clé du
 * profil de type `secondary.type_profiles.<code>` (paramètre de plateforme, héritable par client et établissement). Tout le
 * reste du code interroge le profil (cycles, enseignements, modules) et jamais le code de type.
 *
 * Règles : type absent, hors liste fermée (RG-SEC-1) ou établissement qui n'est pas du secondaire : 422 ; établissement inconnu :
 * 404. Un profil de type absent ou illisible donne un profil vide (noyau seulement), jamais une valeur inventée.
 */
final class ProfileResolver implements EstablishmentProfiles
{
    public const SETTING_PREFIX = 'secondary.type_profiles.';
    public const INVALID_TYPE = 'https://api.scholaris.cm/errors/establishment-subtype-invalid';

    public function __construct(
        private readonly EstablishmentDirectory $establishments,
        private readonly SettingsReader $settings,
        private readonly PdoProfileStore $store,
    ) {
    }

    /** @return list<string> codes de type connus (liste fermée du contrat d'Organisation) */
    public static function typeCodes(): array
    {
        return EstablishmentSubtypes::CODES;
    }

    public function forEstablishment(string $tenantId, string $establishmentId): EstablishmentProfile
    {
        $info = $this->establishments->find($tenantId, $establishmentId);
        if ($info === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }
        $stored = $this->store->find($tenantId, $establishmentId);
        if ($stored !== null) {
            return $this->fromStored($tenantId, $establishmentId, $stored);
        }
        $code = $info->subtype;
        if ($code === null || !in_array($code, EstablishmentSubtypes::allowedFor($info->type), true)) {
            throw self::invalidType();
        }

        return $this->typeProfile($tenantId, $establishmentId, $code);
    }

    /** Valeurs de départ du profil de type pour un établissement (avant ouverture) ; type inconnu : 422. */
    public function typeProfile(string $tenantId, string $establishmentId, string $code): EstablishmentProfile
    {
        if (!in_array($code, EstablishmentSubtypes::CODES, true)) {
            throw self::invalidType();
        }
        $raw = $this->settings->value($tenantId, $establishmentId, self::SETTING_PREFIX . $code, null);
        $data = is_array($raw) ? $raw : [];
        $modules = self::codes($data['modules'] ?? null, Modules::ALL);
        $ownership = isset($data['ownership']) && is_string($data['ownership']) && in_array($data['ownership'], Cycles::OWNERSHIPS, true) ? $data['ownership'] : null;
        $options = self::codes($data['ownership_options'] ?? null, Cycles::OWNERSHIPS);

        return new EstablishmentProfile(
            $tenantId,
            $establishmentId,
            $code,
            isset($data['version']) && is_int($data['version']) ? $data['version'] : 0,
            false,
            $ownership,
            $options === [] ? Cycles::OWNERSHIPS : $options,
            self::codes($data['cycles'] ?? null, Cycles::ALL),
            self::codes($data['teaching_kinds'] ?? null, Cycles::TEACHING_KINDS),
            self::codes($data['sections'] ?? null, Cycles::SECTIONS),
            $modules,
            [],
            $modules,
            isset($data['head_title']) && is_string($data['head_title']) && $data['head_title'] !== '' ? $data['head_title'] : null,
            isset($data['confirmation']) && is_string($data['confirmation']) ? $data['confirmation'] : 'to_confirm',
        );
    }

    /** @param array<string, mixed> $row ligne de se_establishment_profiles */
    private function fromStored(string $tenantId, string $establishmentId, array $row): EstablishmentProfile
    {
        $code = (string) ($row['subtype'] ?? '');
        $defaults = $this->typeProfile($tenantId, $establishmentId, $code);
        $ownership = (string) ($row['ownership'] ?? '');

        return new EstablishmentProfile(
            $tenantId,
            $establishmentId,
            $code,
            (int) ($row['profile_version'] ?? 0),
            true,
            $ownership === '' ? null : $ownership,
            $defaults->ownershipOptions,
            self::codes(self::json($row['cycles'] ?? null), Cycles::ALL),
            self::codes(self::json($row['teaching_kinds'] ?? null), Cycles::TEACHING_KINDS),
            self::codes(self::json($row['sections'] ?? null), Cycles::SECTIONS),
            self::codes(self::json($row['modules'] ?? null), Modules::ALL),
            self::codes(self::json($row['read_only_modules'] ?? null), Modules::ALL),
            $defaults->defaultModules,
            is_string($row['head_title'] ?? null) && $row['head_title'] !== '' ? $row['head_title'] : null,
            $defaults->confirmation,
        );
    }

    private static function invalidType(): HttpException
    {
        return new HttpException(422, 'Type d\'établissement invalide', 'Le type d\'établissement secondaire est absent ou inconnu : il doit être l\'un des 7 types de la liste fermée.', self::INVALID_TYPE);
    }

    private static function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : null;
    }

    /**
     * Garde les seuls codes connus, sans doublon, dans l'ordre de la liste de référence.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function codes(mixed $value, array $allowed): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = array_filter($value, static fn (mixed $item): bool => is_string($item));

        return array_values(array_intersect($allowed, $strings));
    }
}
