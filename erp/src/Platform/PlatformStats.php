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

    private Scope $scope;

    public function __construct(Connection $db, ?Scope $scope = null)
    {
        $this->db = $db;
        $this->scope = $scope ?? Scope::platform();
    }

    /**
     * Restriction portee par la table des etablissements elle-meme.
     *
     * Rendue sous forme de « AND ... » prete a coller a une clause existante :
     * les vingt-huit requetes de ce fichier ont toutes deja un WHERE.
     */
    private function scopeTenants(string $alias = 'tenants'): string
    {
        return ' AND '.$this->scope->condition($alias)['sql'];
    }

    /** Restriction portee par une colonne tenant_id d'une autre table. */
    private function scopeVia(string $column): string
    {
        return ' AND '.$this->scope->conditionOnTenantColumn($column)['sql'];
    }

    /**
     * Restriction des demandes d'ouverture.
     *
     * Une demande n'appartient a aucun etablissement — elle en cree un. Elle se
     * rattache donc a la region declaree par le demandeur. Un compte cantonne a
     * un etablissement n'a rien a voir ici : instruire les demandes n'est pas
     * son role.
     */
    private function scopeRequests(): string
    {
        return match ($this->scope->type()) {
            // Le ministere de tutelle se deduit du type demande : une demande
            // n'a pas encore de rattachement, mais un lycee technique relevera
            // du MINESEC quoi qu'il arrive.
            Scope::MINISTRY => " AND type IN ".$this->typesOfMinistry(),
            Scope::REGION => ' AND region = :scope_region',
            Scope::DEPARTMENT => ' AND LOWER(city) = :scope_department',
            Scope::TENANT => ' AND 1 = 0',
            default => '',
        };
    }

    /**
     * Types d'etablissement relevant de la tutelle du perimetre.
     *
     * Rendu en clair plutot qu'en parametres lies : les valeurs viennent d'une
     * table de constantes du code, jamais d'une saisie.
     */
    private function typesOfMinistry(): string
    {
        $ministry = (string) $this->scope->value();
        $types = [];

        foreach (['PRIMAIRE', 'COLLEGE', 'LYCEE_GENERAL', 'LYCEE_TECHNIQUE', 'CENTRE_FORMATION', 'SUPERIEUR'] as $type) {
            if (Cameroon::ministryForType($type) === $ministry) {
                $types[] = "'".$type."'";
            }
        }

        // Aucune correspondance : la clause doit exclure, pas tout laisser
        // passer.
        return $types === [] ? "('')" : '('.implode(', ', $types).')';
    }

    /**
     * Parametres du perimetre, restreints a ceux que la requete utilise.
     *
     * MySQL refuse un parametre lie qui n'apparait pas dans la requete : les
     * fournir systematiquement ferait echouer toutes celles qui n'ont pas de
     * clause de perimetre.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withScope(string $sql, array $params): array
    {
        foreach (array_merge(
            $this->scope->condition()['params'],
            $this->scope->conditionOnTenantColumn('x')['params']
        ) as $name => $value) {
            if (str_contains($sql, ':'.$name)) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $monthStart = date('Y-m-01 00:00:00');
        $previousMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
        $today = date('Y-m-d');

        $students = $this->count('SELECT COUNT(*) FROM students WHERE deleted_at IS NULL'.$this->scopeVia('tenant_id'));
        $studentsThisMonth = $this->count(
            'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND created_at >= :since'.$this->scopeVia('tenant_id'),
            ['since' => $monthStart]
        );
        $studentsLastMonth = $this->count(
            'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL AND created_at >= :from AND created_at < :to'.$this->scopeVia('tenant_id'),
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
                'total' => $this->count('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL'.$this->scopeTenants()),
                'active' => $this->count(
                    "SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND platform_status = 'ACTIVE'".$this->scopeTenants()
                ),
                'suspended' => $this->count(
                    "SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND platform_status = 'SUSPENDED'".$this->scopeTenants()
                ),
                'thisMonth' => $this->count(
                    'SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND created_at >= :since'.$this->scopeTenants(),
                    ['since' => $monthStart]
                ),
            ],
            'users' => [
                'total' => $this->count('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'.$this->scopeVia('tenant_id')),
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
        $recorded = $this->count('SELECT COUNT(*) FROM attendances WHERE date = :date'.$this->scopeVia('tenant_id'), ['date' => $today]);

        if ($recorded === 0) {
            return ['rate' => null, 'present' => 0, 'recorded' => 0, 'trend' => null];
        }

        $present = $this->count(
            "SELECT COUNT(*) FROM attendances WHERE date = :date AND status IN ('PRESENT', 'LATE')".$this->scopeVia('tenant_id'),
            ['date' => $today]
        );

        $rate = round($present / $recorded * 100, 1);

        // Comparaison a la veille, pour situer la journee.
        $yesterday = date('Y-m-d', strtotime($today.' -1 day'));
        $previousRecorded = $this->count('SELECT COUNT(*) FROM attendances WHERE date = :date'.$this->scopeVia('tenant_id'), ['date' => $yesterday]);
        $trend = null;

        if ($previousRecorded > 0) {
            $previousPresent = $this->count(
                "SELECT COUNT(*) FROM attendances WHERE date = :date AND status IN ('PRESENT', 'LATE')".$this->scopeVia('tenant_id'),
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
            'SELECT COUNT(*) FROM establishment_requests WHERE created_at >= :since'.$this->scopeRequests(),
            ['since' => $monthStart]
        );
        $lastMonth = $this->count(
            'SELECT COUNT(*) FROM establishment_requests WHERE created_at >= :from AND created_at < :to'.$this->scopeRequests(),
            ['from' => $previousMonthStart, 'to' => $monthStart]
        );

        return [
            'pending' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'PENDING'".$this->scopeRequests()
            ),
            'approved' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'APPROVED'".$this->scopeRequests()
            ),
            'rejected' => $this->count(
                "SELECT COUNT(*) FROM establishment_requests WHERE request_status = 'REJECTED'".$this->scopeRequests()
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
        $sql = "SELECT MIN(created_at) FROM establishment_requests WHERE request_status = 'PENDING'"
            .$this->scopeRequests();

        $oldest = $this->db->scalar($sql, $this->withScope($sql, []));

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
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deleted_at IS NULL AND paid_at >= :since'.$this->scopeVia('tenant_id'),
            ['since' => $monthStart]
        );
        $lastMonth = $this->money(
            'SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE deleted_at IS NULL AND paid_at >= :from AND paid_at < :to'.$this->scopeVia('tenant_id'),
            ['from' => $previousMonthStart, 'to' => $monthStart]
        );

        $invoiced = $this->money('SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE deleted_at IS NULL'.$this->scopeVia('tenant_id'));
        $collected = $this->money('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deleted_at IS NULL'.$this->scopeVia('tenant_id'));

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

        $tenantSql = 'SELECT t.id, t.code, t.name, t.type, t.region, t.city, t.platform_status,
                    (SELECT COUNT(*) FROM students s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students_count
             FROM tenants t
             WHERE t.deleted_at IS NULL'.$this->scopeTenants('t').'
             ORDER BY t.name';

        $tenants = $this->db->select($tenantSql, $this->withScope($tenantSql, []));

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

        $pendingSql = "SELECT id, code, name, type, region, city, reference, created_at
             FROM establishment_requests
             WHERE request_status = 'PENDING'".$this->scopeRequests()."
             ORDER BY created_at";

        $pending = $this->db->select($pendingSql, $this->withScope($pendingSql, []));

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
                'Enseignant', 'Chef de département', 'Intendant', 'Comptable', 'Secrétaire',
                'Infirmier(ère)', 'Bibliothécaire', 'Surveillant général',
            ]],
            'ELEVE' => ['label' => 'Eleves', 'roles' => ['Élève']],
            'PARENT' => ['label' => 'Parents et tuteurs', 'roles' => ['Parent']],
            'PLATEFORME' => ['label' => 'Administration plateforme', 'roles' => ['SUPER_ADMIN']],
        ];

        $rolesSql = "SELECT r.name AS role_name,
                    COUNT(*) AS created,
                    SUM(CASE WHEN u.last_login IS NOT NULL THEN 1 ELSE 0 END) AS activated,
                    SUM(CASE WHEN u.status <> 'ACTIVE' THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN u.created_at >= :since THEN 1 ELSE 0 END) AS recent,
                    SUM(CASE WHEN u.last_login IS NULL OR u.last_login < :stale THEN 1 ELSE 0 END) AS dormant
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL".$this->scopeVia('u.tenant_id')."
             GROUP BY r.name";

        $rows = $this->db->select($rolesSql, $this->withScope($rolesSql, [
            'since' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'stale' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]));

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

        $orphanSql = 'SELECT COUNT(*) FROM users u
             WHERE u.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)'
            .$this->scopeVia('u.tenant_id');

        $withoutRole = (int) $this->db->scalar($orphanSql, $this->withScope($orphanSql, []));

        $total = array_sum(array_column($profiles, 'created')) + $withoutRole;
        $activated = array_sum(array_column($profiles, 'activated'));

        return [
            'profiles' => $profiles,
            'withoutRole' => $withoutRole,
            'total' => $total,
            'activated' => $activated,
            'activationRate' => $total > 0 ? round($activated / $total * 100, 1) : null,
            'topTenants' => $this->topTenants(),
        ];
    }

    /**
     * Etablissements les plus fournis en comptes, dans le perimetre.
     *
     * @return list<array<string, mixed>>
     */
    private function topTenants(): array
    {
        $sql = 'SELECT t.name, t.code, COUNT(u.id) AS accounts
                 FROM tenants t
                 JOIN users u ON u.tenant_id = t.id AND u.deleted_at IS NULL
                 WHERE t.deleted_at IS NULL'.$this->scopeTenants('t').'
                 GROUP BY t.id, t.name, t.code
                 ORDER BY accounts DESC
                 LIMIT 5';

        return $this->db->select($sql, $this->withScope($sql, []));
    }

    /**
     * Effectifs et charge du personnel, dans le perimetre.
     *
     * Ce sont les chiffres que reclame une tutelle : combien d'agents, ou, et
     * dans quelle fonction. Le taux d'encadrement — eleves par membre du
     * personnel — est le seul qui se compare d'un etablissement a l'autre : un
     * effectif brut ne dit rien sans la taille de l'ecole.
     *
     * @return array<string, mixed>
     */
    public function humanResources(): array
    {
        $total = $this->count(
            "SELECT COUNT(*) FROM employees WHERE status = 'ACTIVE'".$this->scopeVia('tenant_id')
        );

        $students = $this->count(
            'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL'.$this->scopeVia('tenant_id')
        );

        $byPosition = $this->db->select(
            ...$this->prepare(
                "SELECT position, COUNT(*) AS total FROM employees
                 WHERE status = 'ACTIVE'".$this->scopeVia('tenant_id')."
                 GROUP BY position
                 ORDER BY total DESC"
            )
        );

        $byRegion = $this->db->select(
            ...$this->prepare(
                "SELECT t.region, COUNT(e.id) AS total
                 FROM tenants t
                 LEFT JOIN employees e ON e.tenant_id = t.id AND e.status = 'ACTIVE'
                 WHERE t.deleted_at IS NULL".$this->scopeTenants('t')."
                 GROUP BY t.region
                 ORDER BY total DESC"
            )
        );

        return [
            'total' => $total,
            // Faute de personnel enregistre, le taux est inconnu et non nul :
            // afficher « 0 eleve par agent » laisserait croire a un
            // sureffectif.
            'ratio' => $total > 0 ? round($students / $total, 1) : null,
            // Deux marqueurs distincts pour la meme date : MySQL en preparation
            // native refuse qu'un nom soit reutilise dans une requete.
            'onLeave' => $this->count(
                "SELECT COUNT(*) FROM leave_requests
                 WHERE status = 'APPROVED' AND start_date <= :from AND end_date >= :to"
                .$this->scopeVia('tenant_id'),
                ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]
            ),
            'pendingLeave' => $this->count(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'PENDING'".$this->scopeVia('tenant_id')
            ),
            'byPosition' => $byPosition,
            'byRegion' => $byRegion,
            'hiredThisYear' => $this->count(
                'SELECT COUNT(*) FROM employees WHERE hire_date >= :since'.$this->scopeVia('tenant_id'),
                ['since' => date('Y-01-01')]
            ),
        ];
    }

    /**
     * Chiffre-cle de chaque domaine d'administration.
     *
     * Le tableau de bord montrait des indicateurs sans ouvrir sur rien : on y
     * lisait « 3 demandes en attente » sans pouvoir les instruire, et la
     * moitie des ecrans n'etait atteignable que par le menu lateral. Un
     * tableau de bord qui ne mene nulle part n'est qu'un rapport.
     *
     * @return array<string, int>
     */
    public function governance(): array
    {
        return [
            'roles' => $this->count('SELECT COUNT(*) FROM roles WHERE tenant_id IS NULL'),
            'auditEvents' => $this->count(
                'SELECT COUNT(*) FROM audit_logs a
                 WHERE a.user_id IS NULL OR a.user_id IN (
                     SELECT u.id FROM users u WHERE 1 = 1'.$this->scopeVia('u.tenant_id').'
                 )'
            ),
            'mailsFailed' => $this->count(
                "SELECT COUNT(*) FROM notifications WHERE status <> 'SENT'".$this->scopeVia('tenant_id')
            ),
            'mailsTotal' => $this->count(
                'SELECT COUNT(*) FROM notifications WHERE 1 = 1'.$this->scopeVia('tenant_id')
            ),
        ];
    }

    /**
     * Prepare une requete et ses parametres de perimetre.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prepare(string $sql): array
    {
        return [$sql, $this->withScope($sql, [])];
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
        return (int) $this->db->scalar($sql, $this->withScope($sql, $params));
    }

    /** @param  array<string, mixed>  $params */
    private function money(string $sql, array $params = []): float
    {
        return (float) $this->db->scalar($sql, $this->withScope($sql, $params));
    }
}
