<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Application\TemplateService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;

/** Types de document : code, lettre, gabarit de numéro, QR, blocage pour impayé, variables autorisées. */
final class TypeController extends BaseController
{
    /** Relevés, PV et grilles déclarent une variable par ligne d'étudiant (DOC-10 de l'IPES : 139). */
    private const MAX_VARIABLES = 200;

    private const CATEGORIES = ['bulletin', 'certificate', 'other'];
    /** Rôle du signataire attendu pour ce type (S17), ex. directeur_ecole, directeur_general, president_jury, comptable. */
    public const SIGNER_ROLE = '/^[a-z][a-z0-9_]{1,39}$/';
    public const SIGNER_ROLE_MESSAGE = 'Rôle de signataire : minuscules, chiffres et _ (2 à 40 caractères).';

    public function __construct(HttpKit $kit, private readonly TypeRepository $types, private readonly TemplateService $service)
    {
        parent::__construct($kit);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::TEMPLATES);

        return $this->responder->json(['data' => array_map(Resources::type(...), $this->types->all($this->tenant(), $this->establishment()))]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::TEMPLATES);
        $row = $this->types->findById($this->tenant(), $this->establishment(), $this->id($request)) ?? throw HttpException::notFound('Type de document introuvable.');

        return $this->responder->json(['data' => Resources::type($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request), ['code', 'label', 'category', 'type_letter', 'number_template', 'qr_enabled', 'blockable', 'variables', 'signer_role']);
        $input = [
            'code' => $v->pattern('code', '/^[a-z][a-z0-9_]{1,29}$/', 'Code : minuscules, chiffres et _ (2 à 30 caractères).', true, 30),
            'label' => $v->string('label', 160, true),
            'category' => $v->enum('category', self::CATEGORIES, true),
            'type_letter' => $v->pattern('type_letter', '/^[A-Z]$/', 'Une lettre majuscule est attendue.', true, 1),
            'number_template' => $v->string('number_template', 80),
            'qr_enabled' => $v->bool('qr_enabled'),
            'blockable' => $v->bool('blockable'),
            'variables' => $v->stringList('variables', self::MAX_VARIABLES),
            'signer_role' => $v->pattern('signer_role', self::SIGNER_ROLE, self::SIGNER_ROLE_MESSAGE, false, 40),
        ];
        $v->done();

        $row = $this->service->createType($this->tenant(), $this->establishment(), $principal->userId, array_filter($input, static fn ($x): bool => $x !== null));

        return $this->responder->json(['data' => Resources::type($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request), ['label', 'category', 'number_template', 'qr_enabled', 'blockable', 'variables', 'signer_role']);
        $changes = array_filter([
            'label' => $v->string('label', 160),
            'category' => $v->enum('category', self::CATEGORIES),
            'number_template' => $v->string('number_template', 80),
            'qr_enabled' => $v->bool('qr_enabled'),
            'blockable' => $v->bool('blockable'),
            'variables' => $v->stringList('variables', self::MAX_VARIABLES),
            'signer_role' => $v->pattern('signer_role', self::SIGNER_ROLE, self::SIGNER_ROLE_MESSAGE, false, 40),
        ], static fn ($x): bool => $x !== null);
        $v->done();

        $row = $this->service->updateType($this->tenant(), $this->establishment(), $principal->userId, $this->id($request), $changes);

        return $this->responder->json(['data' => Resources::type($row)]);
    }
}
