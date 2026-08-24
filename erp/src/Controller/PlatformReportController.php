<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Cameroon;

/**
 * Ce que le Super Admin doit pouvoir lire du parc.
 *
 * Trois ecrans, trois questions differentes.
 *
 * Le comparatif : quels etablissements vont bien, lesquels decrochent. Un
 * chiffre par ecole ne sert a rien isole ; c'est la comparaison qui designe
 * ceux qu'il faut appeler.
 *
 * Le journal : qui a fait quoi. Un administrateur national entre dans les
 * donnees d'ecoles qui ne sont pas les siennes ; cette trace est ce qui
 * distingue un acces legitime d'un acces silencieux, et elle ne vaut que si
 * elle est consultable.
 *
 * Les habilitations : qui peut quoi. Le referentiel compte plus de cent
 * permissions reparties sur douze roles ; sans ecran pour le lire, personne ne
 * sait ce qu'un role autorise reellement.
 */
final class PlatformReportController extends Controller
{
    public function comparison(Request $request): Response
    {
        $this->assertSuperAdmin();

        $rows = $this->app->tenant()->global(fn () => $this->app->db()->select(
            "SELECT t.id, t.code, t.name, t.type, t.region, t.city, t.platform_status,
                    (SELECT COUNT(*) FROM students s
                      WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students,
                    (SELECT COUNT(*) FROM users u
                      WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS accounts,
                    (SELECT COUNT(*) FROM classrooms c WHERE c.tenant_id = t.id) AS classrooms,
                    (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i
                      WHERE i.tenant_id = t.id AND i.deleted_at IS NULL) AS invoiced,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                      WHERE p.tenant_id = t.id AND p.deleted_at IS NULL) AS collected,
                    (SELECT MAX(u.last_login) FROM users u WHERE u.tenant_id = t.id) AS last_activity
             FROM tenants t
             WHERE t.deleted_at IS NULL
             ORDER BY t.name"
        ));

        // Le taux de recouvrement se calcule ici plutot qu'en SQL : une
        // division par zero dans la requete donnerait NULL, que le gabarit
        // afficherait comme « 0 % » — un etablissement qui n'a rien facture
        // n'est pas un etablissement qui ne recouvre rien.
        foreach ($rows as $index => $row) {
            $invoiced = (float) $row['invoiced'];

            $rows[$index]['collection_rate'] = $invoiced > 0.0
                ? round((float) $row['collected'] / $invoiced * 100, 1)
                : null;
        }

        return $this->view('platform.report-comparison', [
            'rows' => $rows,
            'regionName' => static fn (?string $code): string => Cameroon::regionName($code),
        ]);
    }

    /**
     * Comparatif au format CSV.
     *
     * Un tableau de bord se regarde, un rapport se travaille : le ministere et
     * le reseau reclament des chiffres qu'ils recoupent dans un tableur.
     */
    public function comparisonCsv(Request $request): Response
    {
        $this->assertSuperAdmin();

        $rows = $this->app->tenant()->global(fn () => $this->app->db()->select(
            "SELECT t.code, t.name, t.type, t.region, t.city, t.platform_status,
                    (SELECT COUNT(*) FROM students s
                      WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students,
                    (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i
                      WHERE i.tenant_id = t.id AND i.deleted_at IS NULL) AS invoiced,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                      WHERE p.tenant_id = t.id AND p.deleted_at IS NULL) AS collected
             FROM tenants t
             WHERE t.deleted_at IS NULL
             ORDER BY t.name"
        ));

        $lines = ['Code;Nom;Type;Region;Ville;Etat;Eleves;Facture;Encaisse;Taux'];

        foreach ($rows as $row) {
            $invoiced = (float) $row['invoiced'];
            $rate = $invoiced > 0.0 ? round((float) $row['collected'] / $invoiced * 100, 1) : '';

            $lines[] = implode(';', [
                $this->csv((string) $row['code']),
                $this->csv((string) $row['name']),
                $this->csv((string) $row['type']),
                $this->csv(Cameroon::regionName($row['region'] ?? null)),
                $this->csv((string) ($row['city'] ?? '')),
                $this->csv((string) $row['platform_status']),
                (string) (int) $row['students'],
                number_format((float) $row['invoiced'], 2, ',', ''),
                number_format((float) $row['collected'], 2, ',', ''),
                is_string($rate) ? $rate : number_format($rate, 1, ',', ''),
            ]);
        }

        // Point-virgule et BOM : c'est ce qu'attend Excel en configuration
        // francaise, faute de quoi tout atterrit dans une seule colonne et les
        // accents sont illisibles.
        $csv = "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="parc-scholaris-'.date('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function auditLog(Request $request): Response
    {
        $this->assertSuperAdmin();

        $action = trim($request->string('action'));
        $page = max(1, (int) $request->string('page', '1'));
        $perPage = 60;

        $where = ['1 = 1'];
        $params = [];

        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $clause = implode(' AND ', $where);

        $data = $this->app->tenant()->global(function () use ($clause, $params, $page, $perPage): array {
            return [
                'entries' => $this->app->db()->select(
                    'SELECT a.*, u.email, u.first_name, u.last_name, t.name AS tenant_name
                     FROM audit_logs a
                     LEFT JOIN users u ON u.id = a.user_id
                     LEFT JOIN tenants t ON t.id = u.tenant_id
                     WHERE '.$clause.'
                     ORDER BY a.timestamp DESC
                     LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage),
                    $params
                ),
                'total' => (int) $this->app->db()->scalar(
                    'SELECT COUNT(*) FROM audit_logs a WHERE '.$clause,
                    $params
                ),
                'actions' => $this->app->db()->select(
                    'SELECT action, COUNT(*) AS total FROM audit_logs GROUP BY action ORDER BY action'
                ),
            ];
        });

        return $this->view('platform.audit-log', [
            'entries' => $data['entries'],
            'total' => $data['total'],
            'actions' => $data['actions'],
            'action' => $action,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function roles(Request $request): Response
    {
        $this->assertSuperAdmin();

        $data = $this->app->tenant()->global(function (): array {
            $roles = $this->app->db()->select(
                'SELECT r.*,
                        (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permissions_count,
                        (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id
                          WHERE ur.role_id = r.id AND u.deleted_at IS NULL) AS users_count
                 FROM roles r
                 WHERE r.tenant_id IS NULL
                 ORDER BY r.name'
            );

            $permissions = [];

            foreach ($roles as $role) {
                $permissions[(string) $role['id']] = $this->app->db()->select(
                    'SELECT p.resource, p.action, p.description
                     FROM role_permissions rp
                     JOIN permissions p ON p.id = rp.permission_id
                     WHERE rp.role_id = :role
                     ORDER BY p.resource, p.action',
                    ['role' => $role['id']]
                );
            }

            return [
                'roles' => $roles,
                'permissions' => $permissions,
                'total' => (int) $this->app->db()->scalar('SELECT COUNT(*) FROM permissions'),
            ];
        });

        return $this->view('platform.roles', $data);
    }

    /** Neutralise les separateurs et les guillemets d'un champ CSV. */
    private function csv(string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve au Super Admin.');
        }
    }
}
