<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Application\SealService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;

/** Signature électronique simple et cachet de l'établissement (les images ne sont jamais renvoyées par l'API). */
final class SealController extends BaseController
{
    public function __construct(HttpKit $kit, private readonly SealService $service)
    {
        parent::__construct($kit);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::TEMPLATES);

        $query = $request->getQueryParams();
        $role = self::scoped($query['signer_role'] ?? null, TypeController::SIGNER_ROLE, 'signer_role');
        $unit = self::scoped($query['org_unit_id'] ?? null, '/^[A-Za-z0-9_-]{1,40}$/', 'org_unit_id');
        $row = $this->service->get($this->tenant(), $this->establishment(), $role, $unit);

        return $this->responder->json(['data' => $role === null ? Resources::seal($row) : Resources::signer($row, $role, $unit)]);
    }

    private static function scoped(mixed $value, string $regex, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match($regex, $value) !== 1) {
            throw HttpException::validation([$key => ['Valeur invalide.']]);
        }

        return $value;
    }

    public function put(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request), ['signer_name', 'signer_title', 'signature_image', 'stamp_image', 'signer_role', 'org_unit_id']);
        $input = [
            'signer_name' => $v->string('signer_name', 160, true),
            'signer_title' => $v->string('signer_title', 160),
            'signature_image' => $v->string('signature_image', 300000),
            'stamp_image' => $v->string('stamp_image', 300000),
        ];
        $role = $v->pattern('signer_role', TypeController::SIGNER_ROLE, TypeController::SIGNER_ROLE_MESSAGE, false, 40);
        $unit = $v->pattern('org_unit_id', '/^[A-Za-z0-9_-]{1,40}$/', 'Identifiant invalide.', false, 40);
        if ($unit !== null && $role === null) {
            $v->fail('signer_role', 'Un signataire d\'école se déclare pour un rôle (signer_role).');
        }
        $v->done();
        $saved = $this->service->put($this->tenant(), $this->establishment(), $principal->userId, $input, $role, $unit);
        if ($role !== null) {
            return $this->responder->json(['data' => Resources::signer($saved, $role, $unit)]);
        }

        return $this->responder->json(['data' => Resources::seal($saved)]);
    }
}
