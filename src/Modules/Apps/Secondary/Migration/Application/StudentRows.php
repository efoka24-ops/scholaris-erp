<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;

/**
 * Jeu « élèves et inscriptions » : fiche de l'élève, garant d'un mineur, inscription dans une classe de l'année et matricule
 * national, chacun par l'inscription d'API-01 (EnrolmentService::admit : garde du secondaire, autorisation, âge, matricule).
 * La simulation passe par l'inscription en mode contrôle (rien n'est écrit) et par la porte d'inscription du socle (autorisation
 * d'ouverture valide ce jour pour la classe ou sa filière), que l'inscription réelle contrôle en dernier. `id_ancien` (identifiant de l'ancien logiciel)
 * est la clé de reprise quand il est fourni, sinon le matricule, sinon nom, prénoms et date de naissance ; il sert aussi à
 * rattacher les soldes. Un élève déjà inscrit dans l'année (même matricule) est un doublon ignoré.
 *
 * Jeu non atomique : l'inscription du socle numérote dans sa propre transaction ; chaque ligne est appliquée et marquée reprise
 * l'une après l'autre, et rejouer le lot ne reprend que les lignes restantes.
 *
 * @phpstan-import-type Line from KindRows
 */
final class StudentRows implements KindRows
{
    private const GENDERS = ['m' => 'male', 'h' => 'male', 'masculin' => 'male', 'garcon' => 'male', 'f' => 'female', 'feminin' => 'female', 'fille' => 'female'];
    private const RELATIONS = ['parent', 'guardian', 'financial_guarantor', 'emergency'];
    private const ADULT_YEARS = 18;

    public function __construct(
        private readonly Store $store,
        private readonly ClassProfiles $classes,
        private readonly NationalIds $nationalIds,
        private readonly EnrolmentService $enrolment,
        private readonly EnrolmentGate $gate,
    ) {
    }

    public function permissions(): array
    {
        return EnrolmentService::MANAGE;
    }

    public function atomic(): bool
    {
        return false;
    }

    public function plan(Principal $principal, string $establishmentId, string $academicYearId, ?string $entryDate, array $rows): array
    {
        $tenantId = $principal->tenantId;
        $classes = [];
        $open = [];
        $today = new DateTimeImmutable('now', new DateTimeZone('Africa/Douala'));
        foreach ($this->classes->forYear($tenantId, $establishmentId, $academicYearId) as $class) {
            $classes[Cells::fold($class->label)] = $class->id;
            $open[$class->id] = $this->gate->canEnrol($tenantId, $establishmentId, $class->structureId, $today);
        }
        $date = $entryDate ?? '';
        $lines = [];
        foreach ($rows as $number => $row) {
            $lines[] = $this->check($principal, $establishmentId, (int) $number, $row, $classes, $open, $academicYearId, $date);
        }

        return $lines;
    }

    public function apply(Principal $principal, string $establishmentId, string $academicYearId, array $plan): string
    {
        return (string) $this->enrolment->admit($principal, $establishmentId, $plan)['person_id'];
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $classes
     * @param array<string, bool> $open classe => inscription ouverte ce jour (autorisation d'ouverture)
     * @return Line
     */
    private function check(Principal $principal, string $establishmentId, int $number, array $row, array $classes, array $open, string $year, string $date): array
    {
        $tenantId = $principal->tenantId;
        $messages = [];
        $status = 'valid';
        if (Cells::isExample($row['nom'], $row['nom_parent'], $row['id_ancien'])) {
            $messages[] = 'Ligne d\'exemple du modèle : supprimez-la.';
        }
        foreach (['nom' => 'Nom', 'prenoms' => 'Prénoms', 'classe' => 'Classe'] as $column => $label) {
            if ($row[$column] === '') {
                $messages[] = $label . ' manquant.';
            }
        }
        if (mb_strlen($row['id_ancien']) > 60) {
            $messages[] = 'Identifiant de l\'ancien logiciel : 60 caractères au plus.';
        }
        $birth = Cells::date($row['date_naissance']);
        if ($birth === null) {
            $messages[] = 'Date de naissance illisible (AAAA-MM-JJ ou JJ/MM/AAAA).';
        }
        $gender = null;
        if ($row['sexe'] !== '') {
            $gender = self::GENDERS[Cells::fold($row['sexe'])] ?? null;
            if ($gender === null) {
                $messages[] = 'Sexe « ' . $row['sexe'] . ' » non reconnu (M ou F).';
            }
        }
        $classId = $classes[Cells::fold($row['classe'])] ?? null;
        if ($row['classe'] !== '' && $classId === null) {
            $messages[] = 'Classe « ' . $row['classe'] . ' » inconnue pour cette année : reprenez d\'abord les classes.';
        } elseif ($classId !== null && !($open[$classId] ?? true)) {
            $messages[] = 'Classe « ' . $row['classe'] . ' » sans autorisation d\'ouverture valide ce jour : déclarez-la dans Administration, Autorisations.';
        }
        $guardians = [];
        if ($row['nom_parent'] !== '' || $row['telephone_parent'] !== '') {
            $relation = $row['lien_parent'] === '' ? 'parent' : Cells::fold($row['lien_parent']);
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
        } elseif ($birth !== null && $date !== '' && (new DateTimeImmutable($birth))->diff(new DateTimeImmutable($date))->y < self::ADULT_YEARS) {
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
                    $messages = ['Déjà inscrit cette année (même matricule) : ligne ignorée.'];
                }
            }
        }
        $key = match (true) {
            $row['id_ancien'] !== '' => 'A:' . Cells::fold($row['id_ancien']),
            $nationalId !== null => 'M:' . $nationalId,
            default => 'N:' . Cells::fold($row['nom']) . '|' . Cells::fold($row['prenoms']) . '|' . ($birth ?? ''),
        };
        $plan = [
            'person_id' => null, 'identity' => array_filter(['last_name' => $row['nom'], 'first_name' => $row['prenoms'], 'birth_date' => $birth, 'gender' => $gender, 'birth_place' => $row['lieu_naissance']], static fn (mixed $v): bool => is_string($v) && $v !== ''),
            'guardians' => $guardians, 'class_id' => $classId, 'entry_date' => $date, 'national_id' => $nationalId, 'repeater' => in_array(Cells::fold($row['redoublant']), ['oui', 'o', 'yes', '1'], true),
            'entry_kind' => 'import', 'documents_checked' => [],
        ];
        if ($status === 'valid' && $messages === [] && $classId !== null) {
            try {
                $check = $this->enrolment->admit($principal, $establishmentId, $plan, true);
                if (is_string($check['age_alert'] ?? null)) {
                    $messages[] = $check['age_alert'] . ' Décision du chef d\'établissement exigée : inscrivez cet élève depuis l\'écran d\'inscription.';
                }
            } catch (HttpException $e) {
                $messages[] = Cells::explain($e);
            }
        }
        if ($status === 'valid' && $messages !== []) {
            $status = 'error';
        }

        return [
            'line' => $number, 'status' => $status, 'key' => $key, 'label' => trim(mb_strtoupper($row['nom']) . ' ' . $row['prenoms'] . ' (' . $row['classe'] . ')'),
            'messages' => $messages, 'plan' => $status === 'valid' ? $plan : null,
        ];
    }
}
