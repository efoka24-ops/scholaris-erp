<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Canteen\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Cantine et santé scolaire (CAN, T687) :
 *  - repas servis par jour et par classe (EX-CAN-01), saisis par le responsable de la cantine (R17) ; un effectif servi supérieur à
 *    celui de la classe est signalé, jamais refusé (repas d'enseignants, d'invités) ;
 *  - stocks reçus et consommés (entrées et sorties, solde par denrée ; une sortie au-delà du solde est refusée) ;
 *  - campagnes de santé (déparasitage, vaccination, moustiquaires…) saisies EN EFFECTIFS par l'agent de santé scolaire (R18), avec le
 *    nombre de consentements des parents : aucun enfant n'est compté traité sans consentement (traités ≤ consentements). Aucune
 *    donnée nominative ni médicale (CDC : minimisation, pas de dossier médical).
 * Aucune règle officielle publiée (recherche MINEDUB, §4) : types de campagne en paramètre `public_schools.health.campaign_kinds`.
 */
final class CanteenService
{
    public const READ = 'public_schools.canteen.read';
    public const WRITE = 'public_schools.canteen.write';
    public const KINDS_KEY = 'public_schools.health.campaign_kinds';
    public const DEFAULT_KINDS = ['deworming', 'vaccination', 'bednets', 'vitamin_a', 'other'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ScopedByTerritory $scope,
        private readonly ClassRoster $classes,
        private readonly SettingsReader $settings,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly AuditLog $audit,
    ) {
    }

    public function reader(Principal $principal, string $establishmentId): Perimeter
    {
        foreach ([self::WRITE, self::READ] as $permission) {
            $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
            if (!$perimeter->isEmpty()) {
                return $perimeter;
            }
        }

        throw HttpException::notFound();
    }

    public function writer(Principal $principal, string $establishmentId, string $objectType): void
    {
        $perimeter = $this->scope->perimeter($principal, self::WRITE, $establishmentId);
        if ($perimeter->isEmpty()) {
            $this->scope->assertWritable($principal, $perimeter, null, $objectType, $establishmentId);
        }
    }

    /** @return list<array<string, mixed>> repas du jour, par classe */
    public function meals(Principal $principal, string $establishmentId, ?string $day): array
    {
        $this->reader($principal, $establishmentId);
        $day ??= $this->today();
        $query = $this->pdo()->prepare('SELECT * FROM pub_meals WHERE tenant_id = ? AND establishment_id = ? AND day_on = ? ORDER BY structure_id');
        $query->execute([$principal->tenantId, $establishmentId, $day]);

        return array_map(fn (array $row): array => $this->mealResource($principal->tenantId, $row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Repas d'un jour (remplace la saisie de ces classes pour ce jour).
     *
     * @param array<string, mixed> $input lines [{structure_id, served_count}]
     * @return array{meals: list<array<string, mixed>>, warnings: list<string>}
     */
    public function saveMeals(Principal $principal, string $establishmentId, string $day, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_meal');
        $tenantId = $principal->tenantId;
        $errors = [];
        if (!self::isDate($day)) {
            $errors['date'][] = 'Date AAAA-MM-JJ attendue.';
        }
        $lines = $input['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            $errors['lines'][] = 'Lignes {structure_id, served_count} requises.';
            $lines = [];
        }
        foreach ($lines as $index => $line) {
            if (!is_array($line) || !is_string($line['structure_id'] ?? null) || $this->classes->find($tenantId, $establishmentId, (string) $line['structure_id']) === null) {
                $errors['lines.' . $index . '.structure_id'][] = 'Classe inconnue dans cette école.';
            }
            if (!is_array($line) || !is_int($line['served_count'] ?? null) || $line['served_count'] < 0) {
                $errors['lines.' . $index . '.served_count'][] = 'Entier positif ou nul attendu.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $warnings = [];
        $now = $this->now();
        foreach ($lines as $line) {
            $structureId = (string) $line['structure_id'];
            $served = (int) $line['served_count'];
            if ($served > $this->classes->headcount($tenantId, $establishmentId, $structureId, $day)) {
                $warnings[] = 'served_above_headcount:' . $structureId;
            }
            $existing = $this->pdo()->prepare('SELECT id FROM pub_meals WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND day_on = ?');
            $existing->execute([$tenantId, $establishmentId, $structureId, $day]);
            $id = $existing->fetchColumn();
            if (is_string($id)) {
                $this->pdo()->prepare('UPDATE pub_meals SET served_count = ?, recorded_by = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')->execute([$served, $principal->userId, $now, $tenantId, $id]);
            } else {
                $this->pdo()->prepare('INSERT INTO pub_meals (id, tenant_id, establishment_id, structure_id, day_on, served_count, recorded_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(string) new Ulid(), $tenantId, $establishmentId, $structureId, $day, $served, $principal->userId, $now, $now]);
            }
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.canteen.meals_recorded', 'pub_meal', $establishmentId . ':' . $day, null, ['lines' => count($lines)]);

        return ['meals' => $this->meals($principal, $establishmentId, $day), 'warnings' => $warnings];
    }

    /**
     * Synthèse mensuelle des repas (AAAA-MM) : total, jours de service, par classe.
     *
     * @return array<string, mixed>
     */
    public function summary(Principal $principal, string $establishmentId, ?string $month): array
    {
        $this->reader($principal, $establishmentId);
        $month ??= substr($this->today(), 0, 7);
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois AAAA-MM attendu.']]);
        }
        $query = $this->pdo()->prepare('SELECT structure_id, COUNT(*) AS days, SUM(served_count) AS served FROM pub_meals WHERE tenant_id = ? AND establishment_id = ? AND day_on LIKE ? GROUP BY structure_id ORDER BY structure_id');
        $query->execute([$principal->tenantId, $establishmentId, $month . '-%']);
        $classes = [];
        $total = 0;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total += (int) $row['served'];
            $classes[] = ['structure_id' => (string) $row['structure_id'], 'class_name' => $this->classes->find($principal->tenantId, $establishmentId, (string) $row['structure_id'])->label ?? '', 'days' => (int) $row['days'], 'served' => (int) $row['served']];
        }
        $days = $this->pdo()->prepare('SELECT COUNT(DISTINCT day_on) FROM pub_meals WHERE tenant_id = ? AND establishment_id = ? AND day_on LIKE ?');
        $days->execute([$principal->tenantId, $establishmentId, $month . '-%']);

        return ['month' => $month, 'service_days' => (int) $days->fetchColumn(), 'served_total' => $total, 'classes' => $classes];
    }

    /** @return list<array<string, mixed>> */
    public function stockMoves(Principal $principal, string $establishmentId): array
    {
        $this->reader($principal, $establishmentId);
        $query = $this->pdo()->prepare('SELECT * FROM pub_canteen_stock_moves WHERE tenant_id = ? AND establishment_id = ? ORDER BY id');
        $query->execute([$principal->tenantId, $establishmentId]);

        return array_map(static fn (array $row): array => self::moveResource($row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return list<array{item_label: string, unit_label: string, received: int, consumed: int, balance: int}> */
    public function balance(Principal $principal, string $establishmentId): array
    {
        $this->reader($principal, $establishmentId);

        return $this->balances($principal->tenantId, $establishmentId);
    }

    /**
     * @param array<string, mixed> $input item_label, unit_label, direction (in|out), quantity, moved_on, source_label?, note?
     * @return array<string, mixed>
     */
    public function addMove(Principal $principal, string $establishmentId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_canteen_stock');
        $errors = [];
        foreach (['item_label' => 120, 'unit_label' => 30] as $field => $max) {
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '' || mb_strlen($input[$field]) > $max) {
                $errors[$field][] = 'Texte requis (' . $max . ' caractères au plus).';
            }
        }
        if (!in_array($input['direction'] ?? null, ['in', 'out'], true)) {
            $errors['direction'][] = 'Valeur attendue : in ou out.';
        }
        if (!is_int($input['quantity'] ?? null) || $input['quantity'] < 1) {
            $errors['quantity'][] = 'Entier positif attendu.';
        }
        if (!self::isDate($input['moved_on'] ?? null)) {
            $errors['moved_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        foreach (['source_label' => 160, 'note' => 500] as $field => $max) {
            if (($input[$field] ?? null) !== null && (!is_string($input[$field]) || mb_strlen($input[$field]) > $max)) {
                $errors[$field][] = 'Texte de ' . $max . ' caractères au plus.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $item = trim((string) $input['item_label']);
        if ($input['direction'] === 'out') {
            $available = 0;
            foreach ($this->balances($principal->tenantId, $establishmentId) as $line) {
                if (mb_strtolower($line['item_label']) === mb_strtolower($item)) {
                    $available = $line['balance'];
                }
            }
            if ((int) $input['quantity'] > $available) {
                throw HttpException::conflict('Sortie supérieure au stock disponible (' . $available . ').');
            }
        }
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO pub_canteen_stock_moves (id, tenant_id, establishment_id, item_label, unit_label, direction, quantity, moved_on, source_label, note, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $principal->tenantId, $establishmentId, $item, trim((string) $input['unit_label']), $input['direction'], $input['quantity'], $input['moved_on'], $input['source_label'] ?? null, $input['note'] ?? null, $principal->userId, $this->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.canteen.stock_moved', 'pub_canteen_stock', $id, null, ['direction' => $input['direction'], 'quantity' => $input['quantity']]);
        $query = $this->pdo()->prepare('SELECT * FROM pub_canteen_stock_moves WHERE tenant_id = ? AND id = ?');
        $query->execute([$principal->tenantId, $id]);

        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::moveResource($row) : throw HttpException::notFound();
    }

    /** @return list<array<string, mixed>> */
    public function campaigns(Principal $principal, string $establishmentId): array
    {
        $this->reader($principal, $establishmentId);
        $query = $this->pdo()->prepare('SELECT * FROM pub_health_campaigns WHERE tenant_id = ? AND establishment_id = ? ORDER BY held_on DESC, id');
        $query->execute([$principal->tenantId, $establishmentId]);

        return array_map(static fn (array $row): array => self::campaignResource($row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param array<string, mixed> $input campaign_kind, held_on, label?, girls_count, boys_count, consents_count, refusals_count?
     * @return array<string, mixed>
     */
    public function addCampaign(Principal $principal, string $establishmentId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_health_campaign');
        $kinds = $this->settings->value($principal->tenantId, $establishmentId, self::KINDS_KEY, self::DEFAULT_KINDS);
        $kinds = is_array($kinds) && $kinds !== [] ? array_values(array_filter($kinds, 'is_string')) : self::DEFAULT_KINDS;
        $errors = [];
        if (!in_array($input['campaign_kind'] ?? null, $kinds, true)) {
            $errors['campaign_kind'][] = 'Valeur attendue : ' . implode(', ', $kinds) . '.';
        }
        if (!self::isDate($input['held_on'] ?? null)) {
            $errors['held_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        if (($input['label'] ?? null) !== null && (!is_string($input['label']) || mb_strlen($input['label']) > 160)) {
            $errors['label'][] = 'Texte de 160 caractères au plus.';
        }
        foreach (['girls_count', 'boys_count', 'consents_count', 'refusals_count'] as $field) {
            $value = $input[$field] ?? ($field === 'refusals_count' ? 0 : null);
            if (!is_int($value) || $value < 0) {
                $errors[$field][] = 'Effectif entier positif ou nul attendu.';
            }
        }
        if ($errors === [] && (int) $input['girls_count'] + (int) $input['boys_count'] > (int) $input['consents_count']) {
            $errors['consents_count'][] = 'Aucun enfant n\'est traité sans le consentement de ses parents : traités ≤ consentements (EX-CAN-02).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO pub_health_campaigns (id, tenant_id, establishment_id, campaign_kind, held_on, label, girls_count, boys_count, consents_count, refusals_count, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $principal->tenantId, $establishmentId, $input['campaign_kind'], $input['held_on'], $input['label'] ?? null, $input['girls_count'], $input['boys_count'], $input['consents_count'], $input['refusals_count'] ?? 0, $principal->userId, $this->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.health.campaign_recorded', 'pub_health_campaign', $id, null, ['kind' => $input['campaign_kind']]);
        $query = $this->pdo()->prepare('SELECT * FROM pub_health_campaigns WHERE tenant_id = ? AND id = ?');
        $query->execute([$principal->tenantId, $id]);

        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::campaignResource($row) : throw HttpException::notFound();
    }

    /** @return list<array{item_label: string, unit_label: string, received: int, consumed: int, balance: int}> */
    private function balances(string $tenantId, string $establishmentId): array
    {
        $query = $this->pdo()->prepare('SELECT item_label, unit_label, direction, quantity FROM pub_canteen_stock_moves WHERE tenant_id = ? AND establishment_id = ? ORDER BY id');
        $query->execute([$tenantId, $establishmentId]);
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower((string) $row['item_label']);
            $items[$key] ??= ['item_label' => (string) $row['item_label'], 'unit_label' => (string) $row['unit_label'], 'received' => 0, 'consumed' => 0, 'balance' => 0];
            $items[$key][$row['direction'] === 'in' ? 'received' : 'consumed'] += (int) $row['quantity'];
            $items[$key]['balance'] = $items[$key]['received'] - $items[$key]['consumed'];
        }
        ksort($items);

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mealResource(string $tenantId, array $row): array
    {
        return [
            'id' => (string) $row['id'], 'structure_id' => (string) $row['structure_id'],
            'class_name' => $this->classes->find($tenantId, (string) $row['establishment_id'], (string) $row['structure_id'])->label ?? '',
            'day_on' => (string) $row['day_on'], 'served_count' => (int) $row['served_count'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function moveResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'item_label' => (string) $row['item_label'], 'unit_label' => (string) $row['unit_label'], 'direction' => (string) $row['direction'],
            'quantity' => (int) $row['quantity'], 'moved_on' => (string) $row['moved_on'], 'source_label' => $row['source_label'], 'note' => $row['note'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function campaignResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'campaign_kind' => (string) $row['campaign_kind'], 'held_on' => (string) $row['held_on'], 'label' => $row['label'],
            'girls_count' => (int) $row['girls_count'], 'boys_count' => (int) $row['boys_count'], 'treated_count' => (int) $row['girls_count'] + (int) $row['boys_count'],
            'consents_count' => (int) $row['consents_count'], 'refusals_count' => (int) $row['refusals_count'],
        ];
    }

    private function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone((string) $this->config->get('app.timezone', 'Africa/Douala')));
    }

    private function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    private function today(): string
    {
        return $this->moment()->format('Y-m-d');
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
