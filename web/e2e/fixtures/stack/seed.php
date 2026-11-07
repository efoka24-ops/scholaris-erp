<?php

declare(strict_types=1);

/**
 * Données fictives des parcours Playwright (C-110), semées PAR L'API RÉELLE dans la base de la pile de test (E2E_DIR) :
 * mêmes appels que les recettes PHP de bout en bout (tests/E2E/Support/*), réutilisées telles quelles, mais traitées en
 * mémoire (sans HTTP) pour aller vite. Affiche un JSON (identifiants de connexion fictifs, identifiants des ressources).
 *
 *   php seed.php ipes
 *
 * Aucune donnée réelle : noms « Fictif », numéros +237 699 5xx xxx réservés aux tests.
 */

use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Platform\Application\AdministratorAccounts;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;
use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\BuildsIpesGrades;
use Tests\E2E\Support\RealStackTestCase;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Identity\RecordingSms;
use Tests\Support\Identity\TestClock;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$e2eSms = new RecordingSms();
$e2eMail = new RecordingMail();
$e2eClock = new TestClock(time());
// Horloge réelle (pas de TestClock côté serveur) : les codes TOTP sont calculés à l'heure du serveur HTTP qui les vérifiera.
$e2eOverrides = [SmsGateway::class => $e2eSms, MailGateway::class => $e2eMail];
/** @var Kernel $kernel */
$kernel = require __DIR__ . '/boot.php';

final class E2eSeeder extends RealStackTestCase
{
    use BuildsIpes;
    use BuildsIpesGrades;

    private int $seq;

    public function __construct(Kernel $kernel, TestClock $clock, RecordingSms $sms, RecordingMail $mail)
    {
        parent::__construct('seed');
        $this->kernel = $kernel;
        $this->clock = $clock;
        $this->sms = $sms;
        $this->mail = $mail;
        $this->storage = (string) getenv('E2E_DIR');
        // Plage fictive propre à ce passage : plusieurs semis peuvent cohabiter dans la même base.
        $this->seq = random_int(100, 899) * 100;
    }

    /** @var array<string, array{0: string, 1: string}> */
    private array $creds = [];
    /** @var array<string, string> jeton périmé => jeton de la reconnexion */
    private array $alias = [];

    protected function signIn(string $login, string $password): string
    {
        $token = parent::signIn($login, $password);
        $this->creds[$token] = [$login, $password];

        return $token;
    }

    /**
     * Un rôle sensible attribué ferme les sessions sans double authentification (règle réelle) : la personne se reconnecte,
     * comme elle le ferait à l'écran, et l'appel est rejoué une fois.
     */
    protected function api(string $method, string $path, ?string $token, ?array $body = null, ?string $establishment = null, array $headers = []): array
    {
        while ($token !== null && isset($this->alias[$token])) {
            $token = $this->alias[$token];
        }
        $t0 = microtime(true);
        $result = parent::api($method, $path, $token, $body, $establishment, $headers);
        if (getenv('E2E_TRACE') === '1') {
            fwrite(STDERR, sprintf("%.2f %s %s %d
", microtime(true) - $t0, $method, $path, $result[0]->getStatusCode()));
        }
        if ($result[0]->getStatusCode() === 401 && $token !== null && isset($this->creds[$token])) {
            [$login, $password] = $this->creds[$token];
            $fresh = $this->signIn($login, $password);
            $this->alias[$token] = $fresh;
            $result = parent::api($method, $path, $fresh, $body, $establishment, $headers);
        }

        return $result;
    }

    /**
     * Activation réelle ; si une invitation vient de partir (délai de 60 s entre deux codes), on patiente comme la personne.
     */
    protected function activate(string $phone, string $password): void
    {
        [$response] = $this->api('POST', '/auth/activation/request', null, ['identifier' => $phone]);
        if ($response->getStatusCode() === 429) {
            sleep(61);
            [$response] = $this->api('POST', '/auth/activation/request', null, ['identifier' => $phone]);
        }
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $code = $this->sms->lastCode($phone);
        self::assertNotNull($code, 'Aucun code d\'activation reçu par ' . $phone);
        $verified = $this->ok('POST', '/auth/activation/verify', null, ['identifier' => $phone, 'code' => $code]);
        $this->ok('POST', '/auth/activation/password', null, ['ticket' => (string) $verified['ticket'], 'password' => $password]);
    }

    protected function nextPhone(): string
    {
        return sprintf('+2376997%05d', ++$this->seq);
    }

    /** Client mis en service par la plateforme (application en essai), administrateur activé et connecté. */
    public function client(string $app, string $type, string $name, string $first, string $last): array
    {
        $c = $this->kernel->container();
        $phone = $this->nextPhone();
        $client = $c->get(ClientLifecycle::class)->create([
            'name' => $name, 'contact_name' => $first . ' ' . $last, 'contact_email' => null, 'contact_phone' => $phone,
            'city' => 'Garoua', 'region' => 'Nord', 'locale' => 'fr', 'establishment_type' => $type,
        ], 'console', null);
        $tenant = (string) $client['id'];
        $c->get(EntitlementService::class)->open($tenant, $app, EntitlementService::SOURCE_PLATFORM, 'console');
        $c->get(AdministratorAccounts::class)->invite($tenant, $name, ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => null, 'locale' => 'fr'], 'console', null);
        $password = 'Recette-E2E-' . substr(md5($phone), 0, 8) . '!';
        $this->activate($phone, $password);
        $admin = $this->signIn($phone, $password);
        $me = $this->ok('GET', '/me', $admin);
        $establishment = (string) $this->ok('GET', '/establishments', $admin)['data'][0]['id'];

        return [
            'tenant' => $tenant, 'establishment' => $establishment, 'admin' => $admin, 'name' => $name,
            'admin_person' => (string) ($me['data']['person_id'] ?? $me['person_id'] ?? ''),
            'logins' => ['admin' => [$phone, $password]], 'tokens' => [], 'people' => [],
        ];
    }

    /** @var array<string, string> dernier code TOTP présenté par secret (le serveur refuse le rejeu) */
    private array $usedTotp = [];

    /** Code TOTP à l'heure réelle du serveur ; attend la fenêtre suivante si le code courant a déjà servi. */
    protected function totp(string $login): string
    {
        $secret = $this->totpSecrets[$login];
        for (;;) {
            $code = \OTPHP\TOTP::createFromSecret($secret)->at(time());
            if (($this->usedTotp[$secret] ?? null) !== $code) {
                $this->usedTotp[$secret] = $code;

                return $code;
            }
            usleep((30 - time() % 30) * 1_000_000 + 200_000);
        }
    }

    /**
     * Session récente par double authentification (opérations sensibles : verrouillage, signature du PV).
     *
     * @param array<string, mixed> $ctx
     */
    private function recent2fa(array &$ctx, string $who): void
    {
        [$login] = $ctx['logins'][$who];
        if (!isset($this->totpSecrets[$login])) {
            $this->enrol2fa($ctx, $who);

            return;
        }
        $this->ok('POST', '/auth/2fa/verify', $ctx['tokens'][$who], ['code' => $this->totp($login)]);
    }

    /**
     * IPES (LMD) : paramétrage, personnel, maquette publiée, étudiante Marie admise, inscrite, réglée et validée, appel, notes de
     * huit EC sur neuf saisies, validées et verrouillées (l'EC « BUR » reste à saisir à l'écran), jury du groupe préparé.
     *
     * @return array<string, mixed>
     */
    public function ipes(): array
    {
        $ctx = $this->client('higher', 'higher', 'Institut Supérieur Fictif de Recette', 'Abdoulaye', 'Fictif-Directeur');
        $this->buildAcademic($ctx, 'lmd', 'ESG', 'CF', 'Comptabilité et finance');
        $this->staff($ctx);
        $this->curriculum($ctx);
        $this->feeCatalog($ctx);

        $application = $this->application($ctx, 'Marie', 'Fictive-Etudiante');
        $this->admit($ctx, (string) $application['id']);
        $enrolment = $this->as($ctx, 'scol', 'POST', '/higher/enrolments', ['application_id' => $application['id'], 'group_id' => $ctx['org']['g1']])['data'];
        $person = (string) $enrolment['person_id'];
        $y = self::startYear();
        $this->as($ctx, 'compta', 'POST', '/higher/billing/fee-grids', [
            'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'], 'level_id' => $ctx['org']['l1'],
            'study_mode' => 'day', 'label' => 'Niveau 1, cours du jour', 'start_year' => $y,
            'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 50000], ['fee_code' => 'hi.pension', 'amount_xaf' => 450000]],
        ], 201);
        $account = $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts', [
            'person_id' => $person, 'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'],
            'level_id' => $ctx['org']['l1'], 'study_mode' => 'day',
        ], 201)['data'];
        $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts/' . $account['id'] . '/payments', [
            'amount_xaf' => 200000, 'channel' => 'orange_money', 'external_reference' => 'OM-CF-E2E-0001',
        ], 201, ['Idempotency-Key' => 'e2e-' . $account['id']]);
        $validated = $this->as($ctx, 'scol', 'POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [])['data'];
        $ctx['student'] = ['person' => $person, 'application' => (string) $application['id'], 'enrolment' => $validated];

        // Compte de l'étudiante (créé en attente à la candidature) : rôle apprenant donné par l'administrateur, activation par SMS.
        $stmt = $this->pdo()->prepare('SELECT phone FROM users WHERE person_id = ?');
        $stmt->execute([$person]);
        $studentPhone = (string) $stmt->fetchColumn();
        $this->assignRole($ctx, $person, 'apprenant', 'establishment');
        $studentPassword = 'Recette-Etudiante-' . substr(md5($studentPhone), 0, 6) . '!';
        $this->activate($studentPhone, $studentPassword);
        $ctx['logins']['student'] = [$studentPhone, $studentPassword];

        $this->teaching($ctx);

        // Notes de Marie N. (exemple du CDC) pour huit EC ; BUR (CC 16, examen 15,5) sera saisi à l'écran par l'enseignant.
        $student = $ctx['student']['person'];
        foreach (self::marieNotes() as $ec => $notes) {
            $element = $ctx['curriculum']['elements'][$ec];
            $this->try($ctx, 'chef', 'POST', '/higher/teaching/assignments', [
                'person_id' => $ctx['people']['prof'], 'curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $element,
                'group_id' => $ctx['org']['g1'], 'session_kind' => 'cm', 'planned_minutes' => 60 * 60,
            ]);
            if ($ec === 'BUR') {
                continue;
            }
            foreach (['cc', 'exam'] as $i => $kind) {
                $id = (string) $this->res($ctx, 'prof', 'POST', '/higher/grade-assessments', ['curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $element, 'kind' => $kind])['id'];
                $this->as($ctx, 'prof', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => $notes[$i]]]]);
                $this->as($ctx, 'prof', 'POST', '/higher/grade-assessments/' . $id . '/submit');
                $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/validate');
                [$status] = $this->try($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
                if ($status >= 300) {
                    $this->recent2fa($ctx, 'chef');
                    $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
                }
            }
        }

        $this->actor($ctx, 'jury', 'Zakari', 'Fictif-Jury', [['higher_jury', 'structure', $ctx['org']['program']]]);
        $jury = $this->res($ctx, 'chef', 'POST', '/higher/deliberation/juries', [
            'structure_id' => $ctx['org']['g1'], 'period_id' => 'S1', 'period_label' => 'Semestre 1', 'academic_year_id' => $ctx['year'],
            'exam_session' => 'normal', 'president_person_id' => $ctx['people']['dir'],
            'members' => [$ctx['people']['dir'], $ctx['people']['chef'], $ctx['people']['jury']], 'quorum_min' => 2,
        ], 201);
        $ctx['jury'] = (string) $jury['id'];

        return $this->withSecrets($ctx);
    }

    /**
     * Sonde de mise au point (non utilisée par les specs) : fin de saisie, séance, signature, relevé DOC-09.
     *
     * @return array<string, mixed>
     */
    public function ipesProbe(): array
    {
        $ctx = $this->ipes();
        $out = [];
        $student = $ctx['student']['person'];
        foreach (['cc' => 16, 'exam' => 15.5] as $kind => $score) {
            $id = (string) $this->res($ctx, 'prof', 'POST', '/higher/grade-assessments', ['curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $ctx['curriculum']['elements']['BUR'], 'kind' => $kind])['id'];
            $this->as($ctx, 'prof', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => $score]]]);
            $this->as($ctx, 'prof', 'POST', '/higher/grade-assessments/' . $id . '/submit');
            $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/validate');
            $out['lock_' . $kind] = $this->try($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock')[0];
        }
        $out['simulate'] = $this->try($ctx, 'chef', 'POST', '/higher/deliberation/juries/' . $ctx['jury'] . '/simulate', []);
        $body = ['structure_id' => $ctx['org']['g1'], 'period_id' => 'S1', 'period_label' => 'Semestre 1', 'exam_session' => 'normal', 'president_id' => $ctx['people']['dir'], 'members_expected' => 3];
        foreach (['dir', 'chef', 'scol', 'admin'] as $who) {
            $open = $this->try($ctx, $who, 'POST', '/deliberations', $body);
            $out['open_' . $who] = $open[0];
            if ($open[0] < 300) {
                break;
            }
        }
        $out['open'] = $open;
        $sid = (string) ($open[1]['data']['id'] ?? $open[1]['id'] ?? '');
        $out['attendance'] = $this->try($ctx, $who, 'POST', '/deliberations/' . $sid . '/attendance', ['members_present' => [$ctx['people']['dir'], $ctx['people']['chef']]])[0];
        $out['start'] = $this->try($ctx, $who, 'POST', '/deliberations/' . $sid . '/start')[0];
        $out['close'] = $this->try($ctx, $who, 'POST', '/deliberations/' . $sid . '/close');
        if ($out['close'][0] === 403) {
            $this->recent2fa($ctx, $who);
            $out['close2'] = $this->try($ctx, $who, 'POST', '/deliberations/' . $sid . '/close');
        }
        foreach (['dir', 'jury'] as $signer) {
            $out['sign_' . $signer] = $this->try($ctx, $signer, 'POST', '/deliberations/' . $sid . '/sign');
            if ($out['sign_' . $signer][0] === 403) {
                $this->recent2fa($ctx, $signer);
                $out['sign2_' . $signer] = $this->try($ctx, $signer, 'POST', '/deliberations/' . $sid . '/sign');
            }
        }
        foreach (['scol', 'dir', 'admin'] as $who) {
            $out['publish_' . $who] = $this->try($ctx, $who, 'POST', '/results/publish', ['structure_id' => $ctx['org']['g1'], 'period_id' => 'S1']);
            if ($out['publish_' . $who][0] < 300) {
                break;
            }
        }
        $out['sitting'] = $this->try($ctx, 'admin', 'GET', '/deliberations/' . $sid);
        $out['preview'] = $this->try($ctx, 'scol', 'GET', '/higher/deliberation/transcripts?person_id=' . $student . '&period_id=S1');
        $out['issue'] = $this->try($ctx, 'scol', 'POST', '/higher/deliberation/transcripts', ['person_id' => $student, 'period_id' => 'S1']);

        return $out;
    }

    /**
     * Année scolaire en cours, trois trimestres, une classe et son autorisation administrative (socle, par l'administrateur).
     *
     * @param array<string, mixed> $ctx
     */
    private function schoolYear(array &$ctx, string $className, string $authority): string
    {
        $y = self::startYear();
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'], 201)['data']['id'];
        $ctx['periods'] = [];
        foreach ([[1, $y . '-09-01', $y . '-12-18'], [2, ($y + 1) . '-01-04', ($y + 1) . '-03-26'], [3, ($y + 1) . '-04-06', ($y + 1) . '-06-30']] as [$ordinal, $from, $to]) {
            $ctx['periods'][] = (string) $this->as($ctx, 'admin', 'POST', '/periods', [
                'academic_year_id' => $ctx['year'], 'kind' => 'trimester', 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to,
            ], 201)['data']['id'];
        }

        return $this->schoolClass($ctx, $className, $authority);
    }

    /** @param array<string, mixed> $ctx */
    private function schoolClass(array &$ctx, string $className, string $authority, ?string $parent = null): string
    {
        $class = (string) $this->as($ctx, 'admin', 'POST', '/structures', ['kind' => 'class', 'name' => $className, 'capacity' => 60] + ($parent ? ['parent_id' => $parent] : []), 201)['data']['id'];
        $this->as($ctx, 'admin', 'POST', '/authorizations', [
            'structure_id' => $class, 'reference' => 'ARR-' . strtoupper(substr(md5($className), 0, 6)), 'authority' => $authority,
            'valid_from' => (self::startYear() - 1) . '-01-01', 'valid_to' => (self::startYear() + 3) . '-12-31',
        ], 201);
        $ctx['classes'][$className] = $class;

        return $class;
    }

    /**
     * Enfant ou élève fictif inscrit (fiche, inscription, validation) ; renvoie la fiche.
     *
     * @param array<string, mixed> $ctx
     */
    private function pupil(array $ctx, string $class, string $first, string $last, string $birth, string $gender = 'female', ?string $guardianId = null): string
    {
        $person = (string) $this->as($ctx, 'admin', 'POST', '/people', ['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth, 'gender' => $gender], 201)['data']['id'];
        $guardian = $guardianId ?? (string) $this->as($ctx, 'admin', 'POST', '/people', ['first_name' => 'Parent de ' . $first, 'last_name' => $last, 'phone' => $this->nextPhone()], 201)['data']['id'];
        $this->as($ctx, 'admin', 'POST', '/people/' . $person . '/relations', ['related_person_id' => $guardian, 'kind' => 'parent']);
        $enrollment = (string) $this->as($ctx, 'admin', 'POST', '/enrollments', ['person_id' => $person, 'structure_id' => $class, 'academic_year_id' => $ctx['year']], 201)['data']['id'];
        $this->as($ctx, 'admin', 'POST', '/enrollments/' . $enrollment . '/validate', []);

        return $person;
    }

    /**
     * Client d'une application, année en cours et compte de direction (rôle propre à l'application) : socle d'exploration.
     *
     * @return array<string, mixed>
     */
    public function staffed(string $app, string $type, string $role): array
    {
        $ctx = $this->client($app, $type, 'École fictive ' . $app, 'Awa', 'Fictive-Admin');
        $y = self::startYear();
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'], 201)['data']['id'];
        $this->actor($ctx, 'director', 'Moussa', 'Fictif-Directeur', [[$role, 'establishment']]);

        return $this->withSecrets($ctx);
    }

    /**
     * Client d'une application dont l'administrateur (super administrateur) est aussi la direction de l'école : il cumule les
     * rôles de l'application donnés en arguments (séparés par des virgules), année en cours créée. Cas des petites écoles privées.
     *
     * @return array<string, mixed>
     */
    public function owner(string $app, string $type, string $roles): array
    {
        $ctx = $this->client($app, $type, 'École fictive ' . $app, 'Awa', 'Fictive-Directrice');
        $y = self::startYear();
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'], 201)['data']['id'];
        $ctx['periods'] = [];
        foreach ([[1, $y . '-09-01', $y . '-12-18'], [2, ($y + 1) . '-01-04', ($y + 1) . '-03-26'], [3, ($y + 1) . '-04-06', ($y + 1) . '-06-30']] as [$ordinal, $from, $to]) {
            $ctx['periods'][] = (string) $this->as($ctx, 'admin', 'POST', '/periods', [
                'academic_year_id' => $ctx['year'], 'kind' => 'trimester', 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to,
            ], 201)['data']['id'];
        }
        foreach (array_filter(explode(',', $roles)) as $role) {
            $this->assignRole($ctx, $ctx['admin_person'], $role, 'establishment');
        }

        return $this->withSecrets($ctx);
    }

    /**
     * Primaire public : école, année et trimestres, unité « Cycle III » (niveau du socle), directeur d'école et enseignant titulaire
     * (comptes activés, fiche du personnel de l'enseignant). Classes et élèves sont créés à l'écran par le parcours.
     *
     * @return array<string, mixed>
     */
    public function publicPrimary(): array
    {
        $ctx = $this->client('public_primary', 'primary', 'École publique fictive de Recette', 'Awa', 'Fictive-Admin');
        $y = self::startYear();
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'], 201)['data']['id'];
        $ctx['periods'] = [];
        foreach ([[1, $y . '-09-01', $y . '-12-18'], [2, ($y + 1) . '-01-04', ($y + 1) . '-03-26'], [3, ($y + 1) . '-04-06', ($y + 1) . '-06-30']] as [$ordinal, $from, $to]) {
            $ctx['periods'][] = (string) $this->as($ctx, 'admin', 'POST', '/periods', [
                'academic_year_id' => $ctx['year'], 'kind' => 'trimester', 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to,
            ], 201)['data']['id'];
        }
        $ctx['unit'] = (string) $this->as($ctx, 'admin', 'POST', '/structures', ['kind' => 'level', 'name' => 'Cycle III'], 201)['data']['id'];
        $this->actor($ctx, 'director', 'Moussa', 'Fictif-Directeur', [['pub_school_director', 'establishment']]);
        $this->actor($ctx, 'teacher', 'Paul', 'Fictif-Instituteur', [['pub_class_teacher', 'establishment']]);
        [$status, $staff] = $this->try($ctx, 'admin', 'POST', '/staff', ['person_id' => $ctx['people']['teacher'], 'kind' => 'permanent']);
        $ctx['teacher_staff'] = $status < 300 ? (string) ($staff['data']['id'] ?? '') : null;

        return $this->withSecrets($ctx);
    }

    /**
     * Secondaire : lycée d'enseignement général (code de type « leg ») ouvert par le parcours réel de la plateforme (demande d'accès
     * avec le type, acceptation par l'administrateur de plateforme avec 2FA), administrateur activé qui est aussi chef
     * d'établissement et scolarité, année en cours. Le profil n'est PAS ouvert : l'assistant de profil le fait à l'écran.
     *
     * @return array<string, mixed>
     */
    public function secondaryLeg(): array
    {
        $phone = $this->nextPhone();
        $name = 'Lycée général fictif de Recette ' . substr($phone, -3);
        [$submitted, $json] = $this->publicCall('POST', '/public/access-requests', [
            'institution_name' => $name, 'target_type' => 'secondary', 'city' => 'Garoua', 'region' => 'Nord',
            'contact_first_name' => 'Hamadou', 'contact_last_name' => 'Fictif-Proviseur', 'contact_email' => 'proviseur' . substr($phone, -5) . '@lycee-recette.test',
            'contact_phone' => $phone, 'app_key' => 'secondary', 'establishment_subtype' => 'leg',
        ], '198.51.100.' . random_int(20, 250));
        self::assertSame(202, $submitted->getStatusCode(), (string) $submitted->getBody());
        $platform = $this->platform();
        $pending = $this->ok('GET', '/platform/access-requests?filter[status]=pending', $platform)['data'];
        $mine = array_values(array_filter($pending, static fn (array $r): bool => ($r['institution_name'] ?? null) === $name));
        $accepted = $this->ok('POST', '/platform/access-requests/' . $mine[0]['id'] . '/accept', $platform, ['force_reason' => 'Établissement pilote de la recette.'])['data'];
        $password = 'Recette-E2E-' . substr(md5($phone), 0, 8) . '!';
        $this->activate($phone, $password);
        $admin = $this->signIn($phone, $password);
        $me = $this->ok('GET', '/me', $admin);
        $ctx = [
            'tenant' => (string) $accepted['tenant_id'], 'establishment' => (string) $accepted['establishment_id'], 'admin' => $admin, 'name' => $name,
            'admin_person' => (string) ($me['data']['person_id'] ?? $me['person_id'] ?? ''), 'logins' => ['admin' => [$phone, $password]], 'tokens' => [], 'people' => [],
        ];
        $y = self::startYear();
        $ctx['year'] = (string) $this->as($ctx, 'admin', 'POST', '/academic-years', ['label' => $y . '-' . ($y + 1), 'starts_on' => $y . '-09-01', 'ends_on' => ($y + 1) . '-06-30'], 201)['data']['id'];
        foreach (['sec_chef_etablissement', 'sec_scolarite'] as $role) {
            $this->assignRole($ctx, $ctx['admin_person'], $role, 'establishment');
        }

        return $this->withSecrets($ctx);
    }

    /**
     * Rôles restreints d'une application : enseignant, caissier (si l'application en a un) et parent, comptes activés.
     *
     * @return array<string, mixed>
     */
    public function roles(string $app, string $type, string $directorRole, string $teacherRole, string $cashierRole = ''): array
    {
        $ctx = $this->staffed($app, $type, $directorRole);
        $this->actor($ctx, 'teacher', 'Paul', 'Fictif-Enseignant', [[$teacherRole, 'establishment']]);
        if ($cashierRole !== '') {
            $this->actor($ctx, 'cashier', 'Cathy', 'Fictive-Caissiere', [[$cashierRole, 'establishment']]);
        }
        $this->actor($ctx, 'parent', 'Pauline', 'Fictive-Parent', [['parent', 'establishment']]);

        return $this->withSecrets($ctx);
    }

    /**
     * Maternelle publique : classe « Petite section A » de 31 enfants (plafond de 30 dépassé), contribution exigible paramétrée.
     *
     * @return array<string, mixed>
     */
    public function publicNursery(): array
    {
        $ctx = $this->client('public_nursery', 'nursery', 'École maternelle publique fictive de Recette', 'Hadja', 'Fictive-Directrice');
        $class = $this->schoolYear($ctx, 'Petite section A', 'MINEDUB');
        $ctx['children'] = [];
        for ($i = 1; $i <= 31; ++$i) {
            $ctx['children'][] = $this->pupil($ctx, $class, 'Enfant' . $i, 'Fictif-PSA', (self::startYear() - 4) . '-0' . (1 + $i % 9) . '-1' . ($i % 9), $i % 2 ? 'female' : 'male');
        }

        return $this->withSecrets($ctx);
    }

    /**
     * Secrets TOTP fictifs des acteurs enrôlés : le test génère les codes comme l'application d'authentification.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function withSecrets(array $ctx): array
    {
        $ctx['totp'] = [];
        foreach ($ctx['logins'] as $who => [$login]) {
            if (isset($this->totpSecrets[$login])) {
                $ctx['totp'][$who] = $this->totpSecrets[$login];
            }
        }

        return $ctx;
    }
}

$seeder = new E2eSeeder($kernel, $e2eClock, $e2eSms, $e2eMail);
$scenario = $argv[1] ?? '';
if (!method_exists($seeder, $scenario)) {
    fwrite(STDERR, "Scénario inconnu : {$scenario}\n");
    exit(2);
}
$result = $seeder->{$scenario}(...array_slice($argv, 2));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
