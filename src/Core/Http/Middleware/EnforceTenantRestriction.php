<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\TenantRestriction;
use Scholaris\Core\Security\Principal;

/**
 * Blocage central d'un client en régularisation de paiement, pour TOUTES les routes authentifiées (socle et applications),
 * quel que soit le client HTTP : le navigateur n'est qu'un confort.
 *
 *  - niveau `locked` : toute requête est refusée (423 `regularisation-required`) sauf la liste blanche ci-dessous ;
 *  - niveau `read_only` : les requêtes qui modifient (hors GET, HEAD, OPTIONS) sont refusées (423 `subscription-suspended`),
 *    sauf la liste blanche ; lecture et exports restent permis.
 *
 * Liste blanche (chemins relatifs au préfixe de l'API) : authentification et déconnexion `/auth/**`, `/me`, `/me/context(s)`,
 * `/me/regularisation/**`, paiement et suivi de régularisation (`/applications/{clé}/payments`, `/application-payments/{id}/receipt`),
 * `/public/**`, `/health`, `/platform/**` (administrateur de plateforme, jamais bloqué) et les webhooks de paiement.
 * Les routes publiques n'ont pas de personne authentifiée : elles ne sont jamais concernées. Un compte du client technique
 * `platform` n'est jamais restreint. La réponse ne contient aucune donnée du client.
 */
final class EnforceTenantRestriction implements MiddlewareInterface
{
    public const PLATFORM_TENANT = 'platform';
    public const TYPE_REQUIRED = 'https://api.scholaris.cm/errors/regularisation-required';
    public const TYPE_SUSPENDED = 'https://api.scholaris.cm/errors/subscription-suspended';
    /** Écran de régularisation du front, donné dans la réponse. */
    public const REGULARISATION_URL = '/regularisation';

    /** @var list<string> expressions sur le chemin relatif au préfixe de l'API */
    public const ALLOWED = [
        '#^/auth/#',
        '#^/me$#',
        '#^/me/contexts?$#',
        '#^/me/regularisation(/|$)#',
        '#^/applications/[^/]+/payments$#',
        '#^/application-payments/[^/]+/receipt$#',
        '#^/public/#',
        '#^/health$#',
        '#^/platform/#',
        '#^/webhooks?/#',
    ];

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const MESSAGES = [
        'fr' => [
            'locked_title' => 'Régularisation de paiement requise',
            'locked_detail' => 'L\'accès est suspendu pour impayé. Régularisez l\'abonnement depuis l\'écran de régularisation ; vos données sont conservées.',
            'read_only_title' => 'Abonnement suspendu',
            'read_only_detail' => 'Abonnement suspendu : lecture et export seulement. Régularisez l\'abonnement pour rétablir les modifications.',
        ],
        'en' => [
            'locked_title' => 'Payment regularisation required',
            'locked_detail' => 'Access is suspended for unpaid subscription. Regularise it from the regularisation screen; your data is kept safe.',
            'read_only_title' => 'Subscription suspended',
            'read_only_detail' => 'Subscription suspended: read and export only. Regularise the subscription to restore changes.',
        ],
    ];

    public function __construct(
        private readonly TenantRestriction $restriction,
        private readonly string $apiPrefix = '/api/v1',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal || $principal->tenantId === self::PLATFORM_TENANT || $this->allowed($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $level = $this->restriction->levelFor($principal->tenantId);
        if ($level === TenantRestriction::LOCKED) {
            throw $this->refusal('locked', self::TYPE_REQUIRED, $request);
        }
        if ($level === TenantRestriction::READ_ONLY && !in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            throw $this->refusal('read_only', self::TYPE_SUSPENDED, $request);
        }

        return $handler->handle($request);
    }

    public function allowed(string $path): bool
    {
        $relative = str_starts_with($path, $this->apiPrefix) ? substr($path, strlen($this->apiPrefix)) : $path;
        $relative = rawurldecode($relative);
        foreach (self::ALLOWED as $pattern) {
            if (preg_match($pattern, $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    private function refusal(string $level, string $type, ServerRequestInterface $request): HttpException
    {
        $messages = self::MESSAGES[(string) $request->getAttribute('locale', 'fr')] ?? self::MESSAGES['fr'];

        return new HttpException(
            423,
            $messages[$level . '_title'],
            $messages[$level . '_detail'],
            $type,
            extensions: ['level' => $level, 'regularisation_url' => self::REGULARISATION_URL],
        );
    }
}
