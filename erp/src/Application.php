<?php

declare(strict_types=1);

namespace Scholaris;

use PDOException;
use Scholaris\Auth\AccountActivation;
use Scholaris\Auth\Auth;
use Scholaris\Auth\Rbac;
use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Http\Router;
use Scholaris\Notification\EstablishmentMails;
use Scholaris\Notification\Mailer;
use Scholaris\Notification\SmtpTransport;
use Scholaris\Offline\OperationLog;
use Scholaris\Security\Csrf;
use Scholaris\Security\Session;
use Scholaris\Support\Env;
use Scholaris\Tenant\Features;
use Scholaris\Tenant\TenantContext;
use Scholaris\View\View;
use Throwable;

/**
 * Assemblage et execution de l'application.
 *
 * Les controles transversaux vivent ici et nulle part ailleurs : verification
 * CSRF, authentification, permission de route. Un controleur n'a donc rien a
 * refaire, et ne peut rien oublier.
 */
final class Application
{
    private Env $env;

    private Connection $db;

    private Session $session;

    private Csrf $csrf;

    private TenantContext $tenant;

    private Auth $auth;

    private Rbac $rbac;

    private View $view;

    private Router $router;

    private ?Features $features = null;

    private ?Mailer $mailer = null;

    /** Chemin de la requete en cours, pour les journaux et la page d erreur. */
    private ?string $currentPath = null;

    private string $basePath;

    public function __construct(string $basePath, Env $env, Connection $db)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->env = $env;
        $this->db = $db;

        $this->session = new Session();
        $this->csrf = new Csrf($this->session);
        $this->tenant = new TenantContext();
        $this->auth = new Auth($db, $this->session, $this->tenant);
        $this->rbac = new Rbac($db, $this->auth);
        $this->view = new View($this->basePath.'/templates');
        $this->router = new Router();
    }

    public static function boot(string $basePath): self
    {
        $env = Env::load($basePath.'/.env');

        $driver = $env->get('DB_CONNECTION', 'mysql');

        $db = $driver === 'sqlite'
            ? Connection::sqlite(self::resolveSqlitePath($basePath, $env->require('DB_DATABASE')))
            : Connection::mysql(
                $env->get('DB_HOST', 'localhost') ?? 'localhost',
                $env->require('DB_DATABASE'),
                $env->require('DB_USERNAME'),
                $env->get('DB_PASSWORD', '') ?? '',
                (int) ($env->get('DB_PORT', '3306') ?? '3306')
            );

        $app = new self($basePath, $env, $db);
        $app->loadRoutes();

        return $app;
    }

    /**
     * Resout un chemin SQLite relatif depuis la racine du projet.
     *
     * Le repertoire courant depend du serveur : Apache l'ancre sur la racine
     * web, le serveur integre de PHP sur le dossier servi. Un chemin relatif
     * designerait donc un fichier different selon le contexte.
     */
    private static function resolveSqlitePath(string $basePath, string $path): string
    {
        if ($path === ':memory:' || $path === '' || preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path) === 1) {
            return $path;
        }

        return rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.$path;
    }

    /**
     * Charge la table des routes.
     *
     * Le fichier routes.php attend une variable $app : la fournir ici evite que
     * chaque appelant (point d'entree, tests) ait a la mettre en place.
     */
    public function loadRoutes(): void
    {
        $app = $this;

        require $this->basePath.'/routes.php';
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function db(): Connection
    {
        return $this->db;
    }

    public function tenant(): TenantContext
    {
        return $this->tenant;
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function rbac(): Rbac
    {
        return $this->rbac;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    public function view(): View
    {
        return $this->view;
    }

    public function env(): Env
    {
        return $this->env;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Fonctionnalites actives pour l'etablissement courant.
     *
     * Construites une fois par requete : la matrice et la configuration de
     * l'etablissement ne changent pas en cours de traitement.
     */
    public function features(): Features
    {
        if ($this->features !== null) {
            return $this->features;
        }

        $tenant = null;

        if ($this->tenant->isSet()) {
            $tenant = $this->tenant->global(fn () => $this->db->selectOne(
                'SELECT type, config_json FROM tenants WHERE id = :id',
                ['id' => $this->tenant->id()]
            ));
        }

        return $this->features = Features::forTenant($this->basePath, $tenant);
    }

    /**
     * Courriers sortants de la plateforme.
     *
     * La remise est desactivee tant que MAIL_FROM n'est pas renseigne : les
     * courriers sont alors journalises sans quitter la machine, ce qui evite
     * qu'un environnement de test n'ecrive a de vraies personnes.
     */
    public function mailer(): Mailer
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        $host = $this->env->get('MAIL_HOST', '') ?? '';

        $smtp = $host === '' ? null : new SmtpTransport(
            $host,
            (int) ($this->env->get('MAIL_PORT', '465') ?? '465'),
            $this->env->get('MAIL_USERNAME', '') ?? '',
            $this->env->get('MAIL_PASSWORD', '') ?? '',
            $this->env->get('MAIL_ENCRYPTION', 'ssl') ?? 'ssl'
        );

        return $this->mailer = new Mailer(
            $this->db,
            $this->env->get('MAIL_FROM', '') ?? '',
            $this->env->get('MAIL_FROM_NAME', 'SCHOLARIS') ?? 'SCHOLARIS',
            $this->env->bool('MAIL_ENABLED', true),
            $smtp
        );
    }

    public function establishmentMails(): EstablishmentMails
    {
        return new EstablishmentMails(
            $this->mailer(),
            $this->env->get('APP_NAME', 'SCHOLARIS') ?? 'SCHOLARIS',
            $this->env->get('APP_URL', '') ?? '',
            $this->systemTemplates()
        );
    }

    /** Jetons d'activation de compte (chantier "premiere connexion"). */
    public function accountActivation(): AccountActivation
    {
        return new AccountActivation($this->db);
    }

    /** Courriers de compte (bienvenue, relance, incident, suspension). */
    public function accountMails(): \Scholaris\Notification\AccountMails
    {
        return new \Scholaris\Notification\AccountMails(
            $this->mailer(),
            $this->env->get('APP_NAME', 'SCHOLARIS') ?? 'SCHOLARIS',
            $this->env->get('APP_URL', '') ?? '',
            $this->systemTemplates()
        );
    }

    /**
     * Modeles systeme (tenant_id NULL), avec repli sur le texte code en dur si
     * aucun modele n'est enregistre : l'envoi ne doit jamais dependre de la
     * presence d'une configuration en base.
     */
    public function systemTemplates(): \Scholaris\Notification\SystemTemplates
    {
        return new \Scholaris\Notification\SystemTemplates($this->db);
    }

    /** Oublie les fonctionnalites en cache, apres un changement de contexte. */
    public function resetFeatures(): void
    {
        $this->features = null;
    }

    public function isProduction(): bool
    {
        return $this->env->get('APP_ENV', 'production') === 'production';
    }

    public function table(string $name): Table
    {
        return new Table($this->db, $this->tenant, $name);
    }

    public function handle(Request $request): Response
    {
        // Retenu avant tout traitement : la page d'erreur et le journal en ont
        // besoin, y compris quand l'echec survient avant le routage.
        $this->currentPath = $request->path();

        try {
            $this->session->start($this->env->bool('SESSION_SECURE', false));
            $this->auth->restore();

            $route = $this->router->match($request);

            // Une requete d'ecriture emise par un autre site est rejetee avant
            // d'atteindre le moindre controleur.
            if ($request->method() !== 'GET') {
                $this->csrf->verify($request);
            }

            if (! $route['guest'] && ! $this->auth->check()) {
                return Response::redirect('/login');
            }

            // Un utilisateur deja connecte n'a rien a faire sur la page de
            // connexion : il est renvoye vers son tableau de bord.
            if ($route['guest'] && $this->auth->check() && $request->path() === '/login') {
                return Response::redirect('/dashboard');
            }

            // Mot de passe provisoire jamais renouvele : la session reste
            // ouverte mais cantonnee a l'ecran de changement, sans quoi le mot
            // de passe qui a circule par courrier resterait indefiniment en
            // service.
            if ($this->auth->check() && $this->auth->mustChangePassword()
                && ! in_array($request->path(), ['/mot-de-passe/changer', '/logout'], true)) {
                return Response::redirect('/mot-de-passe/changer');
            }

            // Role a privileges sans double authentification : la connexion
            // n'est pas bloquee, mais l'ecran d'enrolement est propose a
            // chaque requete tant qu'il n'a pas ete traite, sauf si
            // l'utilisateur a explicitement choisi de le faire plus tard.
            if ($this->auth->check() && $this->auth->needsMfaEnrollment()
                && $this->session->get('mfa_enroll_dismissed') !== true
                && ! str_starts_with($request->path(), '/mfa/')
                && $request->path() !== '/logout') {
                return Response::redirect('/mfa/enroler');
            }

            // Une fonctionnalite absente du type d'etablissement donne 404 et
            // non 403 : elle n'existe pas ici, il ne s'agit pas d'un refus de
            // droit. Le controle passe avant celui des permissions, pour ne pas
            // reveler l'existence d'un module etranger a l'etablissement.
            if ($route['feature'] !== null && $this->features()->disabled($route['feature'])) {
                throw new HttpException(404);
            }

            if ($route['permission'] !== null && $this->rbac->denies($route['permission'])) {
                throw new HttpException(403, "Permission requise : {$route['permission']}");
            }

            $this->shareViewData();

            return $this->dispatchOnce($route['handler'], $request);
        } catch (HttpException $e) {
            return $this->renderError($e->statusCode(), $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderUnexpected($e);
        }
    }

    /**
     * Execute l'action, une fois et une seule.
     *
     * Une ecriture rejouee depuis la file hors-ligne porte le jeton "_op". Si
     * ce jeton a deja ete applique, l'action n'est pas rejouee : le client
     * recoit la meme destination qu'a la premiere tentative. C'est ce qui
     * evite qu'un appel fait sans reseau, puis renvoye deux fois au retour de
     * la connexion, ne cree deux enregistrements.
     *
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    private function dispatchOnce($handler, Request $request): Response
    {
        $token = (string) $request->input('_op', '');

        if ($request->method() === 'GET' || $token === '' || ! OperationLog::isWellFormed($token)) {
            return $this->dispatch($handler, $request);
        }

        $log = new OperationLog($this->db);

        // Le journal est une protection, pas une condition. Si la table
        // manque — deploiement des fichiers fait avant la migration — la
        // saisie doit passer quand meme : refuser l'enregistrement d'un appel
        // parce qu'on ne sait pas le dedoublonner serait un remede pire que
        // le mal.
        try {
            $replay = $log->replayOf($token);
        } catch (PDOException $e) {
            return $this->dispatch($handler, $request);
        }

        if ($replay !== null) {
            $this->session->flash('success', 'Enregistrement deja pris en compte.');

            return Response::redirect($replay);
        }

        $response = $this->dispatch($handler, $request);

        // Seules les operations qui ont abouti sont journalisees : un echec
        // doit rester rejouable, sans quoi une saisie perdue le serait
        // definitivement.
        if ($response->status() >= 200 && $response->status() < 400) {
            $user = $this->auth->user();

            try {
                $log->record(
                    $token,
                    $this->tenant->isGlobal() ? null : $this->tenant->id(),
                    $user['id'] ?? null,
                    $request->path(),
                    $response->header('Location')
                );
            } catch (PDOException $e) {
                // Deux appareils qui rejouent le meme jeton en meme temps :
                // l'un des deux perd la course a l'insertion. L'operation a
                // bien eu lieu, il n'y a rien a signaler a l'utilisateur.
            }
        }

        return $response;
    }

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    private function dispatch($handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class($this);
            $result = $controller->{$method}($request);
        } else {
            $result = $handler($request, $this);
        }

        return $result instanceof Response ? $result : Response::html((string) $result);
    }

    private function shareViewData(): void
    {
        $user = $this->auth->user();

        $this->view->share('auth', $user);
        $this->view->share('rbac', $this->rbac);
        $this->view->share('csrfToken', $this->csrf->token());
        $this->view->share('appName', $this->env->get('APP_NAME', 'SCHOLARIS'));
        $this->view->share('flashSuccess', $this->session->pullFlash('success'));
        $this->view->share('flashError', $this->session->pullFlash('error'));

        $tenantName = null;

        if ($user !== null && ($user['tenant_id'] ?? null) !== null) {
            $row = $this->tenant->global(fn () => $this->db->selectOne(
                'SELECT name FROM tenants WHERE id = :id',
                ['id' => $user['tenant_id']]
            ));
            $tenantName = $row['name'] ?? null;
        }

        $this->view->share('tenantName', $tenantName);
        $this->view->share('isPlatformAccount', $this->auth->isPlatformAccount());
        $this->view->share('features', $this->features());
    }

    /**
     * Page d'erreur.
     *
     * Un « 404 » sec ne dit rien a qui le recoit : l'adresse est-elle fausse,
     * le module absent de son etablissement, ou l'application cassee ? Chacune
     * de ces trois situations appelle une action differente, et l'utilisateur
     * ne peut pas les distinguer. La page les nomme donc.
     *
     * Les 404 sont par ailleurs journalisees : sans cela, un utilisateur
     * signale « une page introuvable » sans pouvoir dire laquelle, et il n'y a
     * aucun moyen de savoir ou il a clique.
     */
    private function renderError(int $status, string $message): Response
    {
        if ($status === 404) {
            $this->recordNotFound();
        }

        return Response::html(
            $this->view->render('errors.error', [
                'status' => $status,
                'message' => $message,
                'path' => $this->currentPath(),
                'isLogged' => $this->auth->check(),
                'home' => $this->auth->isPlatformAccount() && ! $this->tenant->isSet()
                    ? '/admin'
                    : '/dashboard',
            ]),
            $status
        );
    }

    /**
     * Chemin de la requete en cours.
     *
     * Retenu au debut du traitement plutot que relu dans $_SERVER : la
     * requete porte deja le chemin normalise, et le serveur de test n'alimente
     * pas ces variables globales.
     */
    private function currentPath(): string
    {
        return $this->currentPath ?? '/';
    }

    /**
     * Garde trace d'une adresse introuvable.
     *
     * L'ecriture ne doit jamais faire echouer l'affichage de la page d'erreur :
     * une base injoignable produit deja assez de degats sans qu'on y ajoute une
     * page blanche a la place du message.
     */
    private function recordNotFound(): void
    {
        try {
            $this->tenant->global(function (): void {
                $this->db->execute(
                    'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip_address, timestamp)
                     VALUES (:id, :user, :action, :resource, :resource_id, :ip, :timestamp)',
                    [
                        'id' => Table::uuid(),
                        'user' => $this->auth->id(),
                        'action' => 'http.not_found',
                        'resource' => 'route',
                        'resource_id' => substr($this->currentPath(), 0, 255),
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]
                );
            });
        } catch (Throwable $e) {
            // Sans consequence : la page d'erreur reste affichee.
        }
    }

    private function renderUnexpected(Throwable $e): Response
    {
        // Le detail part dans les journaux, jamais a l'ecran : un message
        // d'erreur PHP expose des chemins, des requetes, parfois des
        // identifiants de connexion a la base.
        error_log(sprintf(
            '[scholaris] %s: %s dans %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (! $this->isProduction()) {
            return Response::html(
                $this->view->render('errors.debug', [
                    'exception' => $e,
                    'isDatabase' => $e instanceof PDOException,
                ]),
                500
            );
        }

        return $this->renderError(500, 'Une erreur interne est survenue. L equipe technique a ete informee.');
    }
}
