<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilDraft;

/** Lecture et validation du corps d'une inscription d'élève (API-02). Aucun champ APEE n'existe (RG-ELV-1) : un champ inconnu est refusé. */
final class PupilInput
{
    private const FIELDS = [
        'last_name', 'first_name', 'birth_date', 'sex', 'birth_act_ref', 'client_uuid', 'class_id', 'guardians', 'confirm_different', 'override_reason',
    ];
    private const KINDS = ['parent', 'guardian', 'financial_guarantor', 'authorized_pickup', 'emergency'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{draft: PupilDraft, class_id: ?string, guardians: list<array{kind: string, person_id?: string, identity?: array<string, mixed>}>, confirm_different: bool, override_reason: ?string}
     */
    public static function registration(array $body): array
    {
        $errors = [];
        foreach (array_diff(array_keys($body), self::FIELDS) as $unknown) {
            $errors[(string) $unknown][] = 'Champ non autorisé.';
        }
        $last = self::name($body['last_name'] ?? null, 'last_name', $errors);
        $first = self::name($body['first_name'] ?? null, 'first_name', $errors);
        $birth = $body['birth_date'] ?? null;
        $parsed = is_string($birth) ? DateTimeImmutable::createFromFormat('!Y-m-d', $birth) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $birth || $parsed > new DateTimeImmutable('today')) {
            $errors['birth_date'][] = 'Date de naissance AAAA-MM-JJ, passée, requise.';
        }
        $sex = $body['sex'] ?? null;
        if ($sex !== 'F' && $sex !== 'M') {
            $errors['sex'][] = 'Sexe attendu : F ou M.';
        }
        $act = $body['birth_act_ref'] ?? null;
        if ($act !== null && $act !== '' && (!is_string($act) || mb_strlen(trim($act)) > 80)) {
            $errors['birth_act_ref'][] = 'Référence de 80 caractères au plus.';
        }
        $uuid = $body['client_uuid'] ?? null;
        if ($uuid !== null && (!is_string($uuid) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1)) {
            $errors['client_uuid'][] = 'Identifiant client UUID attendu.';
        }
        $class = $body['class_id'] ?? null;
        if ($class !== null && (!is_string($class) || $class === '' || mb_strlen($class) > 26)) {
            $errors['class_id'][] = 'Identifiant de classe invalide.';
        }
        $confirm = $body['confirm_different'] ?? false;
        if (!is_bool($confirm)) {
            $errors['confirm_different'][] = 'Valeur booléenne attendue.';
        }
        $reason = $body['override_reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            $errors['override_reason'][] = 'Texte attendu.';
        }
        $guardians = self::guardians($body['guardians'] ?? [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'draft' => new PupilDraft(
                (string) $last,
                (string) $first,
                (string) $birth,
                (string) $sex,
                is_string($act) && trim($act) !== '' ? trim($act) : null,
                is_string($uuid) ? strtolower($uuid) : null,
            ),
            'class_id' => is_string($class) ? $class : null,
            'guardians' => $guardians,
            'confirm_different' => $confirm === true,
            'override_reason' => is_string($reason) ? $reason : null,
        ];
    }

    /** @param array<string, list<string>> $errors */
    private static function name(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 120) {
            $errors[$field][] = 'Texte requis de 120 caractères au plus.';

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{kind: string, person_id?: string, identity?: array<string, mixed>}>
     */
    private static function guardians(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['guardians'][] = 'Liste de garants attendue.';

            return [];
        }
        $guardians = [];
        foreach ($value as $index => $guardian) {
            if (!is_array($guardian) || !is_string($guardian['kind'] ?? null) || !in_array($guardian['kind'], self::KINDS, true)) {
                $errors['guardians.' . $index][] = 'Garant invalide : kind attendu parmi ' . implode(', ', self::KINDS) . '.';

                continue;
            }
            if (is_string($guardian['person_id'] ?? null) && $guardian['person_id'] !== '') {
                $guardians[] = ['kind' => $guardian['kind'], 'person_id' => $guardian['person_id']];
            } elseif (is_array($guardian['identity'] ?? null) && $guardian['identity'] !== []) {
                $guardians[] = ['kind' => $guardian['kind'], 'identity' => $guardian['identity']];
            } else {
                $errors['guardians.' . $index][] = 'Indiquez person_id ou identity.';
            }
        }

        return $guardians;
    }
}
