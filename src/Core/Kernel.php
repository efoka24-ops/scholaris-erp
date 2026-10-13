<?php

declare(strict_types=1);

namespace Scholaris\Core;

use DI\Container;
use Dotenv\Dotenv;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Container\ContainerFactory;
use Scholaris\Core\Http\ControllerInvoker;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\Middleware\Authenticate;
use Scholaris\Core\Http\Middleware\Cors;
use Scholaris\Core\Http\Middleware\ErrorHandler;
use Scholaris\Core\Http\Middleware\Idempotency;
use Scholaris\Core\Http\Middleware\JsonBodyLimit;
use Scholaris\Core\Http\Middleware\Locale;
use Scholaris\Core\Http\Middleware\RateLimit;
use Scholaris\Core\Http\Middleware\ResolveEstablishment;
use Scholaris\Core\Http\Middleware\RouteResolver;
use Scholaris\Core\Http\Middleware\SecurityHeaders;
use Scholaris\Core\Http\Pipeline;
use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Core\Idempotency\IdempotencyStore;
use Scholaris\Core\RateLimit\RateLimiter;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Tenancy\TenantContext;

/**
 * Noyau de l'application : charge l'environnement et la configuration, construit le conteneur,
 * découvre les routes des modules et traite une requête PSR-7 à travers la chaîne de middlewares PSR-15.
 *
 * Ordre : erreurs, en-têtes de sécurité, CORS, langue, résolution de route, authentification,
 * limitation de débit, établissement (droits + contexte), idempotence, contrôleur.
 */
final class Kernel
{
    private ?RouteRegistry $routes = null;

    private function __construct(
        private readonly string $basePath,
        private readonly Config $config,
        private readonly Container $container,
    ) {
    }

    /** @param array<string, mixed> $overrides définitions de conteneur qui remplacent les liaisons par défaut */
    public static function boot(string $basePath, ?Config $config = null, array $overrides = []): self
    {
        if ($config === null) {
            Dotenv::createImmutable($basePath)->safeLoad();
            $config = Config::load($basePath);
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        return new self($basePath, $config, ContainerFactory::create($basePath, $config, $overrides));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function routes(): RouteRegistry
    {
        if ($this->routes === null) {
            $registry = new RouteRegistry((string) $this->config->get('app.api_prefix', '/api/v1'));
            $files = array_merge(
                [$this->basePath . '/src/Core/routes.php'],
                glob($this->basePath . '/src/Modules/*/*/routes.php') ?: [],
            );
            foreach ($files as $file) {
                (require $file)($registry);
            }
            $this->routes = $registry;
        }

        return $this->routes;
    }

    public function handleGlobals(): ResponseInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        return $this->handle($creator->fromGlobals());
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $debug = $this->config->get('app.debug') === true;
        $c = $this->container;

        // En-têtes de sécurité et CORS À L'EXTÉRIEUR du gestionnaire d'erreurs : les réponses Problem Details les portent aussi.
        $middlewares = [
            new SecurityHeaders(),
            new Cors($this->stringList('api.cors_origins')),
            new ErrorHandler($c->get(JsonResponder::class), $c->get(LoggerInterface::class), $debug),
            new JsonBodyLimit((int) $this->config->get('api.max_json_body_bytes', 2_097_152)),
            new Locale(),
            new RouteResolver($this->dispatcher()),
            new Authenticate($c->get(Authenticator::class)),
            new RateLimit($c->get(RateLimiter::class), $this->rateRules()),
            new ResolveEstablishment($c->get(EstablishmentAccess::class), $c->get(TenantContext::class)),
            new Idempotency($c->get(IdempotencyStore::class), $this->stringList('api.idempotent_paths')),
        ];

        return (new Pipeline($middlewares, new ControllerInvoker($c)))->handle($request);
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        return array_values(array_filter((array) $this->config->get($key, []), 'is_string'));
    }

    /** @return list<array{string, string, int, int}> */
    private function rateRules(): array
    {
        $rules = [];
        foreach ((array) $this->config->get('api.rate_limits', []) as $rule) {
            $rule = array_values((array) $rule);
            $rules[] = [(string) ($rule[0] ?? ''), (string) ($rule[1] ?? ''), (int) ($rule[2] ?? 0), (int) ($rule[3] ?? 0)];
        }

        return $rules;
    }

    private function dispatcher(): Dispatcher
    {
        $routes = $this->routes();

        return simpleDispatcher(static function (RouteCollector $collector) use ($routes): void {
            $routes->register($collector);
        });
    }
}
