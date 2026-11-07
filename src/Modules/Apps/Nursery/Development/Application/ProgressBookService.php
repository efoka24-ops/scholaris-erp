<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Carnet de suivi trimestriel (EX-MAT-17, US11) : brouillon rédigé par l'éducatrice, soumis, VALIDÉ par la directrice, publié aux parents
 * puis figé. Un carnet non validé est invisible du parent ; un terme à éviter bloque la soumission et la validation. Le carnet ne porte
 * AUCUN calcul : la matière est le dernier niveau observé par item sur le trimestre, rendue telle quelle. Le modèle imprimable (PDF)
 * attend un carnet réel de l'école cible (MQ-14) : seul le contenu structuré est livré.
 */
final class ProgressBookService
{
    public const TABLE = 'nu_progress_books';
    public const TEMPLATE_PUBLISHED = 'nursery.not06.progress_book';
    public const PORTAL_LINK = '/maternelle-privee/famille';

    public function __construct(
        private readonly Store $store,
        private readonly ObservationService $observations,
        private readonly ChildDirectory $children,
        private readonly CurrentPeriod $periods,
        private readonly TermsGuard $terms,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, period_id?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $today = $this->clock->today();
        $candidates = $this->periods->allAt($tenantId, $establishmentId, $today);
        $period = null;
        foreach ($candidates as $candidate) {
            if (isset($data['period_id']) ? $candidate->id === (string) $data['period_id'] : $candidate->kind === 'trimester') {
                $period = $candidate;
                break;
            }
        }
        if ($period === null) {
            throw HttpException::validation(['period_id' => ['Aucune période en cours ne correspond : le carnet porte sur le trimestre courant.']]);
        }
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'period_id' => $period->id]) > 0) {
            throw HttpException::conflict('Un carnet existe déjà pour cet enfant et cette période.');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $snapshot = $this->observations->latestByItem($tenantId, $establishmentId, $learnerId, $period->startsOn, min($period->endsOn, $today));
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'structure_id' => $section, 'period_id' => $period->id, 'academic_year_id' => $period->academicYearId, 'status' => 'draft',
            'content' => json_encode(['synthesis' => '', 'domains' => new \stdClass()], JSON_THROW_ON_ERROR), 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'author_id' => $principal->userId, 'submitted_at' => null, 'validated_by' => null, 'validated_at' => null, 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.progress_book.created', 'progress_book', $id, null, ['learner_id' => $learnerId, 'period_id' => $period->id]);

        return $this->resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $data synthesis?, domains?: array<string, string>
     * @return array<string, mixed>
     */
    public function write(Principal $principal, Perimeter $perimeter, string $id, array $data): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] !== 'draft') {
            throw HttpException::conflict('Seul un brouillon se rédige : demandez à la directrice de le renvoyer.');
        }
        $content = $this->content($row);
        if (isset($data['synthesis'])) {
            $content['synthesis'] = (string) $data['synthesis'];
        }
        if (isset($data['domains'])) {
            $content['domains'] = (array) $data['domains'];
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => $this->clock->now()], ['id' => $id]);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function submit(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        $this->expect($row, ['draft'], 'soumis');
        $this->assertWording($perimeter, $row);
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'submitted', 'submitted_at' => $this->clock->now(), 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.progress_book.submitted', 'progress_book', $id);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function validate(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        $this->expect($row, ['submitted'], 'validé');
        $this->assertWording($perimeter, $row);
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->access->audit($principal, 'nursery.progress_book.validated', 'progress_book', $id);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> renvoie un carnet soumis ou validé à l'éducatrice (jamais un carnet publié) */
    public function sendBack(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        $this->expect($row, ['submitted', 'validated'], 'renvoyé');
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'draft', 'validated_by' => null, 'validated_at' => null, 'submitted_at' => null, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.progress_book.returned', 'progress_book', $id);

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function publish(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        $this->expect($row, ['validated'], 'publié');
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'published', 'published_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $this->access->audit($principal, 'nursery.progress_book.published', 'progress_book', $id);
        $child = $this->people->find($perimeter->tenantId, (string) $row['learner_id']);
        foreach ($this->people->guarantorsOf($perimeter->tenantId, (string) $row['learner_id']) as $guardian) {
            $this->messenger->send($perimeter->tenantId, $perimeter->establishmentId, $guardian->id, self::TEMPLATE_PUBLISHED, [
                'child_name' => $child !== null ? $child->fullName() : '', 'period_label' => (string) $row['period_id'], 'link' => self::PORTAL_LINK,
            ]);
        }

        return $this->resource($this->store->findOrFail(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function get(Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Carnet introuvable.');
        $this->access->assertReadable($perimeter, (string) $row['structure_id']);

        return $this->resource($row);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Perimeter $perimeter, Paging $paging, ?string $sectionId, ?string $status, ?string $learnerId): array
    {
        $where = array_filter(['structure_id' => $sectionId, 'status' => $status, 'learner_id' => $learnerId], static fn (?string $v): bool => $v !== null);
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $where, 'updated_at DESC, id', 2000) as $row) {
            if ($perimeter->coversSection((string) $row['structure_id'])) {
                $rows[] = $this->resource($row);
            }
        }

        return $paging->slice($rows);
    }

    /**
     * Carnets PUBLIÉS d'un enfant, pour son parent (un carnet non validé et publié est invisible).
     *
     * @return list<array<string, mixed>>
     */
    public function publishedFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $rows = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'status' => 'published'], 'published_at DESC, id', 100) as $row) {
            $rows[] = $this->resource($row);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Carnet introuvable.');
        $this->access->assertWritable($principal, $perimeter, (string) $row['structure_id'], 'progress_book', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $allowed
     */
    private function expect(array $row, array $allowed, string $action): void
    {
        if (!in_array((string) $row['status'], $allowed, true)) {
            throw HttpException::conflict('Ce carnet ne peut pas être ' . $action . ' dans l’état « ' . $row['status'] . ' ».');
        }
    }

    /** @param array<string, mixed> $row */
    private function assertWording(Perimeter $perimeter, array $row): void
    {
        $content = $this->content($row);
        $text = trim((string) $content['synthesis'] . ' ' . implode(' ', array_map('strval', (array) $content['domains'])));
        $this->terms->assertClean($perimeter->tenantId, $perimeter->establishmentId, 'content', $text);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{synthesis: string, domains: array<string, string>}
     */
    private function content(array $row): array
    {
        $decoded = $row['content'] !== null ? json_decode((string) $row['content'], true, 16, JSON_THROW_ON_ERROR) : [];
        $domains = is_array($decoded) && is_array($decoded['domains'] ?? null) ? array_map('strval', $decoded['domains']) : [];

        return ['synthesis' => is_array($decoded) ? (string) ($decoded['synthesis'] ?? '') : '', 'domains' => $domains];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        $content = $this->content($row);
        /** @var list<array<string, mixed>> $snapshot */
        $snapshot = $row['snapshot'] !== null ? json_decode((string) $row['snapshot'], true, 16, JSON_THROW_ON_ERROR) : [];

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'section_id' => (string) $row['structure_id'], 'period_id' => (string) $row['period_id'],
            'academic_year_id' => (string) $row['academic_year_id'], 'status' => (string) $row['status'], 'synthesis' => $content['synthesis'], 'domains' => (object) $content['domains'],
            'observations' => $snapshot, 'submitted_at' => $row['submitted_at'], 'validated_at' => $row['validated_at'], 'published_at' => $row['published_at'],
        ];
    }
}
