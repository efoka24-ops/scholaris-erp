<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberRequest;
use Scholaris\Modules\Socle\People\Domain\Completeness;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;
use Scholaris\Modules\Socle\People\Domain\Majority;
use Scholaris\Modules\Socle\People\Infrastructure\EnrollmentNumberProviders;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/**
 * Inscriptions : brouillon enregistré en continu, validation (autorisation de la structure, gardes-fous des applications, pièces,
 * garant), statuts. Points d'extension (vague 0 du Supérieur) : gardes-fous d'inscription (D3, Organisation\EnrolmentGuards),
 * numéro d'inscription d'une application (D5, EnrollmentNumberProvider), double cursus par le paramètre
 * `enrolment.allow_multiple_tracks` (D7 : une inscription vivante par filière et par année au lieu d'une seule). Sans application
 * enregistrée ni paramètre, le comportement est inchangé.
 */
final class EnrollmentService
{
    public const MULTIPLE_TRACKS = 'enrolment.allow_multiple_tracks';

    public function __construct(
        private readonly PdoStore $store,
        private readonly PeopleClock $clock,
        private readonly EnrolmentGate $gate,
        private readonly SettingsReader $settings,
        private readonly SequenceService $sequences,
        private readonly DocumentService $documents,
        private readonly RelationService $relations,
        private readonly PersonService $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly EnrolmentGuards $guards,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentNumberProviders $numbers,
    ) {
    }

    /** @return array<string, mixed> */
    public function createDraft(string $tenantId, string $establishmentId, string $personId, ?string $structureId, ?string $yearId, ?string $actorId): array
    {
        $this->people->get($tenantId, $personId);
        $this->assertStructureOpen($tenantId, $establishmentId, $structureId);
        $this->assertNoLiveEnrollment($tenantId, $establishmentId, $personId, $yearId, null, $structureId);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('enrollments', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'establishment_id' => $establishmentId,
            'structure_id' => $structureId, 'academic_year_id' => $yearId, 'status' => EnrollmentStatus::Draft->value,
            'internal_number' => null, 'completeness' => 0, 'documents_due_on' => null, 'created_by' => $actorId,
            'validated_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->refresh($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $changes structure_id, academic_year_id
     * @return array<string, mixed>
     */
    public function updateDraft(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== EnrollmentStatus::Draft->value) {
            throw HttpException::conflict('Seul un brouillon peut être modifié. Changez le statut de l\'inscription.');
        }
        $structure = array_key_exists('structure_id', $changes) ? $this->nullableString($changes['structure_id']) : $this->nullableString($row['structure_id']);
        $year = array_key_exists('academic_year_id', $changes) ? $this->nullableString($changes['academic_year_id']) : $this->nullableString($row['academic_year_id']);
        if ($structure !== $this->nullableString($row['structure_id'])) {
            $this->assertStructureOpen($tenantId, $establishmentId, $structure);
        }
        $this->assertNoLiveEnrollment($tenantId, $establishmentId, (string) $row['person_id'], $year, $id, $structure);
        $this->store->update('enrollments', $tenantId, $id, ['structure_id' => $structure, 'academic_year_id' => $year, 'updated_at' => $this->clock->now()]);

        return $this->refresh($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->find('enrollments', $tenantId, $id);
        if ($row === null || $row['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Inscription introuvable.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function validate(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== EnrollmentStatus::Draft->value) {
            throw HttpException::conflict('Cette inscription est déjà validée.');
        }
        $errors = [];
        $structure = $this->nullableString($row['structure_id']);
        $year = $this->nullableString($row['academic_year_id']);
        if ($structure === null) {
            $errors['structure_id'] = ['Choisissez une filière avant de valider.'];
        }
        if ($year === null) {
            $errors['academic_year_id'] = ['Choisissez l\'année scolaire avant de valider.'];
        }
        $personId = (string) $row['person_id'];
        $person = $this->people->get($tenantId, $personId);
        if (!Majority::isAdult($this->nullableString($person['birth_date']), $this->clock->today())
            && !$this->relations->hasGuarantor($tenantId, $personId)) {
            $errors['person_id'] = ['Ajoutez un parent, un tuteur ou un garant financier pour cet apprenant mineur.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Le dossier est incomplet.');
        }
        $this->assertStructureOpen($tenantId, $establishmentId, $structure);
        $reason = $this->guards->refusal($tenantId, $establishmentId, (string) $structure, $year, $personId, new DateTimeImmutable('@' . $this->clock->timestamp()), $id);
        if ($reason !== null) {
            throw HttpException::validation(['structure_id' => [$reason]], 'Inscription refusée.');
        }
        $this->assertNoLiveEnrollment($tenantId, $establishmentId, $personId, $year, $id, $structure);

        $missing = $this->documents->completeness($tenantId, $personId)['missing'];
        $grace = max(0, (int) $this->settings->value($tenantId, $establishmentId, 'enrolment.documents_grace_days', 30));
        $status = $missing === [] ? EnrollmentStatus::Active : EnrollmentStatus::Conditional;
        $yearNumber = (int) substr($this->clock->today(), 0, 4);
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $name = $establishment->name ?? 'Établissement';
        $numbering = new EnrollmentNumberRequest($tenantId, $establishmentId, $establishment->type ?? '', $id, $personId, (string) $structure, (string) $year, $yearNumber);

        $this->sequences->transaction(function () use ($tenantId, $establishmentId, $id, $row, $personId, $status, $missing, $grace, $yearNumber, $name, $numbering): void {
            $number = $this->providedNumber($numbering)
                ?? SequenceService::format('MAT', $yearNumber, $this->sequences->next($tenantId, $establishmentId, 'enrollment', $yearNumber), 5);
            $this->store->update('enrollments', $tenantId, $id, [
                'status' => $status->value,
                'internal_number' => $number,
                'documents_due_on' => $missing === [] ? null : $this->clock->inDays($grace),
                'validated_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);
            if ($this->store->where('person_identifiers', $tenantId, ['person_id' => $personId, 'kind' => 'internal', 'establishment_id' => $establishmentId, 'value' => $number], 'id', 1) === []) {
                $this->people->storeIdentifier($tenantId, $personId, 'internal', $establishmentId, $number);
            }
            $this->store->insert('schooling_history', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'person_id' => $personId, 'enrollment_id' => $id,
                'school_name' => $name, 'level_label' => null, 'year_label' => $row['academic_year_id'],
                'outcome' => null, 'source' => 'enrollment', 'created_at' => $this->clock->now(),
            ]);
        });

        return $this->refresh($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function changeStatus(string $tenantId, string $establishmentId, string $id, EnrollmentStatus $target): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        $current = EnrollmentStatus::from((string) $row['status']);
        if (!in_array($target, $current->allowedNext(), true)) {
            throw HttpException::conflict(sprintf('Passage de « %s » à « %s » impossible.', $current->value, $target->value));
        }
        if ($target === EnrollmentStatus::Active && $current === EnrollmentStatus::Suspended) {
            $this->assertStructureOpen($tenantId, $establishmentId, $this->nullableString($row['structure_id']));
        }
        $this->store->update('enrollments', $tenantId, $id, [
            'status' => $target->value,
            'documents_due_on' => $target === EnrollmentStatus::Conditional ? $row['documents_due_on'] : null,
            'updated_at' => $this->clock->now(),
        ]);

        return $this->refresh($tenantId, $id);
    }

    public function deleteDraft(string $tenantId, string $establishmentId, string $id): void
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== EnrollmentStatus::Draft->value) {
            throw HttpException::conflict('Seul un brouillon peut être supprimé : une inscription validée se clôt par un changement de statut.');
        }
        $this->store->delete('enrollments', $tenantId, ['id' => $id]);
    }

    /**
     * @param list<string>|null $restrictTo personnes visibles ; null = toutes
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [[], null];
        }
        $where = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $where[] = 'id > ?';
            $params[] = $after;
        }
        foreach (['person_id', 'status', 'structure_id', 'academic_year_id'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if ($restrictTo !== null) {
            $where[] = 'person_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->store->rows(sprintf('SELECT * FROM enrollments WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /** @return list<array<string, mixed>> toutes les inscriptions d'une personne dans le client */
    public function forPerson(string $tenantId, string $personId): array
    {
        return $this->store->where('enrollments', $tenantId, ['person_id' => $personId]);
    }

    /** @return list<array<string, mixed>> */
    public function historyOf(string $tenantId, string $personId): array
    {
        return $this->store->where('schooling_history', $tenantId, ['person_id' => $personId]);
    }

    /**
     * Recalcule et enregistre le taux de complétude.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $tenantId, string $id): array
    {
        $row = $this->store->find('enrollments', $tenantId, $id) ?? throw HttpException::notFound('Inscription introuvable.');
        $personId = (string) $row['person_id'];
        $docs = $this->documents->completeness($tenantId, $personId);
        $percent = Completeness::percent(
            $this->people->get($tenantId, $personId),
            $this->relations->hasGuarantor($tenantId, $personId),
            $row['structure_id'] !== null,
            $docs['received'],
            $docs['required'],
        );
        if ($percent !== (int) $row['completeness']) {
            $this->store->update('enrollments', $tenantId, $id, ['completeness' => $percent]);
            $row['completeness'] = $percent;
        }

        return $row;
    }

    private function assertStructureOpen(string $tenantId, string $establishmentId, ?string $structureId): void
    {
        if ($structureId === null) {
            return;
        }
        $today = new DateTimeImmutable('@' . $this->clock->timestamp());
        if (!$this->gate->canEnrol($tenantId, $establishmentId, $structureId, $today)) {
            throw HttpException::validation(['structure_id' => ['Inscription impossible : cette filière n\'a pas d\'autorisation valide à ce jour.']]);
        }
    }

    /**
     * Une seule inscription vivante par personne, établissement et année. Avec `enrolment.allow_multiple_tracks` (D7, double cursus),
     * une par filière : deux inscriptions ne se gênent que si elles visent la même filière (structure elle-même ou filière ascendante),
     * ou si l'une n'a pas encore de structure.
     */
    private function assertNoLiveEnrollment(string $tenantId, string $establishmentId, string $personId, ?string $yearId, ?string $exceptId, ?string $structureId = null): void
    {
        if ($yearId === null) {
            return;
        }
        $perTrack = $structureId !== null && $this->settings->value($tenantId, $establishmentId, self::MULTIPLE_TRACKS, false) === true;
        $track = $perTrack ? $this->trackKey($tenantId, $establishmentId, (string) $structureId) : null;
        foreach ($this->store->where('enrollments', $tenantId, ['person_id' => $personId, 'establishment_id' => $establishmentId, 'academic_year_id' => $yearId]) as $other) {
            if ($other['id'] === $exceptId || !EnrollmentStatus::from((string) $other['status'])->isLive()) {
                continue;
            }
            if ($perTrack && $other['structure_id'] !== null && $this->trackKey($tenantId, $establishmentId, (string) $other['structure_id']) !== $track) {
                continue;
            }
            throw HttpException::conflict($perTrack
                ? 'Cette personne a déjà une inscription en cours dans cette filière pour cette année.'
                : 'Cette personne a déjà une inscription en cours dans cet établissement pour cette année.');
        }
    }

    /** Filière d'une structure (elle-même ou son ascendante de nature filière), à défaut la structure. */
    private function trackKey(string $tenantId, string $establishmentId, string $structureId): string
    {
        return $this->tree->trackOf($tenantId, $establishmentId, $structureId) ?? $structureId;
    }

    /** Numéro fourni par une application (D5) ; null = numéro du socle. */
    private function providedNumber(EnrollmentNumberRequest $request): ?string
    {
        foreach ($this->numbers->all() as $provider) {
            if ($provider->supports($request)) {
                $number = trim($provider->next($request));
                if ($number === '' || mb_strlen($number) > 40) {
                    throw new \RuntimeException('Le fournisseur de numéro d\'inscription a rendu un numéro vide ou trop long (40 caractères au plus).');
                }

                return $number;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
