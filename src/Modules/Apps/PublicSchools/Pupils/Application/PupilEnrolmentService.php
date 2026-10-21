<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilDraft;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistration;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumbering;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Inscription d'un élève (EX-ELV-01..03, RG-ELV-1..3, API-02) : fiche et garants (LearnerWriter), identifiant national (PP8),
 * inscription dans la classe, profil minimal et notification des garants (NOT-01). Aucun champ APEE (RG-ELV-1). Un acte de naissance
 * manquant, un âge hors norme ou un doublon passé outre donnent des avertissements, jamais un refus (RG-ELV-2, RG-ELV-3).
 */
final class PupilEnrolmentService
{
    public const NOTIFICATION_TEMPLATE = 'public_pupil_registered';

    /** Âge attendu au 31/12 par code de niveau d'entrée (RG-ELV-3), paramètre `public_schools.entry_age.levels`. */
    private const DEFAULT_ENTRY_AGES = ['SIL' => 6, 'CLASS1' => 6];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly SchoolRegistry $schools,
        private readonly ClassRoster $classes,
        private readonly LearnerWriter $writer,
        private readonly LearnerNumbering $numbering,
        private readonly PersonDirectory $people,
        private readonly DuplicateScope $scope,
        private readonly SettingsReader $settings,
        private readonly PupilClock $clock,
        private readonly AuditLog $audit,
        private readonly GuardianNotifier $notifier,
    ) {
    }

    /**
     * @param list<array{kind: string, person_id?: string, identity?: array<string, mixed>}> $guardians
     * @return array{registration: PupilRegistration, replayed: bool}
     *
     * @throws \InvalidArgumentException école fermée ou inconnue, classe d'une autre école
     * @throws HttpException 409 doublon probable non confirmé, 422 données invalides
     */
    public function register(
        string $tenantId,
        string $establishmentId,
        PupilDraft $draft,
        ?string $classId,
        array $guardians,
        string $actorId,
        bool $confirmDifferent = false,
        ?string $overrideReason = null,
    ): array {
        if (!$this->schools->isOpen($tenantId, $establishmentId)) {
            throw new \InvalidArgumentException('L\'école est fermée, suspendue ou inconnue : aucune inscription possible.');
        }
        $class = null;
        if ($classId !== null) {
            $class = $this->classes->find($tenantId, $establishmentId, $classId)
                ?? throw new \InvalidArgumentException('La classe n\'appartient pas à cette école.');
        }
        if ($class !== null && $guardians === []) {
            throw HttpException::validation(['guardians' => ['Un parent, un tuteur ou un garant est requis pour inscrire un élève mineur dans une classe.']]);
        }
        if ($draft->clientUuid !== null) {
            $replay = $this->byClientUuid($tenantId, $draft->clientUuid);
            if ($replay !== null) {
                return ['registration' => $replay, 'replayed' => true];
            }
        }

        $guardianIds = [];
        foreach ($guardians as $guardian) {
            if (is_string($guardian['person_id'] ?? null)) {
                $guardianIds[] = $guardian['person_id'];
            }
        }
        $warnings = [];
        $duplicates = $this->duplicates($tenantId, $establishmentId, $draft, $guardianIds);
        if ($duplicates !== []) {
            if (!$confirmDifferent) {
                throw new HttpException(
                    409,
                    'Doublon probable',
                    'Un élève semblable existe déjà : reprenez sa fiche, ou confirmez qu\'il s\'agit d\'un autre enfant avec un motif.',
                    'https://api.scholaris.cm/errors/conflict',
                    ['possible_duplicates' => array_map(static fn (PupilInfo $info): string => $info->personId, $duplicates)],
                );
            }
            if ($overrideReason === null || trim($overrideReason) === '' || mb_strlen(trim($overrideReason)) > 500) {
                throw HttpException::validation(['override_reason' => ['Motif obligatoire pour passer outre un doublon probable (500 caractères au plus).']]);
            }
            $warnings[] = 'possible_duplicate';
        }

        $created = $this->writer->createLearner($tenantId, [
            'first_name' => $draft->firstName,
            'last_name' => $draft->lastName,
            'birth_date' => $draft->birthDate,
            'gender' => $draft->sex === 'F' ? 'female' : 'male',
        ], $guardians, $actorId, $confirmDifferent);
        $personId = $created['person_id'];
        $number = $this->numbering->assign($tenantId, $personId, $establishmentId);

        $today = $this->clock->today();
        if ($class !== null) {
            $yearId = $this->yearOf($tenantId, $establishmentId, $class->structureId);
            $this->writer->enroll($tenantId, $establishmentId, $personId, $class->structureId, $yearId, $today, $actorId);
            $ageWarning = $this->ageWarning($tenantId, $establishmentId, $class->levelCodes, $draft->birthDate, $today);
            if ($ageWarning !== null) {
                $warnings[] = $ageWarning;
            }
            if ($this->classes->headcount($tenantId, $establishmentId, $class->structureId, $today) > $this->classAlertThreshold($tenantId, $establishmentId)) {
                $warnings[] = 'class_size_alert';
            }
        }
        if ($draft->birthActRef === null) {
            $warnings[] = 'birth_act_missing';
        }

        $this->store($tenantId, $establishmentId, $personId, $number, $draft, $warnings, $overrideReason, $actorId, $today);
        if ($duplicates !== []) {
            $this->audit->append($tenantId, $actorId, 'public_schools.pupil.duplicate_overridden', 'pupil', $personId, null, [
                'establishment_id' => $establishmentId,
                'reason' => trim((string) $overrideReason),
                'similar_to' => array_map(static fn (PupilInfo $info): string => $info->personId, $duplicates),
            ]);
        }
        if ($guardianIds !== [] || $created['guardian_ids'] !== []) {
            $notified = $this->notify($tenantId, $establishmentId, $personId, $number, $draft);
            if (!$notified) {
                $warnings[] = 'guardian_notification_failed';
            }
        }

        return [
            'registration' => new PupilRegistration(
                new PupilInfo($tenantId, $personId, $number, $draft->lastName, $draft->firstName, $draft->birthDate, false),
                array_values(array_unique($warnings)),
            ),
            'replayed' => false,
        ];
    }

    /**
     * Élèves déjà connus qui ressemblent au brouillon (même nom normalisé et même date de naissance dans le département de l'école ;
     * avec des garants connus, au moins un parent commun).
     *
     * @param list<string> $guardianIds
     * @return list<PupilInfo>
     */
    public function duplicates(string $tenantId, string $establishmentId, PupilDraft $draft, array $guardianIds = []): array
    {
        $sql = 'SELECT person_id, learner_number FROM pub_pupil_registrations WHERE tenant_id = ? AND name_key = ? AND birth_date = ?';
        $params = [$tenantId, IdentityKey::of($draft->lastName, $draft->firstName), $draft->birthDate];
        $establishments = $this->scope->establishments($tenantId, $establishmentId);
        if ($establishments !== null) {
            $sql .= ' AND establishment_id IN (' . implode(', ', array_fill(0, count($establishments), '?')) . ')';
            array_push($params, ...$establishments);
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY person_id');
        $query->execute($params);
        $found = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $personId = (string) $row['person_id'];
            if ($guardianIds !== []) {
                $known = array_map(static fn ($person): string => $person->id, $this->people->guarantorsOf($tenantId, $personId));
                if (array_intersect($known, $guardianIds) === []) {
                    continue;
                }
            }
            $person = $this->people->find($tenantId, $personId);
            if ($person !== null) {
                $found[] = new PupilInfo(
                    $tenantId,
                    $personId,
                    $row['learner_number'] !== null ? (string) $row['learner_number'] : null,
                    $person->lastName,
                    $person->firstName,
                    $draft->birthDate,
                    false,
                );
            }
        }

        return $found;
    }

    private function byClientUuid(string $tenantId, string $clientUuid): ?PupilRegistration
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_pupil_registrations WHERE tenant_id = ? AND client_uuid = ?');
        $query->execute([$tenantId, $clientUuid]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $person = $this->people->find($tenantId, (string) $row['person_id']);
        if ($person === null) {
            return null;
        }
        $warnings = is_string($row['warnings']) && $row['warnings'] !== '' ? explode(',', $row['warnings']) : [];

        return new PupilRegistration(
            new PupilInfo($tenantId, (string) $row['person_id'], $row['learner_number'] !== null ? (string) $row['learner_number'] : null, $person->lastName, $person->firstName, (string) $row['birth_date'], false),
            $warnings,
        );
    }

    private function yearOf(string $tenantId, string $establishmentId, string $structureId): string
    {
        $query = $this->connections->pdo()->prepare('SELECT academic_year_id FROM pub_class_profiles WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?');
        $query->execute([$tenantId, $establishmentId, $structureId]);
        $year = $query->fetchColumn();

        return is_string($year) ? $year : throw new \InvalidArgumentException('La classe n\'appartient pas à cette école.');
    }

    /**
     * RG-ELV-3 : l'âge au 31 décembre de l'année d'inscription s'écarte de l'âge d'entrée du niveau.
     *
     * @param list<string> $levelCodes
     */
    private function ageWarning(string $tenantId, string $establishmentId, array $levelCodes, string $birthDate, string $today): ?string
    {
        $configured = $this->settings->value($tenantId, $establishmentId, 'public_schools.entry_age.levels', self::DEFAULT_ENTRY_AGES);
        $ages = is_array($configured) ? $configured : self::DEFAULT_ENTRY_AGES;
        $expected = null;
        foreach ($levelCodes as $level) {
            if (isset($ages[$level]) && is_int($ages[$level])) {
                $expected = $expected === null ? $ages[$level] : min($expected, $ages[$level]);
            }
        }
        if ($expected === null) {
            return null;
        }
        $tolerance = $this->settings->value($tenantId, $establishmentId, 'public_schools.entry_age.tolerance', 0);
        $tolerance = is_int($tolerance) && $tolerance >= 0 ? $tolerance : 0;
        $age = (int) substr($today, 0, 4) - (int) substr($birthDate, 0, 4);

        return abs($age - $expected) > $tolerance ? 'age_out_of_range' : null;
    }

    private function classAlertThreshold(string $tenantId, string $establishmentId): int
    {
        $threshold = $this->settings->value($tenantId, $establishmentId, 'public_schools.class_size.alert', 60);

        return is_int($threshold) && $threshold > 0 ? $threshold : 60;
    }

    /** @param list<string> $warnings */
    private function store(string $tenantId, string $establishmentId, string $personId, string $number, PupilDraft $draft, array $warnings, ?string $reason, string $actorId, string $today): void
    {
        $pdo = $this->connections->pdo();
        $pdo->prepare(
            'INSERT INTO pub_pupil_registrations (id, tenant_id, establishment_id, person_id, learner_number, client_uuid, name_key, birth_date, sex, warnings, override_reason, registered_by, registered_on) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            (string) new Ulid(), $tenantId, $establishmentId, $personId, $number, $draft->clientUuid, IdentityKey::of($draft->lastName, $draft->firstName), $draft->birthDate,
            $draft->sex, implode(',', array_unique($warnings)), in_array('possible_duplicate', $warnings, true) ? trim((string) $reason) : null, $actorId, $today,
        ]);
        $pdo->prepare(
            'INSERT INTO pub_pupil_profiles (id, tenant_id, person_id, birth_act_ref, birth_act_verified, home_language, is_displaced, is_refugee, is_orphan, accommodation_type, updated_at) '
            . 'VALUES (?, ?, ?, ?, 0, NULL, 0, 0, 0, NULL, ?)',
        )->execute([(string) new Ulid(), $tenantId, $personId, $draft->birthActRef, $this->clock->now()]);
    }

    /** NOT-01 : les garants reçoivent l'identifiant ; un échec d'envoi n'annule jamais l'inscription. */
    private function notify(string $tenantId, string $establishmentId, string $personId, string $number, PupilDraft $draft): bool
    {
        $gateway = $this->notifier->gateway();
        if ($gateway === null) {
            return true;
        }
        try {
            $template = $this->settings->value($tenantId, $establishmentId, 'public_schools.messages.pupil_registered', self::NOTIFICATION_TEMPLATE);
            $gateway->notifyGuardians($tenantId, $establishmentId, $personId, is_string($template) ? $template : self::NOTIFICATION_TEMPLATE, [
                'child_name' => trim($draft->firstName . ' ' . $draft->lastName),
                'learner_number' => $number,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
