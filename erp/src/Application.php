<?php

declare(strict_types=1);

namespace Scholaris;

use PDOException;
use Scholaris\Auth\Auth;
use Scholaris\Auth\Rbac;
use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Http\Router;
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

            return $this->dispatch($route['handler'], $request);
        } catch (HttpException $e) {
            return $this->renderError($e->statusCode(), $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderUnexpected($e);
        }
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

    private function renderError(int $status, string $message): Response
    {
        return Response::html(
            $this->view->render('errors.error', ['status' => $status, 'message' => $message]),
            $status
        );
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
