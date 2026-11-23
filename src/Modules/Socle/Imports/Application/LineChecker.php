<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;
use Scholaris\Modules\Socle\Imports\Domain\RoleRules;

/**
 * Contrôle et normalisation d'UNE ligne du fichier (sans rien écrire) : identité, identifiants de connexion (téléphone camerounais
 * « +237… », e-mail en minuscules), langue, rôle (code ou libellé, parmi ceux que l'importeur peut attribuer ici), périmètre résolu
 * côté serveur, dates. Les messages sont en français et ne rappellent que ce qui est utile à la correction.
 */
final class LineChecker
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, string> $raw cellules sous leur clé interne
     * @param array<string, array{code: string, label_fr: string, label_en: string}> $roles rôles acceptés ici, par code
     * @return array{last_name: string, first_name: string, email: ?string, phone: ?string, locale: string, role_code: ?string, scope_type: ?string, scope_code: string, scope_id: ?string, starts_on: ?string, ends_on: ?string, note: string, messages: list<string>}
     */
    public function check(string $tenantId, string $establishmentId, array $raw, array $roles): array
    {
        $messages = [];
        $lastName = self::clean($raw['last_name']);
        $firstName = self::clean($raw['first_name']);
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $messages[] = 'Le nom est obligatoire (100 caractères au plus).';
        }
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $messages[] = 'Les prénoms sont obligatoires (100 caractères au plus).';
        }

        $email = null;
        if ($raw['email'] !== '') {
            $email = self::email($raw['email']);
            if ($email === null) {
                $messages[] = 'Adresse e-mail invalide.';
            }
        }
        $phone = null;
        if ($raw['phone'] !== '') {
            $phone = self::phone($raw['phone']);
            if ($phone === null) {
                $messages[] = 'Numéro de téléphone invalide (attendu : mobile camerounais, ex. 6 91 00 00 00 ou +237 691 000 000).';
            }
        }
        if ($raw['email'] === '' && $raw['phone'] === '') {
            $messages[] = 'Indiquez une adresse e-mail ou un numéro de téléphone : c\'est l\'identifiant de connexion.';
        }

        $locale = self::locale($raw['locale']);
        if ($locale === null) {
            $messages[] = 'Langue inconnue : écrivez « fr » ou « en » (vide = fr).';
            $locale = 'fr';
        }

        $role = $this->role($raw['role'], $roles, $messages);

        $scopeType = $this->scopes->typeOf($raw['scope']);
        $scopeId = null;
        if ($scopeType === null) {
            $messages[] = 'Périmètre inconnu : écrivez « établissement », « unité » ou « structure » (vide = établissement).';
        } else {
            $resolved = $this->scopes->resolve($tenantId, $establishmentId, $scopeType, $raw['scope_code']);
            $scopeId = $resolved['id'];
            if ($resolved['error'] !== null) {
                $messages[] = $resolved['error'];
            }
        }

        [$startsOn, $endsOn] = $this->dates($raw, $role, $messages);

        $note = self::clean($raw['note']);
        if (mb_strlen($note) > 200) {
            $messages[] = 'La note est limitée à 200 caractères.';
        }
        if (str_starts_with(PeopleColumns::normalize($note), 'exemple')) {
            $messages[] = 'Ligne d\'exemple du modèle : supprimez-la du fichier.';
        }

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'email' => $email,
            'phone' => $phone,
            'locale' => $locale,
            'role_code' => $role,
            'scope_type' => $scopeType,
            'scope_code' => mb_substr(self::clean($raw['scope_code']), 0, 80),
            'scope_id' => $scopeId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'note' => mb_substr($note, 0, 200),
            'messages' => $messages,
        ];
    }

    /**
     * @param array<string, array{code: string, label_fr: string, label_en: string}> $roles
     * @param list<string> $messages
     */
    private function role(string $cell, array $roles, array &$messages): ?string
    {
        if ($cell === '') {
            $messages[] = 'Le rôle est obligatoire.';

            return null;
        }
        $wanted = PeopleColumns::normalize($cell);
        foreach ($roles as $code => $role) {
            if ($wanted === PeopleColumns::normalize($code) || $wanted === PeopleColumns::normalize($role['label_fr']) || $wanted === PeopleColumns::normalize($role['label_en'])) {
                return $code;
            }
        }
        $messages[] = 'Rôle « ' . mb_substr($cell, 0, 60) . ' » inconnu ou que vous ne pouvez pas attribuer dans cet établissement.';

        return null;
    }

    /**
     * @param array<string, string> $raw
     * @param list<string> $messages
     * @return array{0: ?string, 1: ?string}
     */
    private function dates(array $raw, ?string $role, array &$messages): array
    {
        $starts = null;
        $ends = null;
        if ($raw['starts_on'] !== '') {
            $starts = self::date($raw['starts_on']);
            if ($starts === null) {
                $messages[] = 'Date de début invalide (attendu : AAAA-MM-JJ ou JJ/MM/AAAA).';
            }
        }
        if ($raw['ends_on'] !== '') {
            $ends = self::date($raw['ends_on']);
            if ($ends === null) {
                $messages[] = 'Date de fin invalide (attendu : AAAA-MM-JJ ou JJ/MM/AAAA).';
            }
        }
        $today = (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'))->format('Y-m-d');
        $from = $starts ?? $today;
        if ($ends !== null && $ends < $from) {
            $messages[] = 'La date de fin doit être postérieure à la date de début.';
        } elseif ($ends !== null && $ends < $today) {
            $messages[] = 'La date de fin est déjà passée.';
        }
        if ($role !== null && RoleRules::isTemporary($role)) {
            if ($ends === null && $raw['ends_on'] === '') {
                $messages[] = 'Ce rôle est temporaire : la date de fin est obligatoire.';
            } elseif ($ends !== null) {
                $limit = (new DateTimeImmutable($from))->modify('+' . PeopleColumns::TEMPORARY_MAX_DAYS . ' days')->format('Y-m-d');
                if ($ends > $limit) {
                    $messages[] = 'Ce rôle est temporaire : la date de fin ne peut pas dépasser ' . PeopleColumns::TEMPORARY_MAX_DAYS . ' jours après le début.';
                }
            }
        }

        return [$starts, $ends];
    }

    /** Date ISO « AAAA-MM-JJ » depuis ISO, JJ/MM/AAAA ou numéro de série Excel ; null si illisible. */
    public static function date(string $cell): ?string
    {
        $cell = trim($cell);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $cell, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $cell, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{5})(?:\.\d+)?$/', $cell, $m) === 1 && (int) $m[1] >= 36526 && (int) $m[1] <= 73050) {
            // Numéro de série Excel (jours depuis le 30/12/1899), plage 2000-2099.
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $m[1] . ' days')->format('Y-m-d');
        }

        return null;
    }

    /** Forme canonique « +237XXXXXXXXX » (mêmes règles que la connexion) ; null si le numéro n'est pas plausible. */
    public static function phone(string $raw): ?string
    {
        $clean = (string) preg_replace('/[\s().\-]/', '', trim($raw));
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }
        if (preg_match('/^[26]\d{8}$/', $clean) === 1) {
            return '+237' . $clean;
        }
        if (preg_match('/^237[26]\d{8}$/', $clean) === 1) {
            return '+' . $clean;
        }

        return preg_match('/^\+\d{8,15}$/', $clean) === 1 ? $clean : null;
    }

    public static function email(string $raw): ?string
    {
        $email = mb_strtolower(trim($raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 190 ? $email : null;
    }

    private static function locale(string $cell): ?string
    {
        return match (PeopleColumns::normalize($cell)) {
            '', 'fr', 'francais', 'french' => 'fr',
            'en', 'anglais', 'english' => 'en',
            default => null,
        };
    }

    /** Espaces multiples réduits, caractères de contrôle retirés. */
    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text)));
    }
}
