<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Application\StudentDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Documents du secondaire (T833) : catalogue des natures avec leur disponibilité selon les modules actifs, émission par le socle
 * Documents (numéro, QR, contenu figé, FR/EN), documents valides d'un élève.
 */
final class DocumentsController extends Controller
{
    public const READ = ['secondary.documents.issue', 'secondary.enrol.manage', 'secondary.enrol.read', 'secondary.billing.manage', 'secondary.settings.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly DocumentTypeCatalogue $catalogue,
        private readonly StudentDocuments $documents,
        private readonly EstablishmentProfiles $profiles,
        private readonly EnrollmentDirectory $enrollments,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function kinds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::READ);
        $profile = $this->profiles->forEstablishment($principal->tenantId, $establishmentId);
        $items = [];
        foreach ($this->catalogue->all() as $type) {
            $items[] = [
                'doc' => $type->doc, 'kind' => $type->kind, 'type' => $type->socleCode, 'label_fr' => $type->labelFr, 'label_en' => $type->labelEn,
                'qr' => $type->qr, 'source' => $type->source, 'module' => $type->module,
                'available' => $type->module === null || $profile->isActive($type->module),
                'direct' => $type->direct && !$type->issuedBySocle(),
                'can_issue' => !$this->access->perimeter($principal, $establishmentId, $type->permissions)->isEmpty(),
            ];
        }

        return $this->json(['items' => $items]);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $kind = (string) $input->enum('kind', array_keys($this->catalogue->all()));
        $input->reference('enrollment_id', false);
        $input->reference('class_id', false);
        $input->reference('invoice_id', false);
        $input->string('destination', 160, false);
        $input->string('reason', 255, false);
        $input->date('effective_on', false);
        $input->enum('locale', ['fr', 'en'], false);
        $v = $input->done();

        return $this->json($this->documents->issue($principal, $establishmentId, $kind, $v), 201);
    }

    /** Élèves inscrits (en cours ou clos) d'une classe du périmètre, pour choisir l'inscription d'un document. */
    public function candidates(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $classId = $request->getQueryParams()['class_id'] ?? null;
        if (!is_string($classId) || $classId === '') {
            throw HttpException::validation(['class_id' => ['Paramètre obligatoire.']]);
        }
        $this->access->assertReadableClass($perimeter, $classId);

        return $this->json(['items' => $this->documents->candidates($principal->tenantId, $establishmentId, $classId)]);
    }

    public function issued(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $personId = $request->getQueryParams()['person_id'] ?? null;
        if (!is_string($personId) || $personId === '') {
            throw HttpException::validation(['person_id' => ['Paramètre obligatoire.']]);
        }
        $visible = false;
        foreach ($this->enrollments->forPerson($principal->tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && ($perimeter->wholeEstablishment || $perimeter->coversClass($enrollment->structureId))) {
                $visible = true;
            }
        }
        if (!$visible) {
            throw HttpException::notFound('Élève introuvable dans cet établissement.');
        }

        return $this->json(['items' => $this->documents->issuedFor($principal->tenantId, $personId)]);
    }
}
