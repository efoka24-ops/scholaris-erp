<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\Table;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Validator;

/**
 * Module 4 : dossiers eleves.
 */
final class StudentController extends Controller
{
    private const PER_PAGE = 25;

    private const GENDERS = ['MALE', 'FEMALE'];

    private const STATUSES = ['ACTIVE', 'SUSPENDED', 'GRADUATED', 'EXCLUDED', 'ABANDONED'];

    public function index(Request $request): Response
    {
        $search = $request->string('q');
        $status = $request->string('status');
        $page = max($request->int('page', 1), 1);

        $query = $this->table('students')->notDeleted();

        if ($search !== '') {
            $query->search($search, ['first_name', 'last_name', 'matricule']);
        }

        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        // Filtre par classe : passe par l'inscription active, un eleve n'ayant
        // pas de classe en propre — il change de classe chaque annee.
        $classroom = $request->string('classe');

        if ($classroom !== '') {
            $query->whereIn('id', $this->studentIdsInClassroom($classroom));
        }

        // Le comptage doit porter les memes filtres que la liste : on compte
        // avant d'appliquer la pagination, sur une requete clonee.
        $total = (clone $query)->count();

        $students = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            ->get();

        return $this->view('students.index', [
            'students' => $this->decorate($students),
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'pages' => (int) ceil($total / self::PER_PAGE),
            'search' => $search,
            'status' => $status,
            'statuses' => self::STATUSES,
            'counts' => $this->countsByStatus(),
            'classrooms' => $this->table('classrooms')->orderBy('name')->get(),
            'classroom' => $request->string('classe'),
        ]);
    }

    public function show(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));

        $enrollments = $this->app->db()->select(
            'SELECT e.*, c.name AS classroom_name, y.label AS year_label
             FROM enrollments e
             INNER JOIN classrooms c ON c.id = e.classroom_id
             INNER JOIN academic_years y ON y.id = e.academic_year_id
             WHERE e.student_id = :student AND e.tenant_id = :tenant AND e.deleted_at IS NULL
             ORDER BY e.enrollment_date DESC',
            ['student' => $student['id'], 'tenant' => $this->app->tenant()->requireId()]
        );

        return $this->view('students.show', [
            'student' => $student,
            'enrollments' => $enrollments,
            'invoices' => $this->table('invoices')
                ->notDeleted()
                ->where('student_id', (string) $student['id'])
                ->orderBy('created_at', 'desc')
                ->get(),
        ]);
    }

    /**
     * Certificat de scolarite imprimable : la demande la plus frequente du
     * secretariat, jusqu'ici absente de l'application.
     */
    public function certificate(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));

        $enrollment = $this->app->db()->selectOne(
            'SELECT e.*, c.name AS classroom_name, y.label AS year_label
             FROM enrollments e
             INNER JOIN classrooms c ON c.id = e.classroom_id
             INNER JOIN academic_years y ON y.id = e.academic_year_id
             WHERE e.student_id = :student AND e.tenant_id = :tenant AND e.status = :status AND e.deleted_at IS NULL
             ORDER BY e.enrollment_date DESC LIMIT 1',
            ['student' => $student['id'], 'tenant' => $this->app->tenant()->requireId(), 'status' => 'ACTIVE']
        );

        if ($enrollment === null) {
            return $this->redirectWithError(
                '/students/'.$student['id'],
                'Aucune inscription active : impossible de generer un certificat de scolarite.'
            );
        }

        $tenantRow = $this->app->db()->selectOne(
            'SELECT name FROM tenants WHERE id = :id',
            ['id' => $this->app->tenant()->requireId()]
        );

        return $this->view('students.certificate', [
            'student' => $student,
            'enrollment' => $enrollment,
            'tenantName' => $tenantRow['name'] ?? '',
            'issuedAt' => date('Y-m-d'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('students.form', [
            'student' => null,
            'errors' => [],
            'old' => $this->app->session()->pullOldInput(),
            'genders' => self::GENDERS,
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/students/create');
        }

        $data = $validator->only([
            'first_name', 'last_name', 'date_of_birth', 'place_of_birth', 'gender',
            'nationality', 'blood_group', 'allergies', 'handicap', 'emergency_contact',
        ]);

        $data['status'] = 'ACTIVE';
        $data['nationality'] = $data['nationality'] ?? 'Camerounaise';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];

        // Matricule et eleve crees dans la meme transaction : deux inscriptions
        // simultanees ne peuvent pas obtenir le meme numero.
        $id = $this->app->db()->transaction(function () use ($data): string {
            $data['matricule'] = $this->nextMatricule();

            return $this->table('students')->insert($data);
        });

        return $this->redirectWithSuccess('/students/'.$id, 'Eleve enregistre.');
    }

    public function edit(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));

        return $this->view('students.form', [
            'student' => $student,
            'errors' => [],
            'old' => $this->app->session()->pullOldInput(),
            'genders' => self::GENDERS,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));
        $validator = $this->validate($request);
        $validator->in('status', 'statut', self::STATUSES);

        if ($validator->fails()) {
            return $this->backWithErrors($request, $validator, '/students/'.$student['id'].'/edit');
        }

        $data = $validator->only([
            'first_name', 'last_name', 'date_of_birth', 'place_of_birth', 'gender',
            'nationality', 'blood_group', 'allergies', 'handicap', 'emergency_contact', 'status',
        ]);

        // La colonne nationality est NOT NULL : un champ laisse vide reprend la
        // valeur par defaut plutot que d'ecrire NULL.
        $data['nationality'] = $data['nationality'] ?? 'Camerounaise';
        $data['updated_at'] = date('Y-m-d H:i:s');

        $this->table('students')->where('id', (string) $student['id'])->update($data);

        return $this->redirectWithSuccess('/students/'.$student['id'], 'Dossier mis a jour.');
    }

    public function destroy(Request $request): Response
    {
        $student = $this->findOrFail('students', (string) $request->attribute('id'));

        // Suppression logique : le dossier reste consultable pour l'historique
        // des notes, des factures et des bulletins deja emis.
        $this->table('students')->where('id', (string) $student['id'])->softDelete();

        return $this->redirectWithSuccess('/students', 'Eleve archive.');
    }

    private function validate(Request $request): Validator
    {
        return (new Validator($request))
            ->required('first_name', 'prenom')
            ->required('last_name', 'nom')
            ->date('date_of_birth', 'date de naissance')
            ->in('gender', 'sexe', self::GENDERS)
            ->optional('place_of_birth')
            ->optional('nationality')
            ->optional('blood_group')
            ->optional('allergies')
            ->optional('handicap')
            ->optional('emergency_contact');
    }

    private function backWithErrors(Request $request, Validator $validator, string $to): Response
    {
        $session = $this->app->session();
        $session->flashInput($request->all());
        $session->flash('error', implode(' ', $validator->errors()));

        return $this->redirect($to);
    }

    /**
     * Numero de matricule suivant, au format CODE/ANNEE/0001.
     *
     * Le compteur est verrouille en lecture (SELECT ... FOR UPDATE) pour que
     * deux creations concurrentes ne lisent pas la meme valeur. SQLite ignore
     * cette clause mais serialise deja les ecritures.
     */
    private function nextMatricule(): string
    {
        $tenantId = $this->app->tenant()->requireId();
        $year = date('Y');
        $db = $this->app->db();

        $lockClause = $db->driver() === 'mysql' ? ' FOR UPDATE' : '';

        $sequence = $db->selectOne(
            'SELECT id, last_number FROM matricule_sequences
             WHERE tenant_id = :tenant AND year = :year LIMIT 1'.$lockClause,
            ['tenant' => $tenantId, 'year' => $year]
        );

        if ($sequence === null) {
            $next = 1;
            $db->execute(
                'INSERT INTO matricule_sequences (id, tenant_id, year, last_number, created_at, updated_at)
                 VALUES (:id, :tenant, :year, :n, :created_at, :updated_at)',
                [
                    'id' => Table::uuid(),
                    'tenant' => $tenantId,
                    'year' => $year,
                    'n' => $next,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        } else {
            $next = (int) $sequence['last_number'] + 1;
            $db->execute(
                'UPDATE matricule_sequences SET last_number = :n, updated_at = :updated_at WHERE id = :id',
                ['n' => $next, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $sequence['id']]
            );
        }

        $tenant = $db->selectOne('SELECT code FROM tenants WHERE id = :id', ['id' => $tenantId]);

        return sprintf('%s/%s/%04d', $tenant['code'] ?? 'ETS', $year, $next);
    }
    /**
     * Compteurs par statut, affiches en bandeau au-dessus de la liste.
     *
     * Un directeur ouvre cet ecran pour savoir combien d'eleves il a, et
     * combien de dossiers restent en attente. Le faire filtrer quatre fois
     * pour l'apprendre serait absurde.
     *
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        $rows = $this->app->db()->select(
            'SELECT status, COUNT(*) AS total FROM students
             WHERE tenant_id = :tenant AND deleted_at IS NULL
             GROUP BY status',
            ['tenant' => $this->app->tenant()->requireId()]
        );

        $counts = ['TOTAL' => 0];

        foreach (self::STATUSES as $status) {
            $counts[$status] = 0;
        }

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = (int) $row['total'];
            $counts['TOTAL'] += (int) $row['total'];
        }

        // Les dossiers de pre-inscription ne sont pas encore des eleves : ils
        // sont comptes a part, sans entrer dans le total.
        $counts['PENDING'] = $this->table('admission_applications')
            ->notDeleted()
            ->where('status', 'PENDING')
            ->count();

        return $counts;
    }

    /**
     * Ajoute a chaque eleve sa classe et sa derniere moyenne publiee.
     *
     * Les deux sont ce qu'on cherche dans une liste d'eleves, et les obtenir
     * exigeait jusqu'ici d'ouvrir chaque fiche. Une requete par eleve serait
     * ruineuse sur deux mille dossiers : les deux jeux sont donc charges en
     * bloc puis rapproches en memoire.
     *
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    private function decorate(array $students): array
    {
        if ($students === []) {
            return [];
        }

        $ids = array_map(static fn (array $s): string => (string) $s['id'], $students);
        $placeholders = [];
        $params = ['tenant' => $this->app->tenant()->requireId()];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':s'.$index;
            $params['s'.$index] = $id;
        }

        $list = implode(', ', $placeholders);

        $classrooms = [];

        foreach ($this->app->db()->select(
            'SELECT e.student_id, c.name FROM enrollments e
             JOIN classrooms c ON c.id = e.classroom_id
             WHERE e.tenant_id = :tenant AND e.deleted_at IS NULL
               AND e.status = \'ACTIVE\' AND e.student_id IN ('.$list.')',
            $params
        ) as $row) {
            $classrooms[(string) $row['student_id']] = (string) $row['name'];
        }

        $averages = [];

        foreach ($this->app->db()->select(
            'SELECT student_id, general_average FROM period_results
             WHERE tenant_id = :tenant AND is_published = 1
               AND general_average IS NOT NULL AND student_id IN ('.$list.')
             ORDER BY created_at',
            $params
        ) as $row) {
            // La derniere publiee ecrase les precedentes : c'est celle qui
            // renseigne sur la situation actuelle.
            $averages[(string) $row['student_id']] = (float) $row['general_average'];
        }

        foreach ($students as $index => $student) {
            $id = (string) $student['id'];
            $students[$index]['classroom_name'] = $classrooms[$id] ?? null;
            $students[$index]['average'] = $averages[$id] ?? null;
        }

        return $students;
    }

    /**
     * Identifiants des eleves inscrits dans une classe.
     *
     * @return list<string>
     */
    private function studentIdsInClassroom(string $classroomId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['student_id'],
            $this->app->db()->select(
                'SELECT student_id FROM enrollments
                 WHERE tenant_id = :tenant AND classroom_id = :classroom
                   AND deleted_at IS NULL AND status = \'ACTIVE\'',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'classroom' => $classroomId,
                ]
            )
        );
    }
}
