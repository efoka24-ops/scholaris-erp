<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Application\CarteScolaireImports;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Application\CarteScolaireSettings;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Application\NationalIdRegistry;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Application\Roster;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\CarteScolaireDefaults;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\IdentityKey;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Échanges par fichiers avec cartescolaire.cm (noyau, RG-CSC-1) : matricules des élèves (saisie, import, liste à régulariser),
 * écarts, relevé des paiements de l'économe (API-03). Lecture des matricules : `secondary.enrol.read` ou `.enrol.manage` ;
 * écriture : `secondary.enrol.manage` (scolarité). Paiements : lecture par les droits des frais, import par
 * `secondary.billing.manage` (intendant). Toutes les écritures exigent tout l'établissement. Aucun appel réseau.
 */
final class CarteScolaireController extends Controller
{
    public const STUDENTS_READ = ['secondary.enrol.read', 'secondary.enrol.manage'];
    public const STUDENTS_WRITE = ['secondary.enrol.manage'];
    public const PAYMENTS_READ = ['secondary.fees.read', 'secondary.finance.read', 'secondary.billing.manage', 'secondary.enrol.manage', 'secondary.exams.lists'];
    public const PAYMENTS_WRITE = ['secondary.billing.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly NationalIdRegistry $registry,
        private readonly CarteScolaireImports $imports,
        private readonly CarteScolaireSettings $settings,
        private readonly Roster $roster,
        private readonly Listing $listing,
    ) {
        parent::__construct($responder, $access, $store);
    }

    /** Paramètres en vigueur des fichiers (colonnes, séparateur, format du matricule, niveaux d'entrée), tous « à confirmer ». */
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, [...self::STUDENTS_READ, ...self::PAYMENTS_READ]);
        $tenantId = $principal->tenantId;

        return $this->json([
            'national_id_pattern' => $this->settings->pattern($tenantId, $establishmentId),
            'csv_delimiter' => $this->settings->delimiter($tenantId, $establishmentId),
            'columns' => [
                'national_ids' => $this->settings->columns($tenantId, $establishmentId, CarteScolaireImports::NATIONAL_IDS, CarteScolaireDefaults::COLUMNS_NATIONAL_IDS),
                'payments' => $this->settings->columns($tenantId, $establishmentId, CarteScolaireImports::PAYMENTS, CarteScolaireDefaults::COLUMNS_PAYMENTS),
            ],
            'entry_levels' => $this->settings->entryLevels($tenantId, $establishmentId),
            'max_bytes' => CarteScolaireDefaults::MAX_BYTES,
            'network' => false,
            'confirmation' => CarteScolaireDefaults::CONFIRMATION,
        ]);
    }

    /**
     * Élèves inscrits et état de leur matricule ; `status=pending|recorded|mismatch`, `entry_only=1` : niveaux d'entrée seulement
     * (liste des matricules à générer par l'établissement, EX-CSC-02). Curseur de position.
     */
    public function students(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::STUDENTS_READ);
        $tenantId = $principal->tenantId;
        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? null, [NationalIdRegistry::PENDING, NationalIdRegistry::RECORDED, NationalIdRegistry::MISMATCH], true) ? (string) $params['status'] : null;
        $entryOnly = in_array($params['entry_only'] ?? '0', ['1', 'true'], true);
        $query = ListQuery::fromRequest($request, [], []);
        $registered = $this->registry->all($tenantId, $establishmentId);
        $students = $this->roster->students($tenantId, $establishmentId);
        $levels = $entryOnly ? $this->roster->levelNames($tenantId, $establishmentId, array_map('strval', array_keys($students))) : [];
        $entryLevels = $entryOnly ? $this->settings->entryLevels($tenantId, $establishmentId) : [];
        $rows = [];
        foreach ($students as $personId => $student) {
            $row = $registered[$personId] ?? null;
            $state = $row === null ? NationalIdRegistry::PENDING : (string) $row['id_status'];
            if ($state === NationalIdRegistry::RECORDED && ($row['national_id'] ?? null) === null) {
                $state = NationalIdRegistry::PENDING;
            }
            if (($status !== null && $state !== $status)) {
                continue;
            }
            $level = $levels[$personId] ?? null;
            if ($entryOnly && ($level === null || !in_array(IdentityKey::name($level), $entryLevels, true))) {
                continue;
            }
            $rows[] = [
                'person_id' => (string) $personId,
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'birth_date' => $student['birth_date'],
                'national_id' => $row['national_id'] ?? null,
                'national_id_status' => $state,
                'origin' => $row['origin'] ?? null,
                'recorded_at' => $row['recorded_at'] ?? null,
                'level' => $entryOnly ? $level : null,
            ];
        }
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        $page = array_slice($rows, $offset, $query->perPage);
        $next = $offset + $query->perPage < count($rows) ? ListQuery::encodeCursor((string) ($offset + $query->perPage)) : null;

        return $this->page(['data' => $page, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage, 'total' => count($rows)]]);
    }

    /** Saisie manuelle du matricule (API-02 côté carte scolaire) : l'élève est désigné par son identifiant interne, jamais par le matricule. */
    public function recordNationalId(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::STUDENTS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_national_id');
        $input = Input::of($request);
        $nationalId = $input->string('national_id', 40);
        $input->done();
        $personId = self::id($request);
        if (!array_key_exists($personId, $this->roster->students($principal->tenantId, $establishmentId))) {
            throw HttpException::notFound('Élève introuvable dans cet établissement.');
        }
        $before = $this->registry->of($principal->tenantId, $establishmentId, $personId);
        $recorded = $this->registry->record($principal->tenantId, $establishmentId, $personId, (string) $nationalId, 'manual', $principal->userId);
        $this->access->audit($principal, 'secondary.national_id.recorded', 'secondary_student', $personId, ['national_id' => $before], ['national_id' => $recorded]);

        return $this->json(['person_id' => $personId, 'national_id' => $recorded, 'national_id_status' => NationalIdRegistry::RECORDED]);
    }

    public function importNationalIds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::STUDENTS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_cs_import');
        [$fileName, $content] = self::file($request);
        [$import, $created] = $this->imports->importNationalIds($principal->tenantId, $establishmentId, $fileName, $content, $principal->userId);
        $created && $this->access->audit($principal, 'secondary.cs_import.national_ids', 'secondary_cs_import', (string) $import['id'], null, ['rows_total' => $import['rows_total'], 'rows_ok' => $import['rows_ok']]);

        return $this->json($import + ['replayed' => !$created], $created ? 201 : 200);
    }

    /** API-03 : relevé des paiements constatés sur cartescolaire.cm, extrait par l'économe. */
    public function importPayments(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::PAYMENTS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_cs_import');
        [$fileName, $content] = self::file($request);
        [$import, $created] = $this->imports->importPayments($principal->tenantId, $establishmentId, $fileName, $content, $principal->userId);
        $created && $this->access->audit($principal, 'secondary.cs_import.payments', 'secondary_cs_import', (string) $import['id'], null, ['rows_total' => $import['rows_total'], 'rows_ok' => $import['rows_ok'], 'rows_unknown' => $import['rows_unknown']]);

        return $this->json($import + ['replayed' => !$created], $created ? 201 : 200);
    }

    public function imports(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, [...self::STUDENTS_READ, ...self::PAYMENTS_READ]);

        return $this->page($this->listing->page($request, 'se_cs_imports', $principal->tenantId, $establishmentId, ['import_kind'], ['created_at'], 'created_at DESC', static fn (array $row): array => CarteScolaireImports::view($row)));
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, [...self::STUDENTS_READ, ...self::PAYMENTS_READ]);
        $import = $this->imports->show($principal->tenantId, $establishmentId, self::id($request));
        $discrepancies = array_map(
            static fn (array $row): array => CarteScolaireImports::discrepancyView($row),
            $this->store->select('se_cs_discrepancies', $principal->tenantId, $establishmentId, ['import_id' => $import['id']], 'created_at', 1000),
        );

        return $this->json($import + ['discrepancies' => $discrepancies]);
    }

    /** Écarts (`filter[discrepancy_kind]`, `filter[person_id]`, `resolved=0|1`) : contrôle de cohérence EX-CSC-05. */
    public function discrepancies(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::STUDENTS_READ);
        $resolved = $request->getQueryParams()['resolved'] ?? null;
        $scope = match ($resolved) {
            '0', 'false' => ['resolved_at IS NULL', []],
            '1', 'true' => ['resolved_at IS NOT NULL', []],
            default => ['1 = 1', []],
        };

        return $this->page($this->listing->page($request, 'se_cs_discrepancies', $principal->tenantId, $establishmentId, ['discrepancy_kind', 'person_id', 'import_id'], ['created_at'], 'created_at DESC', static fn (array $row): array => CarteScolaireImports::discrepancyView($row), [], $scope));
    }

    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::STUDENTS_WRITE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_cs_discrepancy');
        $input = Input::of($request);
        $note = $input->string('note', 255);
        $apply = $input->bool('apply_national_id', false);
        $personId = $input->reference('person_id', false);
        $input->done();
        $id = self::id($request);
        $discrepancy = $this->imports->resolve($principal->tenantId, $establishmentId, $id, (string) $note, $apply === true, $personId, $principal->userId);
        $this->access->audit($principal, 'secondary.cs_discrepancy.resolved', 'secondary_cs_discrepancy', $id, null, ['note' => $note, 'applied' => $apply === true]);

        return $this->json($discrepancy);
    }

    /** Paiements constatés (`filter[match_status]`, `filter[payment_kind]`, `filter[matched_person_id]`, `filter[import_id]`). */
    public function payments(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::PAYMENTS_READ);

        return $this->page($this->listing->page(
            $request,
            'se_cs_payments',
            $principal->tenantId,
            $establishmentId,
            ['match_status', 'payment_kind', 'matched_person_id', 'import_id', 'exam_code'],
            ['paid_on', 'created_at'],
            'paid_on DESC',
            static fn (array $row): array => [
                'id' => (string) $row['id'], 'import_id' => (string) $row['import_id'], 'national_id' => (string) $row['national_id'],
                'payment_kind' => (string) $row['payment_kind'], 'exam_code' => $row['exam_code'], 'amount' => (int) $row['amount'],
                'paid_on' => (string) $row['paid_on'], 'external_ref' => (string) $row['external_ref'],
                'matched_person_id' => $row['matched_person_id'], 'match_status' => (string) $row['match_status'],
            ],
        ));
    }

    /** @return array{0: string, 1: string} nom et contenu texte du fichier (CSV UTF-8) */
    private static function file(ServerRequestInterface $request): array
    {
        $input = Input::of($request);
        $name = $input->string('file_name', 160);
        $content = $input->custom('content', static function (mixed $value): string {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Contenu du fichier CSV attendu (texte UTF-8).');
            }
            if (strlen($value) > CarteScolaireDefaults::MAX_BYTES) {
                throw new \InvalidArgumentException(sprintf('Fichier trop volumineux (%d octets au plus).', CarteScolaireDefaults::MAX_BYTES));
            }

            return $value;
        });
        $input->done();

        return [(string) $name, (string) $content];
    }
}
