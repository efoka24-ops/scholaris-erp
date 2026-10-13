<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsAccess;
use Scholaris\Modules\Apps\Higher\Admissions\Application\ApplicationService;
use Scholaris\Modules\Apps\Higher\Admissions\Application\ContestService;
use Scholaris\Modules\Apps\Higher\Admissions\Application\DecisionService;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;

/** Candidatures (EX-ADM-02 à 07) : dépôt, pièces, frais de dossier, note, décision motivée, retrait, équivalences de transfert. */
final class ApplicationController
{
    private const FILTERS = ['campaign_id', 'school_id', 'program_id', 'level_id', 'academic_year_id', 'status', 'channel', 'person_id', 'list_kind'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ApplicationService $applications,
        private readonly DecisionService $decisions,
        private readonly ContestService $contests,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$query, $offset] = AdmissionsResources::listQuery($request, self::FILTERS);
        $rows = $this->applications->index(
            AdmissionsAccess::principal($request),
            AdmissionsAccess::establishmentId($request),
            $query->filters,
            $query->perPage + 1,
            $offset,
            AdmissionsAccess::selectedSchool($request),
        );

        return $this->responder->json(AdmissionsResources::page($rows, $query->perPage, $offset, AdmissionsResources::application(...)));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $identity = $body->object('identity', false);
        $input = [
            'campaign_id' => (string) $body->string('campaign_id', 40),
            'person_id' => $body->string('person_id', 40, false),
            'identity' => null,
            'application_kind' => (string) $body->choice('application_kind', ['new', 'transfer'], false, 'new'),
            'origin_institution' => $body->string('origin_institution', 160, false),
            'diploma' => (string) $body->choice('diploma', Prerequisites::DIPLOMAS),
            'diploma_series' => $body->string('diploma_series', 20, false),
            'diploma_year' => $body->int('diploma_year', 1950, 2100, false),
            'diploma_mention' => $body->choice('diploma_mention', Prerequisites::MENTIONS, false),
        ];
        if ($identity !== null) {
            $sub = new Body($identity);
            $input['identity'] = [
                'first_name' => (string) $sub->string('first_name', 80),
                'last_name' => (string) $sub->string('last_name', 80),
                'phone' => $sub->string('phone', 30, false),
                'email' => $sub->string('email', 160, false),
                'locale' => (string) $sub->choice('locale', ['fr', 'en'], false, 'fr'),
            ];
            if ($input['identity']['phone'] === null && $input['identity']['email'] === null) {
                $sub->error('phone', 'Téléphone ou e-mail obligatoire (notifications du candidat).');
            }
            try {
                $sub->done();
            } catch (\Scholaris\Core\Http\HttpException $e) {
                foreach ($e->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $body->error('identity.' . $field, $message);
                    }
                }
            }
        }
        $body->done();

        return $this->json($this->applications->create(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), $input), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->applications->readable(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request)));
    }

    public function attachDocument(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $code = (string) $body->string('requirement_code', 40);
        $reference = (string) $body->string('document_ref', 80);
        $body->done();

        return $this->json($this->applications->attachDocument(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $code, $reference));
    }

    public function verifyDocument(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->applications->verifyDocument(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), self::id($request, 'documentId')));
    }

    public function rejectDocument(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->json($this->applications->rejectDocument(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), self::id($request, 'documentId'), $reason));
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->applications->submit(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request)));
    }

    public function payFee(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $channel = $body->choice('channel', ApplicationService::CHANNELS, false);
        $phone = $body->string('payer_phone', 30, false);
        $reference = $body->string('external_reference', 80, false);
        $body->done();

        return $this->json($this->applications->payFee(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $channel, $phone, $reference));
    }

    public function score(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $score = (int) $body->int('score_hundredths', 0, 2000);
        $body->done();

        return $this->json($this->applications->score(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $score));
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $decision = (string) $body->choice('decision', ApplicationStatus::DECISIONS);
        $reason = (string) $body->string('reason', 500);
        $over = $body->string('over_capacity_reason', 500, false);
        $body->done();

        return $this->json($this->decisions->decide(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $decision, $reason, $over));
    }

    public function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->json($this->applications->withdraw(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $reason));
    }

    public function equivalences(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $commission = (string) $body->string('commission_reference', 80);
        $items = [];
        foreach ($body->list('items', true, 60) ?? [] as $i => $item) {
            $sub = new Body(is_array($item) && !array_is_list($item) ? $item : []);
            $entry = [
                'origin_unit_code' => (string) $sub->string('origin_unit_code', 40),
                'origin_unit_title' => (string) $sub->string('origin_unit_title', 160),
                'origin_credits' => (int) $sub->int('origin_credits', 0, 60),
                'origin_grade_hundredths' => $sub->int('origin_grade_hundredths', 0, 2000, false),
                'unit_code' => (string) $sub->string('unit_code', 40),
                'granted' => $sub->bool('granted'),
            ];
            try {
                $sub->done();
                $items[] = $entry;
            } catch (\Scholaris\Core\Http\HttpException) {
                $body->error('items.' . $i, 'Équivalence invalide : origin_unit_code, origin_unit_title, origin_credits, unit_code et granted attendus.');
            }
        }
        $body->done();

        return $this->json($this->contests->equivalences(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $commission, $items));
    }

    private static function id(ServerRequestInterface $request, string $name = 'id'): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $application */
    private function json(array $application, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => AdmissionsResources::application($application)], $status);
    }
}
