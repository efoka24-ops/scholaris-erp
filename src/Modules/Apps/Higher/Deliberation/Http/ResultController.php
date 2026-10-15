<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Http\Controller;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\DeliberationPermissions as P;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\TranscriptService;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\YearDecisionService;

/** Décisions de fin d'année (EX-DEL-07), relevés DOC-09 (aperçu et émission), résultats publiés de l'étudiant (API-16). */
final class ResultController extends Controller
{
    private const SCOPE = ['school' => 'school_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'structure_id'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly YearDecisionService $years,
        private readonly TranscriptService $transcripts,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function yearIndex(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            'hi_year_decisions',
            [P::READ],
            self::SCOPE,
            ['structure_id', 'academic_year_id', 'program_id', 'decision_code', 'person_id'],
            ['decided_at', 'average'],
            'decided_at DESC',
            static fn (array $row): array => [
                'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'program_id' => (string) $row['program_id'], 'level_id' => (string) $row['level_id'],
                'structure_id' => (string) $row['structure_id'], 'academic_year_id' => (string) $row['academic_year_id'],
                'average' => $row['average'] === null ? null : (float) $row['average'], 'credits_earned' => (int) $row['credits_earned'],
                'credits_total' => (int) $row['credits_total'], 'cumulative_credits' => (int) $row['cumulative_credits'], 'decision_code' => (string) $row['decision_code'],
                'decision_label' => (string) $row['decision_label'], 'mention' => $row['mention'], 'diploma_file' => (int) $row['diploma_file'] === 1,
                'derogation' => (int) $row['derogation'] === 1, 'acquired_units' => json_decode((string) $row['acquired_units'], true),
                'missing_units' => json_decode((string) $row['missing_units'], true), 'decided_at' => (string) $row['decided_at'],
            ],
        );
    }

    public function yearPreview(ServerRequestInterface $request): ResponseInterface
    {
        return $this->year($request, false);
    }

    public function yearRecord(ServerRequestInterface $request): ResponseInterface
    {
        return $this->year($request, true);
    }

    public function transcript(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();
        $person = is_string($query['person_id'] ?? null) ? $query['person_id'] : throw HttpException::validation(['person_id' => ['Obligatoire.']]);
        $period = is_string($query['period_id'] ?? null) ? $query['period_id'] : throw HttpException::validation(['period_id' => ['Obligatoire.']]);

        return $this->json($this->transcripts->semester($principal, $establishmentId, $person, $period, ($query['locale'] ?? 'fr') === 'en' ? 'en' : 'fr'));
    }

    public function issueTranscript(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('period_id');
        $input->enum('locale', ['fr', 'en'], false);
        $values = $input->done();

        return $this->json($this->transcripts->issueSemester($principal, $establishmentId, (string) $values['person_id'], (string) $values['period_id'], isset($values['locale']) ? (string) $values['locale'] : null), 201);
    }

    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $locale = ($request->getQueryParams()['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $this->responder->json(['data' => $this->transcripts->mine($principal, $establishmentId, $locale)]);
    }

    private function year(ServerRequestInterface $request, bool $record): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('structure_id');
        $input->reference('academic_year_id');
        $periodIds = $input->references('period_ids', 4) ?? [];
        $derogations = $input->references('derogations', 200, false) ?? [];
        $values = $input->done();

        return $this->responder->json(['data' => $this->years->decide(
            $principal,
            $establishmentId,
            (string) $values['structure_id'],
            (string) $values['academic_year_id'],
            $periodIds,
            $derogations,
            $record,
        )], $record ? 201 : 200);
    }
}
