<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Observation du développement (EX-MAT-16, RG-MAT-1) : « un enfant, un item du référentiel actif, un niveau », sans note, sans
 * moyenne, sans total ni rang. Le niveau doit figurer parmi ceux de `nursery.dev.levels` (sinon 422) ; un terme de la liste à éviter
 * bloque la remarque. Une observation n'est jamais modifiée ; hors ligne, `client_uuid` rend l'écriture idempotente.
 */
final class ObservationService
{
    public const TABLE = 'nu_observations';

    public function __construct(
        private readonly Store $store,
        private readonly FrameService $frames,
        private readonly ChildDirectory $children,
        private readonly SectionService $sections,
        private readonly PersonDirectory $people,
        private readonly NurserySettings $settings,
        private readonly TermsGuard $terms,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data learner_id, item_id, level_code, remark?, observed_on?, client_uuid?
     * @return array{observation: array<string, mixed>, duplicate: bool}
     */
    public function record(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $clientUuid = isset($data['client_uuid']) ? (string) $data['client_uuid'] : null;
        if ($clientUuid !== null) {
            $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['client_uuid' => $clientUuid], 'id', 1)[0] ?? null;
            if ($existing !== null) {
                return ['observation' => self::resource($existing), 'duplicate' => true];
            }
        }
        $levels = $this->settings->list($tenantId, $establishmentId, NurserySettings::DEV_LEVELS);
        if ($levels === []) {
            throw HttpException::validation(['level_code' => ['Les niveaux d’observation ne sont pas paramétrés (nursery.dev.levels).']]);
        }
        $level = (string) $data['level_code'];
        if (!in_array($level, $levels, true)) {
            throw HttpException::validation(['level_code' => ['Niveau attendu parmi : ' . implode(', ', $levels) . '.']]);
        }
        $target = $this->frames->activeItem($tenantId, $establishmentId, (string) $data['item_id']);
        if ($target === null) {
            throw HttpException::validation(['item_id' => ['Item introuvable dans le référentiel actif de l’école.']]);
        }
        $day = (string) ($data['observed_on'] ?? $this->clock->today());
        if ($day > $this->clock->today()) {
            throw HttpException::validation(['observed_on' => ['Une observation ne peut pas être datée dans le futur.']]);
        }
        $remark = isset($data['remark']) ? trim((string) $data['remark']) : null;
        if ($remark !== null && $remark !== '') {
            $this->terms->assertClean($tenantId, $establishmentId, 'remark', $remark);
        }
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'structure_id' => $section, 'frame_id' => (string) $target['frame']['id'], 'domain_id' => (string) $target['domain']['id'],
            'item_id' => (string) $target['item']['id'], 'level_code' => $level, 'remark' => $remark === '' ? null : $remark, 'observed_on' => $day,
            'observer_id' => $principal->userId, 'client_uuid' => $clientUuid, 'created_at' => $this->clock->now(),
        ]);

        return ['observation' => self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id)), 'duplicate' => false];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Perimeter $perimeter, Paging $paging, ?string $learnerId, ?string $sectionId): array
    {
        $where = [];
        if ($learnerId !== null) {
            $this->children->assertInPerimeter($perimeter, $learnerId);
            $where['learner_id'] = $learnerId;
        }
        if ($sectionId !== null) {
            $this->sections->get($perimeter, $sectionId);
            $where['structure_id'] = $sectionId;
        }
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $where, 'observed_on DESC, id', 5000) as $row) {
            if ($perimeter->coversSection((string) $row['structure_id'])) {
                $rows[] = self::resource($row);
            }
        }

        return $paging->slice($rows);
    }

    /**
     * Nombre d'écarts de fréquence d'une section pour un mois (rappels hebdomadaires du cron).
     */
    public function gapCount(string $tenantId, string $establishmentId, string $sectionId, string $month): int
    {
        $perimeter = new Perimeter($tenantId, $establishmentId, true);

        return count($this->gaps($perimeter, $sectionId, $month));
    }

    /**
     * Suivi de la fréquence : pour chaque enfant de la section et chaque domaine du référentiel actif, le nombre d'observations du mois
     * comparé au minimum de `nursery.dev.min_observations_per_domain_per_month`. Ne liste que les écarts ; aucune comparaison entre enfants.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function coverage(Perimeter $perimeter, Paging $paging, string $sectionId, string $month): array
    {
        $this->sections->get($perimeter, $sectionId);

        return $paging->slice($this->gaps($perimeter, $sectionId, $month));
    }

    /** @return list<array<string, mixed>> */
    private function gaps(Perimeter $perimeter, string $sectionId, string $month): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $required = $this->settings->int($tenantId, $establishmentId, NurserySettings::DEV_MIN_OBSERVATIONS);
        if ($required === null) {
            return [];
        }
        $counts = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['structure_id' => $sectionId], 'observed_on, id', 20000) as $row) {
            if (str_starts_with((string) $row['observed_on'], $month)) {
                $key = (string) $row['learner_id'] . '|' . (string) $row['domain_id'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $domains = $this->frames->activeDomains($tenantId, $establishmentId);
        $rows = [];
        $people = $this->people->findMany($tenantId, $this->sections->childIds($tenantId, $sectionId));
        usort($people, static fn ($a, $b): int => [mb_strtolower($a->lastName), mb_strtolower($a->firstName)] <=> [mb_strtolower($b->lastName), mb_strtolower($b->firstName)]);
        foreach ($people as $child) {
            foreach ($domains as $domain) {
                $count = $counts[$child->id . '|' . (string) $domain['id']] ?? 0;
                if ($count < $required) {
                    $rows[] = [
                        'learner_id' => $child->id, 'full_name' => $child->fullName(), 'domain_id' => (string) $domain['id'], 'domain_code' => (string) $domain['code'],
                        'domain_label_fr' => (string) $domain['label_fr'], 'domain_label_en' => (string) $domain['label_en'], 'observations' => $count, 'required' => $required,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Dernier niveau observé par item pour un enfant entre deux dates (matière du carnet) : pas de calcul, la donnée telle quelle.
     *
     * @return list<array{item_id: string, domain_id: string, level_code: string, observed_on: string, remark: ?string}>
     */
    public function latestByItem(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): array
    {
        $latest = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId], 'observed_on, created_at, id', 5000) as $row) {
            if ((string) $row['observed_on'] >= $from && (string) $row['observed_on'] <= $to) {
                $latest[(string) $row['item_id']] = ['item_id' => (string) $row['item_id'], 'domain_id' => (string) $row['domain_id'], 'level_code' => (string) $row['level_code'], 'observed_on' => (string) $row['observed_on'], 'remark' => $row['remark'] !== null ? (string) $row['remark'] : null];
            }
        }

        return array_values($latest);
    }

    /** @return list<string> enfants sans observation depuis le jour indiqué (rappels hebdomadaires) */
    public function learnersWithoutObservationSince(string $tenantId, string $establishmentId, string $since): array
    {
        $seen = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'observed_on, id', 50000) as $row) {
            if ((string) $row['observed_on'] >= $since) {
                $seen[(string) $row['learner_id']] = true;
            }
        }
        $missing = [];
        foreach ($this->sections->inPerimeter(new Perimeter($tenantId, $establishmentId, true)) as $section) {
            foreach ($this->sections->childIds($tenantId, (string) $section['id']) as $childId) {
                if (!isset($seen[$childId])) {
                    $missing[] = $childId;
                }
            }
        }

        return $missing;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'section_id' => (string) $row['structure_id'], 'domain_id' => (string) $row['domain_id'],
            'item_id' => (string) $row['item_id'], 'level_code' => (string) $row['level_code'], 'remark' => $row['remark'] !== null ? (string) $row['remark'] : null,
            'observed_on' => (string) $row['observed_on'], 'observer_id' => (string) $row['observer_id'],
        ];
    }
}
