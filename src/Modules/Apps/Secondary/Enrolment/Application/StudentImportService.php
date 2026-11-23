<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Enrolment\Domain\ImportDefaults;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\TableReader;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Import des élèves par fichier Excel ou CSV (comme les autres applications) : SIMULATION d'abord (aucune écriture : chaque ligne
 * valide, en erreur ou doublon, avec ses messages), puis application du même fichier. Un fichier qui a des erreurs n'est pas
 * appliqué (rien n'est enregistré : corriger puis relancer). Chaque ligne passe par l'inscription d'API-01 (fiche, garant d'un
 * mineur, garde du secondaire, âge, matricule) ; un élève déjà inscrit dans l'année (même matricule) est ignoré.
 */
final class StudentImportService
{
    private const GENDERS = ['m' => 'male', 'h' => 'male', 'masculin' => 'male', 'garcon' => 'male', 'f' => 'female', 'feminin' => 'female', 'fille' => 'female'];
    private const RELATIONS = ['parent', 'guardian', 'financial_guarantor', 'emergency'];

    public function __construct(
        private readonly TableReader $reader,
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassProfiles $classes,
        private readonly NationalIds $nationalIds,
        private readonly EnrolmentService $enrolment,
    ) {
    }

    /** @return array{columns: list<string>, required: list<string>, sample: list<list<string>>, separator: string, encoding: string} */
    public function template(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, EnrolmentService::MANAGE);

        return ['columns' => ImportDefaults::ALL, 'required' => ImportDefaults::REQUIRED, 'sample' => ImportDefaults::SAMPLE, 'separator' => ';', 'encoding' => 'UTF-8'];
    }

    /**
     * @param array<string, mixed> $body format (csv|xlsx), content ou content_base64, academic_year_id, entry_date, dry_run
     * @return array<string, mixed>
     */
    public function run(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $this->access->require($principal, $establishmentId, EnrolmentService::MANAGE);
        $errors = [];
        $format = is_string($body['format'] ?? null) ? $body['format'] : 'csv';
        $year = is_string($body['academic_year_id'] ?? null) ? trim($body['academic_year_id']) : '';
        $date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';
        $dryRun = ($body['dry_run'] ?? true) !== false;
        if ($year === '') {
            $errors['academic_year_id'] = ['Année scolaire obligatoire.'];
        }
        if (!EnrolmentService::isDate($date)) {
            $errors['entry_date'] = ['Date d\'entrée au format AAAA-MM-JJ attendue.'];
        }
        $content = null;
        if (is_string($body['content_base64'] ?? null)) {
            $decoded = base64_decode($body['content_base64'], true);
            $content = $decoded === false ? null : $decoded;
        } elseif (is_string($body['content'] ?? null)) {
            $content = $body['content'];
        }
        if ($content === null) {
            $errors['content'] = ['Contenu du fichier obligatoire (content en texte CSV, ou content_base64).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $table = $this->reader->read($format, (string) $content);
        $classes = [];
        foreach ($this->classes->forYear($tenantId, $establishmentId, $year) as $class) {
            $classes[self::fold($class->label)] = $class->id;
            $classes[strtolower($class->id)] = $class->id;
        }
        $lines = [];
        $plans = [];
        $seen = [];
        foreach ($table['rows'] as $number => $row) {
            [$line, $plan] = $this->check($tenantId, $establishmentId, $principal, (int) $number, $row, $classes, $year, $date, $seen);
            $lines[] = $line;
            if ($plan !== null) {
                $plans[(int) $number] = $plan;
            }
        }
        $summary = self::summary($lines);
        $report = ['dry_run' => $dryRun, 'unknown_columns' => $table['unknown'], 'summary' => $summary, 'lines' => $lines];
        if ($dryRun) {
            return $report;
        }
        if ($summary['error'] > 0) {
            throw new HttpException(422, 'Import refusé', 'Aucun élève n\'a été inscrit : corrigez les lignes en erreur puis relancez l\'import.', 'https://api.scholaris.cm/errors/import-rejected', ['file' => [sprintf('%d ligne(s) en erreur.', $summary['error'])]]);
        }
        $applied = 0;
        foreach ($lines as $index => $line) {
            $plan = $plans[(int) $line['line']] ?? null;
            if ($plan === null) {
                continue;
            }
            try {
                $dossier = $this->enrolment->admit($principal, $establishmentId, $plan);
                $lines[$index]['status'] = 'enrolled';
                $lines[$index]['person_id'] = $dossier['person_id'];
                ++$applied;
            } catch (HttpException $e) {
                $lines[$index]['status'] = 'error';
                $lines[$index]['messages'][] = EnrolmentService::explain($e);
            }
        }
        $this->access->audit($principal, 'secondary.students.imported', 'se_enrolment_record', $year, null, ['applied' => $applied]);

        return ['dry_run' => false, 'unknown_columns' => $table['unknown'], 'summary' => self::summary($lines) + ['enrolled' => $applied], 'lines' => $lines];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $classes
     * @param array<string, int> $seen
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function check(string $tenantId, string $establishmentId, Principal $principal, int $number, array $row, array $classes, string $year, string $date, array &$seen): array
    {
        $messages = [];
        $status = 'valid';
        if (strtoupper($row['nom']) === 'EXEMPLE' || strtoupper($row['nom_parent']) === 'EXEMPLE') {
            $messages[] = 'Ligne d\'exemple du modèle : supprimez-la.';
        }
        foreach (['nom' => 'Nom', 'prenoms' => 'Prénoms', 'classe' => 'Classe'] as $column => $label) {
            if ($row[$column] === '') {
                $messages[] = $label . ' manquant.';
            }
        }
        $birth = self::date($row['date_naissance']);
        if ($birth === null) {
            $messages[] = 'Date de naissance illisible (AAAA-MM-JJ ou JJ/MM/AAAA).';
        }
        $gender = null;
        if ($row['sexe'] !== '') {
            $gender = self::GENDERS[self::fold($row['sexe'])] ?? null;
            if ($gender === null) {
                $messages[] = 'Sexe « ' . $row['sexe'] . ' » non reconnu (M ou F).';
            }
        }
        $classId = $classes[self::fold($row['classe'])] ?? $classes[strtolower($row['classe'])] ?? null;
        if ($row['classe'] !== '' && $classId === null) {
            $messages[] = 'Classe « ' . $row['classe'] . ' » inconnue pour cette année : créez-la dans le paramétrage.';
        }
        $guardians = [];
        if ($row['nom_parent'] !== '' || $row['telephone_parent'] !== '') {
            $relation = $row['lien_parent'] === '' ? 'parent' : self::fold($row['lien_parent']);
            if (!in_array($relation, self::RELATIONS, true)) {
                $messages[] = 'Lien du garant attendu : ' . implode(', ', self::RELATIONS) . '.';
            }
            if ($row['telephone_parent'] !== '' && preg_match('/^\+?[0-9 ]{8,20}$/', $row['telephone_parent']) !== 1) {
                $messages[] = 'Téléphone du garant invalide.';
            }
            $guardians[] = ['kind' => $relation, 'identity' => array_filter([
                'last_name' => $row['nom_parent'] === '' ? $row['nom'] : $row['nom_parent'], 'first_name' => $row['prenoms_parent'] === '' ? 'Parent' : $row['prenoms_parent'],
                'phone' => $row['telephone_parent'],
            ], static fn (string $v): bool => $v !== '')];
        } elseif ($birth !== null && (new DateTimeImmutable($birth))->diff(new DateTimeImmutable($date))->y < 18) {
            $messages[] = 'Élève mineur : un garant (nom_parent, telephone_parent) est exigé.';
        }
        $nationalId = null;
        if ($row['matricule'] !== '') {
            $nationalId = $this->nationalIds->normalize($tenantId, $establishmentId, $row['matricule']);
            if ($nationalId === null) {
                $messages[] = 'Matricule au format non reconnu (paramètre de l\'établissement).';
            } else {
                $owner = $this->nationalIds->personIdByNationalId($tenantId, $establishmentId, $nationalId);
                if ($owner !== null && $this->store->count('se_enrolment_records', $tenantId, $establishmentId, ['person_id' => $owner, 'academic_year_id' => $year]) > 0) {
                    $status = 'duplicate';
                    $messages[] = 'Déjà inscrit cette année (même matricule) : ligne ignorée.';
                }
            }
        }
        $key = $nationalId !== null ? 'M:' . $nationalId : 'N:' . self::fold($row['nom']) . '|' . self::fold($row['prenoms']) . '|' . ($birth ?? '');
        if (isset($seen[$key])) {
            $messages[] = 'Même élève que la ligne ' . $seen[$key] . ' (doublon dans le fichier).';
        } else {
            $seen[$key] = $number;
        }
        $plan = [
            'person_id' => null, 'identity' => array_filter(['last_name' => $row['nom'], 'first_name' => $row['prenoms'], 'birth_date' => $birth, 'gender' => $gender, 'birth_place' => $row['lieu_naissance']], static fn (mixed $v): bool => is_string($v) && $v !== ''),
            'guardians' => $guardians, 'class_id' => $classId, 'entry_date' => $date, 'national_id' => $nationalId, 'repeater' => in_array(self::fold($row['redoublant']), ['oui', 'o', 'yes', '1'], true),
            'entry_kind' => 'import', 'documents_checked' => [],
        ];
        if ($status !== 'duplicate' && $messages === [] && $classId !== null) {
            try {
                $check = $this->enrolment->admit($principal, $establishmentId, $plan, true);
                if (is_string($check['age_alert'] ?? null)) {
                    $messages[] = $check['age_alert'] . ' Décision du chef d\'établissement exigée : inscrivez cet élève depuis l\'écran d\'inscription.';
                }
            } catch (HttpException $e) {
                $messages[] = EnrolmentService::explain($e);
            }
        }
        if ($status !== 'duplicate' && $messages !== []) {
            $status = 'error';
        }

        return [['line' => $number, 'status' => $status, 'last_name' => $row['nom'], 'first_name' => $row['prenoms'], 'class' => $row['classe'], 'messages' => $messages], $status === 'valid' ? $plan : null];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{valid: int, error: int, duplicate: int, total: int}
     */
    private static function summary(array $lines): array
    {
        $count = static fn (string $status): int => count(array_filter($lines, static fn (array $l): bool => $l['status'] === $status));

        return ['valid' => $count('valid') + $count('enrolled'), 'error' => $count('error'), 'duplicate' => $count('duplicate'), 'total' => count($lines)];
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        if (EnrolmentService::isDate($value)) {
            return $value;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (ctype_digit($value) && (int) $value > 0) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }

        return null;
    }

    private static function fold(string $value): string
    {
        return TableReader::normalize($value);
    }
}
