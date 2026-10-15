<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;
use Scholaris\Modules\Apps\Higher\Billing\Domain\PaymentTerms;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\BillingRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Grilles de frais par école, filière, niveau et mode jour/soir (EX-FIN-01) avec l'échéancier par défaut de la pension
 * (EX-FIN-02). Les montants référencent les frais du catalogue du socle par leur code stable (HigherFees, S10).
 * La grille appliquée à un étudiant est la plus précise : filière, puis niveau, puis mode.
 */
final class FeeGridService
{
    public const MODES = ['day', 'evening'];
    public const DEFAULT_TERMS_KEY = 'higher.billing.default_terms';

    public function __construct(
        private readonly BillingRepository $repository,
        private readonly BillingAccess $access,
        private readonly OrgUnitSettingsReader $settings,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array{academic_year_id: string, school_id: string, program_id: ?string, level_id: ?string, study_mode: ?string, label: string,
     *              fees: mixed, terms: mixed, start_year: ?int} $input
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $id = (string) new Ulid();
        $row = ['school_id' => $input['school_id'], 'program_id' => $input['program_id'], 'level_id' => $input['level_id']];
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_fee_grid', $id);

        $fees = self::fees($input['fees']);
        $terms = $this->terms($principal->tenantId, $establishmentId, $input['school_id'], $input['terms'], $input['start_year']);
        $duplicate = $this->repository->list(BillingRepository::GRIDS, $principal->tenantId, $establishmentId, [
            'academic_year_id' => $input['academic_year_id'], 'school_id' => $input['school_id'], 'program_id' => $input['program_id'],
            'level_id' => $input['level_id'], 'study_mode' => $input['study_mode'], 'status' => 'active',
        ], limit: 1);
        if ($duplicate !== []) {
            throw HttpException::conflict('Une grille active existe déjà pour cette école, filière, niveau et mode : archivez-la d\'abord.');
        }

        $now = $this->access->now();
        $this->repository->insert(BillingRepository::GRIDS, [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId,
            'academic_year_id' => $input['academic_year_id'], 'school_id' => $input['school_id'], 'program_id' => $input['program_id'],
            'level_id' => $input['level_id'], 'study_mode' => $input['study_mode'], 'label' => $input['label'],
            'fees_json' => json_encode($fees, JSON_THROW_ON_ERROR), 'terms_json' => json_encode($terms->toArray(), JSON_THROW_ON_ERROR),
            'status' => 'active', 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.fee_grid.created', 'hi_fee_grid', $id, null, ['fees' => $fees]);

        return $this->show($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);
        $row = $this->repository->find(BillingRepository::GRIDS, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Grille introuvable.');
        $this->access->assertReadable($perimeter, $row);

        return self::resource($row);
    }

    /**
     * @param array<string, ?string> $filters
     * @return list<array<string, mixed>>
     */
    public function index(Principal $principal, string $establishmentId, array $filters): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);
        $rows = $this->repository->list(BillingRepository::GRIDS, $principal->tenantId, $establishmentId, array_filter($filters, static fn (?string $v): bool => $v !== null), $this->access->filter($perimeter));

        return array_map(self::resource(...), $rows);
    }

    /** @return array<string, mixed> */
    public function archive(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $row = $this->repository->find(BillingRepository::GRIDS, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Grille introuvable.');
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_fee_grid', $id);
        $this->repository->update(BillingRepository::GRIDS, $principal->tenantId, $establishmentId, $id, ['status' => 'archived', 'updated_at' => $this->access->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.fee_grid.archived', 'hi_fee_grid', $id, ['status' => 'active'], ['status' => 'archived']);

        return $this->show($principal, $establishmentId, $id);
    }

    /**
     * Grille applicable (la plus précise) : même année et école, active ; filière, niveau et mode vides = toute valeur.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $tenantId, string $establishmentId, string $yearId, string $schoolId, ?string $programId, ?string $levelId, ?string $mode): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($this->repository->list(BillingRepository::GRIDS, $tenantId, $establishmentId, ['academic_year_id' => $yearId, 'school_id' => $schoolId, 'status' => 'active']) as $grid) {
            $score = 0;
            foreach ([['program_id', $programId, 4], ['level_id', $levelId, 2], ['study_mode', $mode, 1]] as [$column, $wanted, $weight]) {
                if ($grid[$column] === null) {
                    continue;
                }
                if ($grid[$column] !== $wanted) {
                    continue 2;
                }
                $score += $weight;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$grid, $score];
            }
        }

        return $best;
    }

    /**
     * @return list<array{fee_code: string, amount_xaf: int, due_on: ?string}>
     */
    private static function fees(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw) || count($raw) > 15) {
            throw HttpException::validation(['fees' => ['De 1 à 15 frais attendus.']]);
        }
        $fees = [];
        $codes = [];
        foreach ($raw as $index => $fee) {
            $code = is_array($fee) ? ($fee['fee_code'] ?? null) : null;
            $amount = is_array($fee) ? ($fee['amount_xaf'] ?? null) : null;
            $due = is_array($fee) ? ($fee['due_on'] ?? null) : null;
            if (!is_string($code) || !HigherFees::isKnown($code) || isset($codes[$code])) {
                throw HttpException::validation(['fees.' . $index => ['Code de frais inconnu ou répété. Codes : ' . implode(', ', array_keys(HigherFees::CATALOG)) . '.']]);
            }
            if (!is_int($amount) || $amount < 0 || $amount > 100000000) {
                throw HttpException::validation(['fees.' . $index => ['Montant entier en XAF attendu (0 à 100 000 000).']]);
            }
            if ($due !== null && (!is_string($due) || !PaymentTerms::isDate($due))) {
                throw HttpException::validation(['fees.' . $index => ['Date limite AAAA-MM-JJ attendue.']]);
            }
            $codes[$code] = true;
            $fees[] = ['fee_code' => $code, 'amount_xaf' => $amount, 'due_on' => $due];
        }

        return $fees;
    }

    private function terms(string $tenantId, string $establishmentId, string $schoolId, mixed $raw, ?int $startYear): PaymentTerms
    {
        try {
            if ($raw !== null) {
                return PaymentTerms::fromArray($raw);
            }
            if ($startYear === null) {
                throw new InvalidArgumentException('Sans échéancier explicite, indiquez start_year (année de rentrée) pour l\'échéancier par défaut.');
            }

            return PaymentTerms::defaultFor($startYear, $this->defaultModel($tenantId, $establishmentId, $schoolId));
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['terms' => [$e->getMessage()]]);
        }
    }

    /** @return list<array{0: string, 1: int}> modèle d'échéancier par défaut de l'école (paramètre), sinon 3 tranches du CDC */
    private function defaultModel(string $tenantId, string $establishmentId, string $schoolId): array
    {
        $raw = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, self::DEFAULT_TERMS_KEY, null);
        if (!is_array($raw) || $raw === []) {
            return PaymentTerms::DEFAULT;
        }
        $model = [];
        foreach ($raw as $entry) {
            $day = is_array($entry) ? ($entry['month_day'] ?? null) : null;
            $weight = is_array($entry) ? ($entry['weight'] ?? 1) : null;
            if (!is_string($day) || preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $day) !== 1 || !is_int($weight) || $weight < 1) {
                return PaymentTerms::DEFAULT;
            }
            $model[] = [$day, $weight];
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'school_id' => (string) $row['school_id'],
            'program_id' => $row['program_id'], 'level_id' => $row['level_id'], 'study_mode' => $row['study_mode'], 'label' => (string) $row['label'],
            'fees' => json_decode((string) $row['fees_json'], true), 'terms' => json_decode((string) $row['terms_json'], true),
            'status' => (string) $row['status'], 'created_at' => (string) $row['created_at'],
        ];
    }
}
