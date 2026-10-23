<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\NurseryApplication;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;

/**
 * Valeurs effectives des paramètres `nursery.*` de l'école, en lecture pour tout le personnel. L'écriture passe par la route
 * `/settings` du socle (droit `settings.manage`, journal d'audit) : cette application n'écrit aucun paramètre.
 */
final class SettingsController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly NurserySettings $settings)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, NurseryApplication::permissions());
        return $this->json($this->settings->all($principal->tenantId, $establishmentId));
    }
}
