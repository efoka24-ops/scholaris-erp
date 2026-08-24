<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Cameroon;
use Scholaris\Support\Validator;

/**
 * Pages accessibles sans compte.
 *
 * Deux parcours y vivent :
 *  - la pre-inscription en ligne, ou un parent depose un dossier pour son
 *    enfant dans un etablissement qui a ouvert cette possibilite ;
 *  - la demande de creation d'etablissement, ou un chef d'etablissement
 *    sollicite l'ouverture de son ecole sur la plateforme.
 *
 * Aucun etablissement courant n'est defini sur ces routes : les ecritures
 * precisent donc explicitement leur tenant_id, a l'interieur d'un bloc global.
 */
final class PublicController extends Controller
{
    /**
     * Types proposes au depot d'une demande.
     *
     * Ce sont ceux de la matrice des fonctionnalites : c'est le type choisi
     * ici qui determine les modules ouverts et la structure posee a la
     * validation. Un choix approximatif — « secondaire » pour un college —
     * donnerait a l'etablissement des niveaux et des examens qui ne sont pas
     * les siens.
     */
    private const TENANT_TYPES = [
        'PRIMAIRE',
        'COLLEGE',
        'LYCEE_GENERAL',
        'LYCEE_TECHNIQUE',
        'CENTRE_FORMATION',
        'SUPERIEUR',
    ];

    private const TENANT_STATUSES = ['PUBLIC', 'PRIVE'];

    /**
     * Page d'accueil publique.
     *
     * Un visiteur deja connecte n'a rien a y faire : il repart vers son espace
     * plutot que de devoir cliquer sur "Se connecter" pour y revenir.
     */
    public function home(Request $request): Response
    {
        if ($this->app->auth()->check()) {
            return $this->redirect('/dashboard');
        }

        // Contenu de la maquette (25 modules, guide, temoignages), extrait
        // automatiquement pour n'en omettre aucune entree.
        $content = require $this->app->basePath().'/database/landing-content.php';

        return $this->view('public.home', [
            'tenants' => $this->openTenants(),
            'modules' => $content['modules'],
            'guide' => $content['guide'],
            'testimonials' => $content['testimonials'],
        ]);
    }

    // --- Pre-inscription en ligne -------------------------------------------

    public function preEnrollmentForm(Request $request): Response
    {
        return $this->view('public.pre-enrollment', [
            'tenants' => $this->openTenants(),
            'old' => $this->app->session()->pullOldInput(),
            'error' => $this->app->session()->pullFlash('public_error'),
        ]);
    }

    public function submitPreEnrollment(Request $request): Response
    {
        $tenants = $this->openTenants();
        $codes = array_map(static fn (array $t): string => (string) $t['code'], $tenants);

        $validator = (new Validator($request))
            ->in('tenant_code', 'etablissement', $codes)
            ->required('applicant_first_name', 'prenom de l eleve')
            ->required('applicant_last_name', 'nom de l eleve')
            ->date('date_of_birth', 'date de naissance')
            ->in('gender', 'sexe', ['MALE', 'FEMALE'])
            ->required('parent_name', 'nom du parent')
            ->required('parent_phone', 'telephone du parent')
            ->optional('parent_email')
            ->optional('level_wanted')
            ->optional('previous_school');

        if ($validator->fails()) {
            return $this->backToPublic($request, '/pre-inscription', $validator->errors());
        }

        $data = $validator->validated();
        $tenant = $this->tenantByCode((string) $data['tenant_code']);

        if ($tenant === null) {
            throw new HttpException(404);
        }

        $academicYear = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT id FROM academic_years WHERE tenant_id = :tenant AND status = :status
             ORDER BY start_date DESC LIMIT 1',
            ['tenant' => $tenant['id'], 'status' => 'ACTIVE']
        ));

        if ($academicYear === null) {
            return $this->backToPublic(
                $request,
                '/pre-inscription',
                ['tenant_code' => 'Cet etablissement n a pas encore ouvert son annee academique.']
            );
        }

        // Le dossier est conserve tel quel dans applicant_info : la
        // pre-inscription ne cree ni eleve ni compte, elle depose une demande
        // que l'etablissement instruira.
        $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $this->app->tenant()->global(function () use ($tenant, $academicYear, $data, $reference): void {
            $this->app->db()->execute(
                'INSERT INTO admission_applications
                    (id, tenant_id, applicant_name, applicant_info, type, status, academic_year_id, created_at, updated_at)
                 VALUES (:id, :tenant, :name, :info, :type, :status, :year, :created_at, :updated_at)',
                [
                    'id' => Table::uuid(),
                    'tenant' => $tenant['id'],
                    'name' => $data['applicant_last_name'].' '.$data['applicant_first_name'],
                    'info' => json_encode([
                        'reference' => $reference,
                        'first_name' => $data['applicant_first_name'],
                        'last_name' => $data['applicant_last_name'],
                        'date_of_birth' => $data['date_of_birth'],
                        'gender' => $data['gender'],
                        'level_wanted' => $data['level_wanted'],
                        'previous_school' => $data['previous_school'],
                        'parent' => [
                            'name' => $data['parent_name'],
                            'phone' => $data['parent_phone'],
                            'email' => $data['parent_email'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'type' => 'DOSSIER',
                    'status' => 'PENDING',
                    'year' => $academicYear['id'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        });

        return $this->view('public.pre-enrollment-done', [
            'reference' => $reference,
            'tenantName' => $tenant['name'],
        ]);
    }

    // --- Demande de creation d'etablissement --------------------------------

    public function establishmentRequestForm(Request $request): Response
    {
        return $this->view('public.establishment-request', [
            'types' => self::TENANT_TYPES,
            'statuses' => self::TENANT_STATUSES,
            'regions' => Cameroon::regionChoices(),
            'old' => $this->app->session()->pullOldInput(),
            'error' => $this->app->session()->pullFlash('public_error'),
        ]);
    }

    public function submitEstablishmentRequest(Request $request): Response
    {
        $validator = (new Validator($request))
            ->required('name', 'nom de l etablissement')
            ->required('code', 'code')
            ->in('type', 'type', self::TENANT_TYPES)
            ->in('status', 'statut', self::TENANT_STATUSES)
            ->required('director_first_name', 'prenom du responsable')
            ->required('director_last_name', 'nom du responsable')
            ->email('director_email', 'email du responsable')
            ->optional('director_phone')
            ->optional('address')
            ->optional('phone')
            ->optional('email')
            ->optional('city')
            // La region situe l'etablissement sur la carte du parc. Elle reste
            // facultative : refuser un dossier pour une case non cochee serait
            // disproportionne.
            ->optional('region');

        if ($validator->fails()) {
            return $this->backToPublic($request, '/demande-etablissement', $validator->errors());
        }

        $data = $validator->validated();
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['code']) ?? '');

        if ($code === '') {
            return $this->backToPublic($request, '/demande-etablissement', [
                'code' => 'Le code doit contenir des lettres ou des chiffres.',
            ]);
        }

        // Un code deja pris, par un etablissement existant ou par une demande
        // en attente, est refuse ici : le detecter a la validation obligerait
        // le Super Admin a revenir vers le demandeur.
        if ($this->codeIsTaken($code)) {
            return $this->backToPublic($request, '/demande-etablissement', [
                'code' => 'Ce code est deja utilise. Choisissez-en un autre.',
            ]);
        }

        $demandId = Table::uuid();
        $reference = $this->generateReference();
        $region = is_string($data['region'] ?? null) && Cameroon::isRegion((string) $data['region'])
            ? (string) $data['region']
            : null;

        $this->app->db()->execute(
            'INSERT INTO establishment_requests
                (id, name, code, type, status, address, phone, email, region, city, reference,
                 director_first_name, director_last_name, director_email, director_phone,
                 request_status, created_at, updated_at)
             VALUES (:id, :name, :code, :type, :status, :address, :phone, :email, :region, :city, :reference,
                 :dfirst, :dlast, :demail, :dphone, :rstatus, :created_at, :updated_at)',
            [
                'id' => $demandId,
                'region' => $region,
                'city' => $data['city'],
                'reference' => $reference,
                'name' => $data['name'],
                'code' => $code,
                'type' => $data['type'],
                'status' => $data['status'],
                'address' => $data['address'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'dfirst' => $data['director_first_name'],
                'dlast' => $data['director_last_name'],
                'demail' => $data['director_email'],
                'dphone' => $data['director_phone'],
                'rstatus' => 'PENDING',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        // Accuse de reception. Jusqu'ici le demandeur deposait son dossier et
        // n'entendait plus parler de rien : il ne lui restait que le telephone.
        $demand = $this->app->db()->selectOne(
            'SELECT * FROM establishment_requests WHERE id = :id',
            ['id' => $demandId]
        );

        if ($demand !== null) {
            $this->app->establishmentMails()->acknowledge($demand);
        }

        return $this->view('public.establishment-request-done', [
            'name' => $data['name'],
            'code' => $code,
            'email' => $data['director_email'],
            'reference' => $reference,
        ]);
    }

    /**
     * Suivi d'un dossier par sa reference.
     *
     * Le demandeur n'a pas de compte : la reference et son adresse email
     * tiennent lieu de justificatif. Exiger les deux evite qu'une reference
     * devinee ne donne acces aux coordonnees d'un tiers.
     */
    public function trackEstablishmentRequest(Request $request): Response
    {
        $reference = strtoupper(trim($request->string('reference')));
        $email = strtolower(trim($request->string('email')));

        if ($reference === '' || $email === '') {
            return $this->view('public.establishment-request-track', [
                'demand' => null,
                'searched' => false,
                'error' => null,
                'reference' => $reference,
                'email' => $email,
            ]);
        }

        $demand = $this->app->db()->selectOne(
            'SELECT * FROM establishment_requests
             WHERE reference = :reference AND LOWER(director_email) = :email',
            ['reference' => $reference, 'email' => $email]
        );

        return $this->view('public.establishment-request-track', [
            'demand' => $demand,
            'searched' => true,
            'error' => $demand === null
                ? 'Aucun dossier ne correspond a cette reference et a cette adresse.'
                : null,
            'reference' => $reference,
            'email' => $email,
        ]);
    }

    /**
     * Reference courte, lisible au telephone.
     *
     * Sans caracteres ambigus, et verifiee unique : deux dossiers portant la
     * meme reference renverraient le demandeur vers celui d'un autre.
     */
    private function generateReference(): string
    {
        $alphabet = 'ACDEFGHJKLMNPQRTUVWXY3479';

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffix = '';

            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $reference = 'ET-'.$suffix;

            $taken = $this->app->db()->scalar(
                'SELECT id FROM establishment_requests WHERE reference = :reference',
                ['reference' => $reference]
            );

            if ($taken === null) {
                return $reference;
            }
        }

        // Retombee improbable, mais une reference vide priverait le demandeur
        // de tout suivi.
        return 'ET-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }

    /**
     * Etablissements ayant ouvert la pre-inscription en ligne.
     *
     * @return array<int, array<string, mixed>>
     */
    private function openTenants(): array
    {
        return $this->app->tenant()->global(fn () => $this->app->db()->select(
            'SELECT id, code, name FROM tenants
             WHERE public_enrollment_enabled = 1 AND deleted_at IS NULL
             ORDER BY name'
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenantByCode(string $code): ?array
    {
        return $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT id, code, name FROM tenants
             WHERE code = :code AND public_enrollment_enabled = 1 AND deleted_at IS NULL',
            ['code' => $code]
        ));
    }

    private function codeIsTaken(string $code): bool
    {
        $inTenants = $this->app->db()->scalar(
            'SELECT 1 FROM tenants WHERE code = :code LIMIT 1',
            ['code' => $code]
        );

        $inRequests = $this->app->db()->scalar(
            'SELECT 1 FROM establishment_requests WHERE code = :code AND request_status = :status LIMIT 1',
            ['code' => $code, 'status' => 'PENDING']
        );

        return $inTenants !== null || $inRequests !== null;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function backToPublic(Request $request, string $to, array $errors): Response
    {
        $session = $this->app->session();
        $session->flashInput($request->all());
        $session->flash('public_error', implode(' ', $errors));

        return $this->redirect($to);
    }
}
