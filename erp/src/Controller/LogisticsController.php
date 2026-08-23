<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Modules 15 a 17 : transport, cantine et patrimoine.
 *
 * Trois inventaires que l'intendance tient cote a cote : vehicules et lignes,
 * menus, biens et maintenances. Ils partagent le meme profil d'utilisateur et
 * les memes ecrans de liste, d'ou leur regroupement.
 */
final class LogisticsController extends Controller
{
    private const ASSET_CATEGORIES = ['MOBILIER', 'EQUIPEMENT', 'BATIMENT', 'VEHICULE'];

    private const ASSET_STATUSES = ['ACTIF', 'ENDOMMAGE', 'HORS_SERVICE'];

    private const MEALS = ['PETIT_DEJEUNER', 'DEJEUNER', 'GOUTER', 'DINER'];

    // --- Module 15 : transport -----------------------------------------------

    public function transport(Request $request): Response
    {
        $routes = $this->app->db()->select(
            'SELECT r.*, v.name AS vehicle_name, v.capacity,
                    (SELECT COUNT(*) FROM transport_subscriptions s
                     WHERE s.route_id = r.id AND s.tenant_id = r.tenant_id) AS subscribers
             FROM transport_routes r
             LEFT JOIN transport_vehicles v ON v.id = r.vehicle_id
             WHERE r.tenant_id = :tenant
             ORDER BY r.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('logistics.transport', [
            'routes' => $routes,
            'vehicles' => $this->table('transport_vehicles')->orderBy('name')->get(),
            'students' => $this->activeStudents(),
            'subscriptions' => $this->app->db()->select(
                'SELECT s.*, r.name AS route_name, st.matricule, st.first_name, st.last_name
                 FROM transport_subscriptions s
                 INNER JOIN transport_routes r ON r.id = s.route_id
                 INNER JOIN students st ON st.id = s.student_id
                 WHERE s.tenant_id = :tenant
                 ORDER BY r.name, st.last_name',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeVehicle(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('name', 'nom du vehicule')
            ->integer('capacity', 'capacite', 1, 200);

        if ($validator->fails()) {
            return $this->redirectWithError('/transport', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['name', 'capacity']);
        $data['status'] = 'ACTIVE';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('transport_vehicles')->insert($data);

        return $this->redirectWithSuccess('/transport', 'Vehicule ajoute.');
    }

    public function storeRoute(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('name', 'nom de la ligne')
            ->in('vehicle_id', 'vehicule', $this->ids($this->table('transport_vehicles')->get()), false)
            ->optional('stops')
            ->optional('schedule');

        if ($validator->fails()) {
            return $this->redirectWithError('/transport', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['name', 'vehicle_id', 'stops', 'schedule']);
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('transport_routes')->insert($data);

        return $this->redirectWithSuccess('/transport', 'Ligne creee.');
    }

    public function subscribe(Request $request): Response
    {
        $route = $this->findOrFail('transport_routes', (string) $request->attribute('id'));

        $validator = (new Validator($request))
            ->in('student_id', 'eleve', $this->ids($this->activeStudents()))
            ->optional('stop_name');

        if ($validator->fails()) {
            return $this->redirectWithError('/transport', implode(' ', $validator->errors()));
        }

        $data = $validator->validated();

        $already = $this->table('transport_subscriptions')
            ->where('route_id', (string) $route['id'])
            ->where('student_id', (string) $data['student_id'])
            ->exists();

        if ($already) {
            return $this->redirectWithError('/transport', 'Cet eleve est deja abonne a cette ligne.');
        }

        // La capacite du vehicule borne le nombre d'abonnes : au-dela, des
        // eleves resteraient a l'arret.
        if ($route['vehicle_id'] !== null) {
            $vehicle = $this->table('transport_vehicles')->find((string) $route['vehicle_id']);
            $count = $this->table('transport_subscriptions')->where('route_id', (string) $route['id'])->count();

            if ($vehicle !== null && $count >= (int) $vehicle['capacity']) {
                return $this->redirectWithError('/transport', 'La capacite du vehicule est atteinte sur cette ligne.');
            }
        }

        $this->table('transport_subscriptions')->insert([
            'route_id' => $route['id'],
            'student_id' => $data['student_id'],
            'stop_name' => $data['stop_name'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->redirectWithSuccess('/transport', 'Abonnement enregistre.');
    }

    // --- Module 16 : cantine -------------------------------------------------

    public function catering(Request $request): Response
    {
        $from = $request->string('from') ?: date('Y-m-d');

        return $this->view('logistics.catering', [
            'menus' => $this->table('catering_menus')
                ->where('date', $from, '>=')
                ->orderBy('date')
                ->limit(60)
                ->get(),
            'from' => $from,
            'meals' => self::MEALS,
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeMenu(Request $request): Response
    {
        $validator = (new Validator($request))
            ->date('date', 'date')
            ->in('meal', 'service', self::MEALS)
            ->required('items', 'composition du menu');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/catering', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['date', 'meal', 'items']);

        $existing = $this->table('catering_menus')
            ->where('date', (string) $data['date'])
            ->where('meal', (string) $data['meal'])
            ->first();

        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            // Un seul menu par date et par service : le second remplace le
            // premier plutot que de creer un doublon.
            $this->table('catering_menus')
                ->where('id', (string) $existing['id'])
                ->update(['items' => $data['items'], 'updated_at' => $now]);

            return $this->redirectWithSuccess('/catering', 'Menu mis a jour.');
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->table('catering_menus')->insert($data);

        return $this->redirectWithSuccess('/catering', 'Menu enregistre.');
    }

    // --- Module 17 : patrimoine ----------------------------------------------

    public function assets(Request $request): Response
    {
        $category = $request->string('category');

        $query = $this->table('assets')->orderBy('name');

        if (in_array($category, self::ASSET_CATEGORIES, true)) {
            $query->where('category', $category);
        }

        $assets = $query->limit(200)->get();

        return $this->view('logistics.assets', [
            'assets' => $assets,
            'category' => $category,
            'categories' => self::ASSET_CATEGORIES,
            'statuses' => self::ASSET_STATUSES,
            'totalValue' => $this->table('assets')->sum('acquisition_value'),
            'maintenances' => $this->app->db()->select(
                'SELECT m.*, a.name AS asset_name FROM asset_maintenances m
                 INNER JOIN assets a ON a.id = m.asset_id
                 WHERE m.tenant_id = :tenant
                 ORDER BY m.date DESC LIMIT 20',
                ['tenant' => $this->app->tenant()->requireId()]
            ),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    public function storeAsset(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('name', 'designation')
            ->in('category', 'categorie', self::ASSET_CATEGORIES)
            ->in('status', 'etat', self::ASSET_STATUSES)
            ->date('acquisition_date', 'date d acquisition', false)
            ->optional('location');

        if ($validator->fails()) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/patrimoine', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['name', 'category', 'status', 'acquisition_date', 'location']);

        $value = str_replace(',', '.', $request->string('acquisition_value'));
        $data['acquisition_value'] = is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('assets')->insert($data);

        return $this->redirectWithSuccess('/patrimoine', 'Bien inscrit a l inventaire.');
    }

    public function storeMaintenance(Request $request): Response
    {
        $asset = $this->findOrFail('assets', (string) $request->attribute('id'));

        $validator = (new Validator($request))
            ->date('date', 'date')
            ->required('description', 'description')
            ->optional('technician');

        if ($validator->fails()) {
            return $this->redirectWithError('/patrimoine', implode(' ', $validator->errors()));
        }

        $data = $validator->only(['date', 'description', 'technician']);
        $data['asset_id'] = $asset['id'];

        $cost = str_replace(',', '.', $request->string('cost'));
        $data['cost'] = is_numeric($cost) ? number_format((float) $cost, 2, '.', '') : null;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        $this->table('asset_maintenances')->insert($data);

        return $this->redirectWithSuccess('/patrimoine', 'Intervention enregistree.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeStudents(): array
    {
        return $this->table('students')
            ->select(['id', 'matricule', 'first_name', 'last_name'])
            ->notDeleted()
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }
}
