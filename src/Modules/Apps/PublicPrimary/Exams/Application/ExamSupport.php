<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Lectures communes des services d'examen : session, liste, candidat enrichi de l'identité (registre des élèves de PublicSchools,
 * jamais de donnée APEE), libellés du catalogue paramétré et émission des documents DOC-13 à DOC-17.
 */
final class ExamSupport
{
    public function __construct(
        private readonly PdoExams $repository,
        private readonly PupilRegistry $pupils,
        private readonly ClassRoster $classes,
        private readonly SchoolRegistry $schools,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly Rules $rules,
    ) {
    }

    /** @return array<string, mixed> */
    public function session(string $tenantId, string $sessionId): array
    {
        return $this->repository->session($tenantId, $sessionId) ?? throw HttpException::notFound('Session d\'examen introuvable.');
    }

    /**
     * Motif obligatoire (renvoi, retrait), lu APRÈS le contrôle de droit.
     *
     * @param array<string, mixed> $input
     */
    public static function reason(array $input): string
    {
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Motif requis (500 caractères au plus).']]);
        }

        return trim($reason);
    }

    /** @return array<string, array<string, mixed>> catalogue paramétré des examens (clé => libellés, sous-système, niveaux, nature) */
    public function catalogue(string $tenantId, string $establishmentId): array
    {
        $catalogue = [];
        foreach ($this->rules->array($tenantId, $establishmentId, 'public_primary.exams.catalogue') as $code => $entry) {
            if (is_string($code) && is_array($entry)) {
                $catalogue[$code] = $entry;
            }
        }

        return $catalogue;
    }

    /** Année civile de l'examen : seconde année du libellé AAAA-AAAA. */
    public static function examYear(string $yearLabel): int
    {
        return (int) substr($yearLabel, -4);
    }

    /**
     * @param array<string, mixed> $session
     * @return list<string>
     */
    public static function levels(array $session): array
    {
        return array_values(array_filter(explode(',', (string) $session['level_codes']), static fn (string $level): bool => $level !== ''));
    }

    /** @param array<string, mixed> $session */
    public function examLabel(array $session): string
    {
        return (string) $session['label'] . ' ' . self::examYear((string) $session['year_label']);
    }

    public function pupil(string $tenantId, string $personId): ?PupilInfo
    {
        return $this->pupils->find($tenantId, $personId);
    }

    public function schoolCode(string $tenantId, string $establishmentId): string
    {
        return $this->schools->find($tenantId, $establishmentId)->officialCode ?? $establishmentId;
    }

    public function className(string $tenantId, string $establishmentId, ?string $structureId): string
    {
        return $structureId === null ? '' : ($this->classes->find($tenantId, $establishmentId, $structureId)->label ?? '');
    }

    /**
     * Candidat tel que le voient l'école et la supervision : identité minimale (identifiant national, nom, sexe, date de naissance,
     * référence et vérification de l'acte), état, centre et résultat (seulement s'il est publié, sauf `$withUnpublished`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function candidate(string $tenantId, array $row, bool $withUnpublished = false): array
    {
        $pupil = $this->pupils->find($tenantId, (string) $row['person_id']);
        $published = $row['result_published_at'] !== null;

        return [
            'id' => (string) $row['id'], 'session_id' => (string) $row['session_id'], 'establishment_id' => (string) $row['establishment_id'],
            'person_id' => (string) $row['person_id'], 'learner_number' => $pupil?->learnerNumber,
            'last_name' => $pupil->lastName ?? '', 'first_name' => $pupil->firstName ?? '', 'sex' => $pupil?->sex, 'birth_date' => $pupil?->birthDate,
            'birth_act_ref' => $pupil?->birthActRef, 'birth_act_verified' => $pupil->birthActVerified ?? false,
            'structure_id' => $row['structure_id'], 'level_code' => $row['level_code'], 'state' => (string) $row['candidate_state'],
            'blocked_reason' => $row['blocked_reason'], 'added_after_freeze' => (int) $row['added_after_freeze'] === 1, 'added_reason' => $row['added_reason'],
            'withdrawn_reason' => $row['withdrawn_reason'], 'candidate_number' => $row['candidate_number'], 'centre_id' => $row['centre_id'], 'sub_centre_id' => $row['sub_centre_id'],
            'outcome' => $published || $withUnpublished ? $row['outcome'] : null, 'result_published' => $published,
            'attestation_state' => $row['attestation_state'], 'attestation_document_id' => $row['attestation_document_id'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function sessionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'exam_code' => (string) $row['exam_code'], 'year_label' => (string) $row['year_label'], 'label' => (string) $row['label'],
            'subsystem' => (string) $row['subsystem'], 'level_codes' => self::levels($row), 'kind' => (string) $row['exam_kind'],
            'written_on' => $row['written_on'], 'state' => (string) $row['session_state'],
        ];
    }

    /** @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    public static function listResource(?array $row): ?array
    {
        return $row === null ? null : [
            'id' => (string) $row['id'], 'establishment_id' => (string) $row['establishment_id'], 'session_id' => (string) $row['session_id'],
            'state' => (string) $row['list_state'], 'current_step' => $row['current_step'], 'return_reason' => $row['return_reason'],
            'frozen_at' => $row['frozen_at'], 'amendments_count' => (int) $row['amendments_count'],
        ];
    }

    /**
     * Document émis par l'acteur (liste, relevé : la personne liée au compte en est le sujet) ou au nom d'un élève (fiche, attestation).
     *
     * @param array<string, scalar|null> $data
     */
    public function issue(Principal $principal, string $establishmentId, string $type, ?string $subjectPersonId, array $data, string $locale): IssuedDocument
    {
        $subject = $subjectPersonId ?? $this->users->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);

        return $this->documents->issue($principal->tenantId, $establishmentId, $type, $subject, $data, $principal->userId, $locale === 'en' ? 'en' : 'fr');
    }
}
