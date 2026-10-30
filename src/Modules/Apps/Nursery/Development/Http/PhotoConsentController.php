<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\PhotoConsentService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;

/**
 * Consentement photo : l'école enregistre le formulaire signé (secrétaire, directrice) et peut le révoquer à la demande du parent ;
 * le parent le donne ou le retire lui-même depuis son portail, pour SES enfants seulement. Historique toujours rendu.
 */
final class PhotoConsentController extends Controller
{
    private const MANAGE = ['nursery.consent.manage'];
    private const READ = ['nursery.consent.manage', 'nursery.lifebook.write', 'nursery.lifebook.read'];

    public function __construct(JsonResponder $responder, Access $access, private readonly PhotoConsentService $consents, private readonly ChildDirectory $children)
    {
        parent::__construct($responder, $access);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->children->assertInPerimeter($perimeter, self::id($request));

        return $this->json($this->consents->summary($principal->tenantId, $establishmentId, self::id($request)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $section = $this->children->assertInPerimeter($perimeter, self::id($request));
        $this->access->assertWritable($principal, $perimeter, $section, 'child', self::id($request));
        $input = Input::of($request);
        $input->string('evidence_ref', 120);
        $input->date('signed_on');
        $input->string('signed_by_name', 160);
        $data = $input->done();

        return $this->json($this->consents->grant($principal, $establishmentId, self::id($request), [
            'channel' => 'paper', 'evidence_ref' => (string) $data['evidence_ref'], 'signed_on' => (string) $data['signed_on'], 'signed_by_name' => (string) $data['signed_by_name'],
        ]), 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $section = $this->children->assertInPerimeter($perimeter, self::id($request));
        $this->access->assertWritable($principal, $perimeter, $section, 'child', self::id($request));
        $input = Input::of($request);
        $input->string('reason', 255);

        return $this->json($this->consents->revoke($principal, $establishmentId, self::id($request), (string) $input->done()['reason']));
    }

    public function portalShow(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $learnerId = self::learner($request->getQueryParams()['learner_id'] ?? null);
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->json($this->consents->summary($principal->tenantId, $establishmentId, $learnerId));
    }

    public function portalGrant(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->string('signed_by_name', 160);
        $input->custom('accept', static function (mixed $value): bool {
            if ($value !== true) {
                throw new \InvalidArgumentException('Cochez la case d’accord pour donner le consentement.');
            }

            return true;
        });
        $data = $input->done();
        $learnerId = (string) $data['learner_id'];
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->json($this->consents->grant($principal, $establishmentId, $learnerId, ['channel' => 'portal', 'signed_by_name' => (string) $data['signed_by_name']]), 201);
    }

    public function portalRevoke(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->string('reason', 255, false);
        $data = $input->done();
        $learnerId = (string) $data['learner_id'];
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->json($this->consents->revoke($principal, $establishmentId, $learnerId, isset($data['reason']) ? (string) $data['reason'] : 'Retrait par le parent.'));
    }

    private static function learner(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }

        return $value;
    }
}
