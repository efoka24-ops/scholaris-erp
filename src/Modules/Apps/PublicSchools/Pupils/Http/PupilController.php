<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilEnrolmentService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilProfileService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Inscription et fiche de l'élève (ELV, API-02) : doublons, identifiant national, acte de naissance, situations, liste DOC-07. */
final class PupilController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly PupilEnrolmentService $enrolment,
        private readonly PupilProfileService $profiles,
        private readonly AuditLog $audit,
    ) {
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $input = PupilInput::registration(ApiRequest::body($request));
        $this->access->register($principal, $establishmentId, $input['class_id']);
        try {
            $result = $this->enrolment->register(
                $principal->tenantId,
                $establishmentId,
                $input['draft'],
                $input['class_id'],
                $input['guardians'],
                $principal->userId,
                $input['confirm_different'],
                $input['override_reason'],
            );
        } catch (\InvalidArgumentException $refusal) {
            throw HttpException::validation(['registration' => [$refusal->getMessage()]]);
        }
        $pupil = $result['registration']->pupil;
        if (!$result['replayed']) {
            $this->audit->append(
                $principal->tenantId,
                $principal->userId,
                'public_schools.pupil.registered',
                'pupil',
                $pupil->personId,
                null,
                ['establishment_id' => $establishmentId, 'learner_number' => $pupil->learnerNumber, 'warnings' => $result['registration']->warnings],
                ApiRequest::ip($request),
                ApiRequest::device($request),
            );
        }

        return $this->responder->json(['data' => [
            'person_id' => $pupil->personId,
            'learner_number' => $pupil->learnerNumber,
            'last_name' => $pupil->lastName,
            'first_name' => $pupil->firstName,
            'birth_date' => $pupil->birthDate,
            'warnings' => $result['registration']->warnings,
            'replayed' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $personId = ApiRequest::attribute($request, 'id');
        $read = $this->access->read($principal, $establishmentId);
        $data = $this->profiles->show($principal->tenantId, $establishmentId, $personId, $read->wholeSchool);
        if ($data === null || !$this->access->covers($read, $data['structure_id'])) {
            throw HttpException::notFound('Élève introuvable.');
        }
        $this->access->named($principal, $read, 'pupil', $personId, self::purpose($request));

        return $this->responder->json(['data' => $data]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $personId = ApiRequest::attribute($request, 'id');
        $body = ApiRequest::body($request);
        $unknown = array_diff(array_keys($body), ['birth_act_ref', 'birth_act_verified', 'home_language', 'is_displaced', 'is_refugee', 'is_orphan', 'accommodation_type']);
        if ($unknown !== []) {
            throw HttpException::validation(array_fill_keys(array_map('strval', $unknown), ['Champ non autorisé.']));
        }
        $data = $this->profiles->update($principal->tenantId, $establishmentId, $personId, $body);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.pupil.profile_updated',
            'pupil',
            $personId,
            null,
            ['establishment_id' => $establishmentId, 'fields' => array_keys($body)],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return $this->responder->json(['data' => $data]);
    }

    /** DOC-07 : élèves scolarisés sans acte de naissance (RG-ELV-2). Nominatif : la supervision territoriale déclare sa finalité. */
    public function withoutBirthAct(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $read = $this->access->read($principal, $establishmentId);
        if ($read->isInSchool() && !$read->wholeSchool) {
            throw HttpException::notFound();
        }
        $this->access->named($principal, $read, 'birth_act_list', $establishmentId, self::purpose($request));

        return $this->responder->json(['data' => $this->profiles->withoutBirthAct($principal->tenantId, $establishmentId)]);
    }

    public function transmit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $quarter = ApiRequest::body($request)['quarter'] ?? $this->profiles->currentQuarter();
        if (!is_string($quarter)) {
            throw HttpException::validation(['quarter' => ['Trimestre attendu au format AAAA-Tn.']]);
        }
        $record = $this->profiles->transmitList($principal->tenantId, $establishmentId, $quarter, $principal->userId);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            'public_schools.birth_act_list.transmitted',
            'birth_act_list',
            $establishmentId . ':' . $quarter,
            null,
            $record,
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );

        return $this->responder->json(['data' => $record], 201);
    }

    public function transmissions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $read = $this->access->read($principal, $establishmentId);
        if ($read->isInSchool() && !$read->wholeSchool) {
            throw HttpException::notFound();
        }

        return $this->responder->json(['data' => $this->profiles->transmissions($principal->tenantId, $establishmentId)]);
    }

    private static function purpose(ServerRequestInterface $request): ?string
    {
        $purpose = $request->getQueryParams()['purpose'] ?? null;

        return is_string($purpose) ? $purpose : null;
    }
}
