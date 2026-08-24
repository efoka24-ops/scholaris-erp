<?php

declare(strict_types=1);

namespace Scholaris\Platform;

use Scholaris\Database\Connection;
use Scholaris\Support\Cameroon;

/**
 * Chiffres du parc, tous etablissements confondus.
 *
 * Un tableau de bord n'a d'interet que si ses chiffres se comparent : « 2 847
 * eleves » ne dit rien, « 2 847 eleves, +24 ce mois » dit ou va le parc. Chaque
 * indicateur porte donc sa variation, calculee sur la periode precedente
 * equivalente.
 *
 * Toutes ces lectures traversent les etablissements : l'appelant doit se
 * placer hors scope, sans quoi le filtrage par etablissement les viderait.
 */
final class PlatformStats
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $monthStart = date('Y-m-01 00:00:00');
        $previousMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
        $today = date('Y-m-d');

        $students = $this->count('SELECT COUNT(*) FROM students WHERE deleted_at IS NULL');
        $studentsThisMonth = $this->count(
            'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND created_at >= :since',
            ['since' => $monthStart]
        );
        $studentsLastMonth = $this->count(
            'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND created_at >= :from AND created_at < :to',
            ['from' => $previousMonthStart, 'to' => $monthStart]
        );

        return [
            'students' => [
                'total' => $students,
                'thisMonth' => $studentsThisMonth,
                'trend' => $this->trend($studentsThisMonth, $studentsLastMonth),
            ],
            'attendance' => $this->attendanceToday($today),
            'requests' => $this->requests($monthStart, $previousMonthStart),
            'collection' => $this->collection($monthStart, $previousMonthStart),
            'tenants' => [
                'total' => $this->count('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL'),
                'active' => $this->count(
                    "SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND platform_status = 'ACTIVE'"
                ),
                'suspended' => $this->count(
                    "SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND platform_status = 'SUSPENDED'"
                ),
                'thisMonth' => $this->count(
                    'SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND created_at >= :since',
                    ['since' => $monthStart]
                ),
            ],
            'users' => [
                'total' => $this->count('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            ],
        ];
    }

    /**
     * Taux de presence du jour.
     *
     * Faute d'appel fait aujourd'hui, le taux n'est pas « 0 % » mais inconnu :
     * afficher zero laisserait croire a une desertion generale.
     *
     * @return array{rate: float|null, present: int, recorded: int, trend: float|null}
     */
    private function attendanceToday(string $today): array
    {
        $recorded = $this->count('SELECT COUNT(*) FROM attendances WHERE date = :date', ['date' => $today]);

        if ($recorded === 0) {
            return ['rate' => null, 'present' => 0, 'recorded' => 0, 'trend' => null];
        }

        $present = $this->count(
            "SELECT COUNT(*) FROM attendances WHERE date = :date AND status IN ('PRESENT', 'LATE')",
            ['date' => $today]
        );

        $rate = round($present / $recorded * 100, 1);

        // Comparaison a la veille, pour situer la journee.
        $yesterday = date('Y-m-d', strtotime($today.' -1 day'));
        $previousRecorded = $this->count('SELECT COUNT(*) FROM attendances WHERE date = :date', ['date' => $yesterday]);
        $trend = null;

        if ($previousRecorded > 0) {
            $previousPresent = $this->count(
                "SELECT COUNT(*) FROM attendances WHERE date = :date AND status IN ('PRESENT', 'LATE')",
                ['date' => $yesterday]
            );

            $trend = round($rate - $previousPresent / $previousRecorded * 100, 1);
        }

        return ['rate' => $rate, 'present' => $present, 'recorded' => $recorded, 'trend' => $trend];
    }

    /**
     * @return array<string, mixed>
     */
    private function requests(string $monthStart, string $previousMonthStart): array
    {
        $thisMonth = $this->count(
            'SELECT COUNT(*) FROM establishment_requests WHERE created_at >= :since',
            ['since' => $monthStart]
        );
        $lastMonth = $this->count(
            'SELECT COUNT(*) FROM establishment_requests WHERE created_at >= :from AND created_at < :to',
            ['from' => $previousMonthStart, 'to' => $monthStart]
        );

        return [
            'pending' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'PENDING'"
            ),
            'approved' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'APPROVED'"
            ),
            'rejected' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'REJECTED'"
            ),
            'thisMonth' => $thisMonth,
            'trend' => $this->trend($thisMonth, $lastMonth),
            // Le dossier le plus ancien encore en attente : c'est lui qui
            // mesure le retard d'instruction.
            'oldestPendingDays' => $this->oldestPendingDays(),
        ];
    }

    private function oldestPendingDays(): ?int
    {
        $oldest = $this->db->scalar(
            "SELECT MIN(created_at) FROM establishment_requests WHERE request_status = 'PENDING'"
        );

        if (! is_string($oldest) || $oldest === '') {
            return null;
        }

        $timestamp = strtotime($oldest);

        if ($timestamp === false) {
            return null;
        }

        return (int) floor((time() - $timestamp) / 86400);
    }

    /**
     * Encaissements du mois, tous etablissements.
     *
     * @return array<string, mixed>
     */
    private function collection(string $monthStart, string $previousMonthStart): array
    {
        $thisMonth = $this->money(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deleted_at IS NULL AND paid_at >= :since',
            ['since' => $monthStart]
        );
        $lastMonth = $this->money(
            'SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE deleted_at IS NULL AND paid_at >= :from AND paid_at < :to',
            ['from' => $previousMonthStart, 'to' => $monthStart]
        );

        $invoiced = $this->money('SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE deleted_at IS NULL');
        $collected = $this->money('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deleted_at IS NULL');

        return [
            'thisMonth' => $thisMonth,
            'trend' => $this->trend((int) round($thisMonth), (int) round($lastMonth)),
            'rate' => $invoiced > 0.0 ? round($collected / $invoiced * 100, 1) : null,
            'outstanding' => max(0.0, $invoiced - $collected),
        ];
    }

    /**
     * Repartition du parc par region, pour la carte.
     *
     * Les etablissements et les demandes en attente y figurent ensemble : voir
     * ou l'on est deja implante et ou l'on est sollicite est precisement ce
     * qu'une carte apporte de plus qu'un tableau.
     *
     * @return list<array<string, mixed>>
     */
    public function byRegion(): array
    {
        $regions = [];

        foreach (Cameroon::regions() as $code => $region) {
            $point = Cameroon::regionPoint($code);

            $regions[$code] = [
                'code' => $code,
                'name' => $region['name'],
                'capital' => $region['capital'],
                'x' => $point['x'],
                'y' => $point['y'],
                'tenants' => [],
                'pending' => [],
                'students' => 0,
            ];
        }

        $tenants = $this->db->select(
            'SELECT t.id, t.code, t.name, t.type, t.region, t.city, t.platform_status,
                    (SELECT COUNT(*) FROM students s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students_count
             FROM tenants t
             WHERE t.deleted_at IS NULL
             ORDER BY t.name'
        );

        $unlocated = ['tenants' => [], 'pending' => []];

        foreach ($tenants as $tenant) {
            $region = (string) ($tenant['region'] ?? '');

            if (! isset($regions[$region])) {
                $unlocated['tenants'][] = $tenant;

                continue;
            }

            $regions[$region]['tenants'][] = $tenant;
            $regions[$region]['students'] += (int) $tenant['students_count'];
        }

        $pending = $this->db->select(
            "SELECT id, code, name, type, region, city, reference, created_at
             FROM establishment_requests
             WHERE request_status = 'PENDING'
             ORDER BY created_at"
        );

        foreach ($pending as $demand) {
            $region = (string) ($demand['region'] ?? '');

            if (! isset($regions[$region])) {
                $unlocated['pending'][] = $demand;

                continue;
            }

            $regions[$region]['pending'][] = $demand;
        }

        return ['regions' => array_values($regions), 'unlocated' => $unlocated];
    }

    /**
     * Repartition des comptes par profil.
     *
     * Un compte cree n'est pas un compte utilise : la difference entre les
     * deux est precisement ce qu'un administrateur national doit surveiller.
     * Un directeur qui n'a jamais ouvert son espace n'a pas recu ses
     * identifiants, ou ne s'en sert pas — dans les deux cas il faut l'appeler.
     *
     * Le profil est deduit du role porte par le compte. Un compte sans role
     * n'est rattache a rien : il figure a part plutot que d'etre range de
     * force dans une categorie.
     *
     * @return array<string, mixed>
     */
    public function accountsByProfile(): array
    {
        $groups = [
            'DIRECTION' => ['label' => 'Direction', 'roles' => ['Admin Établissement', 'Directeur', 'Censeur']],
            'PERSONNEL' => ['label' => 'Personnel', 'roles' => [
                'Enseignant', 'Chef de département', 'Intendant', 'Secrétaire',
                'Infirmier(ère)', 'Bibliothécaire', 'Surveillant général',
            ]],
            'ELEVE' => ['label' => 'Eleves', 'roles' => ['Élève']],
            'PARENT' => ['label' => 'Parents et tuteurs', 'roles' => ['Parent']],
            'PLATEFORME' => ['label' => 'Administration plateforme', 'roles' => ['SUPER_ADMIN']],
        ];

        $rows = $this->db->select(
            "SELECT r.name AS role_name,
                    COUNT(*) AS created,
                    SUM(CASE WHEN u.last_login IS NOT NULL THEN 1 ELSE 0 END) AS activated,
                    SUM(CASE WHEN u.status <> 'ACTIVE' THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN u.created_at >= :since THEN 1 ELSE 0 END) AS recent,
                    SUM(CASE WHEN u.last_login IS NULL OR u.last_login < :stale THEN 1 ELSE 0 END) AS dormant
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL
             GROUP BY r.name",
            [
                'since' => date('Y-m-d H:i:s', strtotime('-30 days')),
                'stale' => date('Y-m-d H:i:s', strtotime('-30 days')),
            ]
        );

        $byRole = [];

        foreach ($rows as $row) {
            $byRole[(string) $row['role_name']] = $row;
        }

        $profiles = [];
        $accountedRoles = [];

        foreach ($groups as $code => $group) {
            $totals = ['created' => 0, 'activated' => 0, 'suspended' => 0, 'recent' => 0, 'dormant' => 0];

            foreach ($group['roles'] as $roleName) {
                $accountedRoles[] = $roleName;

                if (! isset($byRole[$roleName])) {
                    continue;
                }

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += (int) $byRole[$roleName][$key];
                }
            }

            $profiles[$code] = $totals + [
                'code' => $code,
                'label' => $group['label'],
                'activation_rate' => $totals['created'] > 0
                    ? round($totals['activated'] / $totals['created'] * 100, 1)
                    : null,
            ];
        }

        // Les roles hors nomenclature ne doivent pas disparaitre du compte :
        // un total qui ne tombe pas juste fait douter de tout le tableau.
        $others = ['created' => 0, 'activated' => 0, 'suspended' => 0, 'recent' => 0, 'dormant' => 0];

        foreach ($byRole as $roleName => $row) {
            if (in_array($roleName, $accountedRoles, true)) {
                continue;
            }

            foreach (array_keys($others) as $key) {
                $others[$key] += (int) $row[$key];
            }
        }

        if ($others['created'] > 0) {
            $profiles['AUTRES'] = $others + [
                'code' => 'AUTRES',
                'label' => 'Autres roles',
                'activation_rate' => round($others['activated'] / $others['created'] * 100, 1),
            ];
        }

        $withoutRole = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users u
             WHERE u.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)'
        );

        $total = array_sum(array_column($profiles, 'created')) + $withoutRole;
        $activated = array_sum(array_column($profiles, 'activated'));

        return [
            'profiles' => $profiles,
            'withoutRole' => $withoutRole,
            'total' => $total,
            'activated' => $activated,
            'activationRate' => $total > 0 ? round($activated / $total * 100, 1) : null,
            'topTenants' => $this->db->select(
                'SELECT t.name, t.code, COUNT(u.id) AS accounts
                 FROM tenants t
                 JOIN users u ON u.tenant_id = t.id AND u.deleted_at IS NULL
                 WHERE t.deleted_at IS NULL
                 GROUP BY t.id, t.name, t.code
                 ORDER BY accounts DESC
                 LIMIT 5'
            ),
        ];
    }

    /**
     * Variation en pourcentage entre deux periodes.
     *
     * Partir de zero n'a pas de variation calculable : mieux vaut ne rien
     * afficher qu'un « +100 % » qui ne veut rien dire.
     */
    private function trend(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /** @param  array<string, mixed>  $params */
    private function count(string $sql, array $params = []): int
    {
        return (int) $this->db->scalar($sql, $params);
    }

    /** @param  array<string, mixed>  $params */
    private function money(string $sql, array $params = []): float
    {
        return (float) $this->db->scalar($sql, $params);
    }
}
