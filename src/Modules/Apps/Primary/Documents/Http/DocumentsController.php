<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Documents\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Documents\Application\AdministrativeDocuments;
use Scholaris\Modules\Apps\Primary\Documents\Domain\DocumentKinds;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Émission des documents administratifs et du transfert sortant (EX-PRI-15, 40, 41). */
final class DocumentsController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly AdministrativeDocuments $documents)
    {
        parent::__construct($responder, $access, $store);
    }

    public function kinds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, AdministrativeDocuments::PERMISSIONS);

        return $this->json(['items' => array_map(static fn (string $kind): array => ['kind' => $kind, 'type' => DocumentKinds::SOCLE_TYPES[$kind]], array_keys(DocumentKinds::SOCLE_TYPES))]);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->enum('kind', DocumentKinds::ENROLMENT_KINDS);
        $input->reference('enrollment_id');
        $input->enum('locale', ['fr', 'en'], false);
        $v = $input->done();

        return $this->json($this->documents->issue($principal, $establishmentId, (string) $v['kind'], (string) $v['enrollment_id'], isset($v['locale']) ? (string) $v['locale'] : null), 201);
    }

    public function transfer(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('destination');
        $input->string('reason', 255);
        $input->date('effective_on');
        $input->enum('locale', ['fr', 'en'], false);
        $v = $input->done();

        return $this->json($this->documents->transfer($principal, $establishmentId, self::id($request), (string) $v['destination'], (string) $v['reason'], (string) $v['effective_on'], isset($v['locale']) ? (string) $v['locale'] : null), 201);
    }
}
