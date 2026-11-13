<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Ouverture de l'application pour un établissement (T815, EX-SEC-01, 02) et changements de profil après l'ouverture (T838,
 * EX-SEC-04, Q21, Q33). L'ouverture COPIE le profil de type (valeurs ajustées dans les limites du profil) et écrit la ligne
 * `opened` ; tout changement ultérieur exige un motif et écrit une ligne de journal. Rien n'est jamais supprimé : un cycle, un
 * enseignement ou une section qui porte des classes ne se retire pas (409) ; un module coupé passe en lecture seule.
 * Le type d'établissement n'est jamais testé ici : on n'interroge que le profil (D9) ; un champ que le profil laisse vide
 * (propriété, cycles, enseignements, vocabulaire) doit être choisi par l'administrateur (422 sinon).
 */
final class ProfileService
{
    public const MANAGE = ['secondary.profile.manage', 'secondary.settings.manage'];
    public const READ = ['secondary.profile.read', 'secondary.profile.manage', 'secondary.settings.manage', 'secondary.settings.read'];
    public const KINDS = ['subtype', 'ownership', 'cycles', 'teaching_kinds', 'sections', 'head_title', 'modules', 'official_code'];

    public function __construct(
        private readonly ProfileResolver $resolver,
        private readonly PdoProfileStore $profiles,
        private readonly Access $access,
        private readonly Store $store,
    ) {
    }

    /**
     * @param array<string, mixed> $input ownership, cycles, teaching_kinds, sections, modules, head_title, official_code, reason
     */
    public function open(Principal $principal, string $establishmentId, array $input): EstablishmentProfile
    {
        $this->guard($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $profile = $this->resolver->forEstablishment($tenantId, $establishmentId);
        if ($profile->stored) {
            throw HttpException::conflict('L\'application est déjà ouverte pour cet établissement : utilisez le changement de profil (motif obligatoire).');
        }
        $errors = [];
        $ownership = self::pick($input, 'ownership', $profile->ownership);
        if ($ownership === null) {
            $errors['ownership'] = ['Choisissez la propriété de l\'établissement (public, privé laïc ou privé confessionnel).'];
        } elseif (!in_array($ownership, $profile->ownershipOptions, true)) {
            $errors['ownership'] = ['Valeurs possibles pour ce type : ' . implode(', ', $profile->ownershipOptions) . '.'];
        }
        $cycles = self::bounded($input, 'cycles', $profile->cycles, Cycles::ALL, $errors, 'Choisissez au moins un cycle.');
        $teaching = self::bounded($input, 'teaching_kinds', $profile->teachingKinds, Cycles::TEACHING_KINDS, $errors, 'Choisissez au moins un enseignement (général ou technique).');
        $sections = self::bounded($input, 'sections', $profile->sections === [] ? [Cycles::SECTIONS[0]] : $profile->sections, Cycles::SECTIONS, $errors, 'Choisissez au moins une section.', true);
        $headTitle = self::pick($input, 'head_title', $profile->headTitle);
        if ($headTitle === null || preg_match('/^[a-z_]{2,20}$/', $headTitle) !== 1) {
            $errors['head_title'] = ['Choisissez le titre du chef d\'établissement (proviseur, principal…).'];
        }
        $officialCode = self::optionalText($input, 'official_code', 40, $errors);
        $modules = $profile->modules;
        if (array_key_exists('modules', $input)) {
            $modules = self::list($input['modules'], Modules::ALL, 'modules', $errors) ?? [];
        }
        $refused = array_values(array_filter($modules, static fn (string $m): bool => isset(Modules::REQUIRED_CYCLE[$m]) && !in_array(Modules::REQUIRED_CYCLE[$m], $cycles, true)));
        if ($refused !== [] && array_key_exists('modules', $input)) {
            $errors['modules'] = ['Module(s) sans le cycle requis : ' . implode(', ', $refused) . '.'];
        }
        $modules = array_values(array_intersect(Modules::ALL, array_diff($modules, $refused)));
        $reason = self::reason($input, 'Ouverture de l\'application secondaire', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $copy = new EstablishmentProfile(
            $tenantId,
            $establishmentId,
            $profile->typeCode,
            $profile->profileVersion,
            true,
            $ownership,
            $profile->ownershipOptions,
            $cycles,
            $teaching,
            $sections,
            $modules,
            [],
            $profile->defaultModules,
            $headTitle,
            $profile->confirmation,
        );
        $this->profiles->open($copy, (string) $ownership, (string) $reason, $principal->userId, $officialCode);
        $this->access->audit($principal, 'secondary.profile.opened', 'se_establishment_profile', $establishmentId, null, ['subtype' => $profile->typeCode, 'modules' => $modules]);

        return $this->resolver->forEstablishment($tenantId, $establishmentId);
    }

    /** @param array<string, mixed> $input kind, value, reason */
    public function change(Principal $principal, string $establishmentId, array $input): EstablishmentProfile
    {
        $this->guard($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $errors = [];
        $kind = is_string($input['kind'] ?? null) && in_array($input['kind'], self::KINDS, true) ? $input['kind'] : null;
        if ($kind === null) {
            $errors['kind'] = ['Valeurs possibles : ' . implode(', ', self::KINDS) . '.'];
        }
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['kind', 'value', 'reason'], true)) {
                $errors[(string) $field] = ['Champ non reconnu.'];
            }
        }
        $reason = self::reason($input, null, $errors);
        if (!array_key_exists('value', $input)) {
            $errors['value'] = ['Ce champ est obligatoire.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $profile = $this->resolver->forEstablishment($tenantId, $establishmentId);
        if (!$profile->stored) {
            throw HttpException::conflict('Le profil de l\'établissement n\'est pas encore ouvert : ouvrez d\'abord l\'application.');
        }
        $value = $input['value'];
        $previous = self::snapshot($profile);
        $values = match ($kind) {
            'subtype' => $this->retype($profile, $value),
            'ownership' => ['ownership' => self::enumValue($value, Cycles::OWNERSHIPS, 'value')],
            'head_title' => ['head_title' => self::headTitle($value)],
            'official_code' => ['official_code' => self::code($value)],
            'cycles' => $this->cycles($profile, $value),
            'teaching_kinds' => ['teaching_kinds' => $this->withoutClasses($profile, $value, Cycles::TEACHING_KINDS, $profile->teachingKinds, 'teaching_kind')],
            'sections' => ['sections' => $this->withoutClasses($profile, $value, Cycles::SECTIONS, $profile->sections, 'section_code')],
            default => $this->modules($profile, $value),
        };
        $this->profiles->saveProfile($tenantId, $establishmentId, (string) $kind, $values, $previous, (string) $reason, $principal->userId);
        $this->access->audit($principal, 'secondary.profile.changed', 'se_establishment_profile', $establishmentId, $previous, ['kind' => $kind] + $values);

        return $this->resolver->forEstablishment($tenantId, $establishmentId);
    }

    /** @return list<array<string, mixed>> journal complet, du plus ancien au plus récent */
    public function changes(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return array_map(self::changeResource(...), $this->profiles->changes($principal->tenantId, $establishmentId));
    }

    /**
     * @param array<string, mixed> $row ligne de se_profile_changes
     * @return array<string, mixed>
     */
    public static function changeResource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'change_kind' => (string) $row['change_kind'],
            'previous_value' => is_string($row['previous_value']) ? json_decode($row['previous_value'], true) : null,
            'new_value' => json_decode((string) $row['new_value'], true),
            'reason' => (string) $row['reason'],
            'changed_by' => (string) $row['changed_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function guard(Principal $principal, string $establishmentId): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_establishment_profile');
    }

    /**
     * Nouveau type (Q21) : on garde tout et on AJOUTE ce que le nouveau type apporte (cycles, enseignements, modules par défaut).
     *
     * @return array<string, scalar|list<string>|null>
     */
    private function retype(EstablishmentProfile $profile, mixed $value): array
    {
        $code = self::enumValue($value, ProfileResolver::typeCodes(), 'value');
        $target = $this->resolver->typeProfile($profile->tenantId, $profile->establishmentId, $code);
        $cycles = array_values(array_intersect(Cycles::ALL, [...$profile->cycles, ...$target->cycles]));
        $added = array_filter($target->modules, static fn (string $m): bool => !isset(Modules::REQUIRED_CYCLE[$m]) || in_array(Modules::REQUIRED_CYCLE[$m], $cycles, true));
        $modules = array_values(array_intersect(Modules::ALL, [...$profile->modules, ...$added]));

        return [
            'subtype' => $code,
            'profile_version' => $target->profileVersion,
            'cycles' => $cycles,
            'teaching_kinds' => array_values(array_intersect(Cycles::TEACHING_KINDS, [...$profile->teachingKinds, ...$target->teachingKinds])),
            'modules' => $modules,
            'read_only_modules' => array_values(array_diff($profile->readOnlyModules, $modules)),
            'head_title' => $target->headTitle ?? $profile->headTitle,
        ];
    }

    /** @return array<string, list<string>> */
    private function cycles(EstablishmentProfile $profile, mixed $value): array
    {
        $cycles = $this->withoutClasses($profile, $value, Cycles::ALL, $profile->cycles, 'cycle_code');
        $cut = array_values(array_filter($profile->modules, static fn (string $m): bool => isset(Modules::REQUIRED_CYCLE[$m]) && !in_array(Modules::REQUIRED_CYCLE[$m], $cycles, true)));

        return [
            'cycles' => $cycles,
            'modules' => array_values(array_diff($profile->modules, $cut)),
            'read_only_modules' => array_values(array_intersect(Modules::ALL, [...$profile->readOnlyModules, ...$cut])),
        ];
    }

    /** @return array<string, list<string>> */
    private function modules(EstablishmentProfile $profile, mixed $value): array
    {
        $errors = [];
        $wanted = self::list($value, Modules::ALL, 'value', $errors) ?? [];
        foreach ($wanted as $module) {
            if (!$profile->isActive($module) && !$profile->canActivate($module)) {
                $errors['value'][] = 'Le module ' . $module . ' exige un cycle que le profil n\'offre pas.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $cut = array_diff($profile->modules, $wanted);

        return [
            'modules' => array_values(array_intersect(Modules::ALL, $wanted)),
            'read_only_modules' => array_values(array_intersect(Modules::ALL, array_diff([...$profile->readOnlyModules, ...$cut], $wanted))),
        ];
    }

    /**
     * Nouvelle liste ; une valeur retirée qui porte des classes est refusée (409, rien n'est supprimé).
     *
     * @param list<string> $allowed
     * @param list<string> $current
     * @return list<string>
     */
    private function withoutClasses(EstablishmentProfile $profile, mixed $value, array $allowed, array $current, string $column): array
    {
        $errors = [];
        $list = self::list($value, $allowed, 'value', $errors) ?? [];
        if ($list === [] && $errors === []) {
            $errors['value'] = ['Au moins une valeur est obligatoire.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        foreach (array_diff($current, $list) as $removed) {
            if ($this->store->count('se_class_profiles', $profile->tenantId, $profile->establishmentId, [$column => $removed]) > 0) {
                throw HttpException::conflict('« ' . $removed . ' » porte déjà des classes : rien n\'est supprimé, retirez ou déplacez d\'abord ces classes.');
            }
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private static function snapshot(EstablishmentProfile $profile): array
    {
        return [
            'subtype' => $profile->typeCode, 'ownership' => $profile->ownership, 'cycles' => $profile->cycles, 'teaching_kinds' => $profile->teachingKinds,
            'sections' => $profile->sections, 'modules' => $profile->modules, 'read_only_modules' => $profile->readOnlyModules, 'head_title' => $profile->headTitle,
        ];
    }

    /** @param array<string, mixed> $input */
    private static function pick(array $input, string $field, ?string $default): ?string
    {
        $value = $input[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Liste choisie dans les limites du profil (si le profil en propose) ou dans la liste fermée (profil vide).
     *
     * @param array<string, mixed> $input
     * @param list<string> $profileValues
     * @param list<string> $closed
     * @param array<string, list<string>> $errors
     * @return list<string>
     */
    private static function bounded(array $input, string $field, array $profileValues, array $closed, array &$errors, string $emptyMessage, bool $free = false): array
    {
        $values = $profileValues;
        if (array_key_exists($field, $input)) {
            $values = self::list($input[$field], $free || $profileValues === [] ? $closed : $profileValues, $field, $errors) ?? [];
        }
        if ($values === [] && !isset($errors[$field])) {
            $errors[$field] = [$emptyMessage];
        }

        return $values;
    }

    /**
     * @param list<string> $allowed
     * @param array<string, list<string>> $errors
     * @return list<string>|null
     */
    private static function list(mixed $value, array $allowed, string $field, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[$field][] = 'Liste attendue parmi : ' . implode(', ', $allowed) . '.';

            return null;
        }
        $unknown = array_filter($value, static fn (mixed $v): bool => !is_string($v) || !in_array($v, $allowed, true));
        if ($unknown !== []) {
            $errors[$field][] = 'Valeurs possibles : ' . implode(', ', $allowed) . '.';

            return null;
        }

        return array_values(array_intersect($allowed, $value));
    }

    /** @param list<string> $allowed */
    private static function enumValue(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw HttpException::validation([$field => ['Valeurs possibles : ' . implode(', ', $allowed) . '.']]);
        }

        return $value;
    }

    private static function headTitle(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z_]{2,20}$/', $value) !== 1) {
            throw HttpException::validation(['value' => ['Titre attendu (proviseur, principal…).']]);
        }

        return $value;
    }

    private static function code(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 40) {
            throw HttpException::validation(['value' => ['Code officiel de 40 caractères au plus.']]);
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function optionalText(array $input, string $field, int $max, array &$errors): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            $errors[$field] = [sprintf('Texte de %d caractères au plus.', $max)];

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function reason(array $input, ?string $default, array &$errors): ?string
    {
        $value = $input['reason'] ?? null;
        if ($value === null && $default !== null) {
            return $default;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 255) {
            $errors['reason'] = ['Motif obligatoire (255 caractères au plus).'];

            return null;
        }

        return trim($value);
    }
}
