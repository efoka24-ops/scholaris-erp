<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Listes nominatives des élèves d'une école : élèves d'une classe (appel) et recherche par nom ou numéro d'apprenant. Lecture seule, bornée
 * au client et à l'école ; le contrôle de périmètre et la journalisation nominative restent à la charge des contrôleurs. Les listes sont
 * triées par nom, prénom puis identifiant.
 */
final class PupilListingService
{
    /** Longueur minimale du terme de recherche si le paramètre `public_schools.pupil_search.min_length` n'est pas posé pour l'école. */
    private const DEFAULT_SEARCH_MIN_LENGTH = 2;

    /** Taille des lots de requêtes à clause IN. */
    private const CHUNK = 400;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ClassRoster $classes,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly SettingsReader $settings,
    ) {
    }

    public function searchMinLength(string $tenantId, string $establishmentId): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'public_schools.pupil_search.min_length', self::DEFAULT_SEARCH_MIN_LENGTH);

        return is_int($value) && $value >= 1 ? $value : self::DEFAULT_SEARCH_MIN_LENGTH;
    }

    /**
     * Élèves inscrits dans la classe à la date (AAAA-MM-JJ).
     *
     * @return list<array{person_id: string, last_name: string, first_name: string, sex: ?string, learner_number: ?string}>
     */
    public function roster(string $tenantId, string $establishmentId, string $classId, string $date): array
    {
        return $this->rows($tenantId, $this->classes->pupilIdsIn($tenantId, $establishmentId, $classId, $date));
    }

    /**
     * Élèves de l'école dont le nom, le prénom ou le numéro d'apprenant contient chaque mot du terme.
     *
     * @param list<string>|null $classIds classes couvertes (enseignant) ; null = toute l'école
     * @return list<array{person_id: string, last_name: string, first_name: string, sex: ?string, learner_number: ?string}>
     */
    public function search(string $tenantId, string $establishmentId, string $term, ?array $classIds, string $date): array
    {
        if ($classIds === null) {
            $ids = $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId);
        } else {
            $ids = [];
            foreach ($classIds as $classId) {
                array_push($ids, ...$this->classes->pupilIdsIn($tenantId, $establishmentId, $classId, $date));
            }
        }
        $words = array_values(array_filter(explode(' ', self::fold($term)), static fn (string $word): bool => $word !== ''));

        return array_values(array_filter($this->rows($tenantId, array_values(array_unique($ids))), static function (array $row) use ($words): bool {
            $haystack = self::fold($row['last_name'] . ' ' . $row['first_name'] . ' ' . ($row['learner_number'] ?? ''));
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<string> $personIds
     * @return list<array{person_id: string, last_name: string, first_name: string, sex: ?string, learner_number: ?string}>
     */
    private function rows(string $tenantId, array $personIds): array
    {
        $registrations = [];
        foreach (array_chunk($personIds, self::CHUNK) as $chunk) {
            $query = $this->connections->pdo()->prepare(
                'SELECT person_id, learner_number, sex FROM pub_pupil_registrations WHERE tenant_id = ? AND person_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
            );
            $query->execute([$tenantId, ...$chunk]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $registrations[(string) $row['person_id']] = $row;
            }
        }
        $rows = [];
        foreach (array_chunk($personIds, self::CHUNK) as $chunk) {
            foreach ($this->people->findMany($tenantId, $chunk) as $person) {
                $registration = $registrations[$person->id] ?? null;
                $rows[] = [
                    'person_id' => $person->id,
                    'last_name' => $person->lastName,
                    'first_name' => $person->firstName,
                    'sex' => is_array($registration) && $registration['sex'] !== null && $registration['sex'] !== '' ? (string) $registration['sex'] : null,
                    'learner_number' => is_array($registration) && $registration['learner_number'] !== null ? (string) $registration['learner_number'] : null,
                ];
            }
        }
        usort($rows, static fn (array $left, array $right): int => [mb_strtolower($left['last_name']), mb_strtolower($left['first_name']), $left['person_id']]
            <=> [mb_strtolower($right['last_name']), mb_strtolower($right['first_name']), $right['person_id']]);

        return $rows;
    }

    /** Minuscules sans accents, espaces réduits : la recherche ignore la casse et les diacritiques. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = is_string($decomposed) ? (string) preg_replace('/\p{Mn}+/u', '', $decomposed) : $text;
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
