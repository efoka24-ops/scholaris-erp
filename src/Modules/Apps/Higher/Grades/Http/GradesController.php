<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Apps\Higher\Grades\Application\AssessmentService;
use Scholaris\Modules\Apps\Higher\Grades\Application\CorrectionService;
use Scholaris\Modules\Apps\Higher\Grades\Application\GradesSettings;
use Scholaris\Modules\Apps\Higher\Grades\Application\LifecycleService;
use Scholaris\Modules\Apps\Higher\Grades\Application\MarkPolicy;
use Scholaris\Modules\Apps\Higher\Grades\Application\MarkService;
use Scholaris\Modules\Apps\Higher\Grades\Application\QueryService;
use Scholaris\Modules\Apps\Higher\Grades\Application\StatisticsService;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;

/**
 * Notes (NOT) côté enseignant et encadrement : évaluations par EC, grille de saisie (API-09), synchronisation hors ligne, import
 * CSV, cycle (API-10, API-11, publication), anonymat, corrections (API-12), requêtes, suivi de la saisie, statistiques par EC.
 * La porte du droit d'usage (WriteGate) est passée avant chaque écriture (HigherRoutes) ; MarkPolicy contrôle le reste.
 */
final class GradesController
{
    private const FILTERS = ['curriculum_id', 'element_id', 'group_id', 'level_id', 'program_id', 'status', 'academic_year_id', 'kind', 'exam_session'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly MarkPolicy $policy,
        private readonly GradesSettings $settings,
        private readonly AssessmentService $assessments,
        private readonly MarkService $marks,
        private readonly LifecycleService $lifecycle,
        private readonly CorrectionService $corrections,
        private readonly QueryService $queries,
        private readonly StatisticsService $statistics,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** GET /higher/grades/settings : configuration IPES effective du moteur (école choisie). */
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        if ($this->policy->perimeter($principal, $establishmentId, MarkPolicy::READ)->isEmpty() && !$this->policy->can($principal, $establishmentId, MarkPolicy::ENTER)) {
            throw HttpException::forbidden('Permission requise : higher.grades.read ou assessment.enter.');
        }

        return $this->json($this->settings->effective($principal->tenantId, $establishmentId, Access::selectedSchool($request) === 'all' ? null : Access::selectedSchool($request)));
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $rows = $this->assessments->visible($principal, $establishmentId, self::filters($request), Access::selectedSchool($request));

        return $this->responder->json(['data' => array_map(AssessmentService::resource(...), $rows), 'meta' => ['count' => count($rows)]]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $row = $this->assessments->find($principal->tenantId, $establishmentId, self::id($request));
        $this->policy->assertReadable($principal, $establishmentId, $row);

        return $this->json(AssessmentService::resource($row));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $input->reference('curriculum_id');
        $input->reference('element_id');
        $input->reference('group_id', false, true);
        $input->enum('kind', AssessmentService::KINDS);
        $this->fields($input);
        $input->bool('anonymous', false);

        return $this->json(AssessmentService::resource($this->assessments->create($principal, $establishmentId, $input->done())), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request, true);
        $this->fields($input);

        return $this->json(AssessmentService::resource($this->assessments->update($principal, $establishmentId, self::id($request), $input->done())));
    }

    private function fields(Input $input): void
    {
        $input->string('label', 120, false);
        $input->date('held_on', false, true);
        $input->date('entry_due_on', false, true);
        $input->number('max_score', 1, 100, 0.25, false);
        $input->int('weight_percent', 0, 100, false);
    }

    /** GET /higher/grade-assessments/{id}/marks : grille de saisie. */
    public function grid(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json($this->marks->grid($principal, $establishmentId, self::id($request)));
    }

    /** PUT /higher/grade-assessments/{id}/marks (API-09) : lot atomique `{marks: [{person_id|anonymous_code, score|absence, expected_version}]}`. */
    public function enter(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $body = Input::body($request);
        $entries = $body['marks'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > 1000 || array_diff(array_keys($body), ['marks']) !== []) {
            throw HttpException::validation(['marks' => ['Liste de 1 à 1000 notes attendue (champ « marks » seul).']]);
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw HttpException::validation(['marks' => ['Chaque note est un objet.']]);
            }
        }

        /** @var list<array<string, mixed>> $entries */
        return $this->json($this->marks->enter($principal, $establishmentId, self::id($request), $entries));
    }

    /** POST /higher/grade-assessments/{id}/marks/import : `{content: "<csv>"}` au format du modèle. */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $content = $input->custom('content', static function (mixed $v): string {
            if (!is_string($v) || $v === '' || strlen($v) > 1_000_000) {
                throw new \InvalidArgumentException('Contenu CSV (1 Mo au plus) attendu.');
            }

            return $v;
        });
        $input->done();

        return $this->json($this->marks->import($principal, $establishmentId, self::id($request), (string) $content));
    }

    public function template(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $csv = $this->marks->template($principal, $establishmentId, self::id($request));

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="notes_' . self::id($request) . '.csv"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($csv));
    }

    /** POST /higher/grades/sync : lots hors ligne `{entries: [{client_uuid, assessment_id, person_id|anonymous_code, base_version, score|absence}]}`. */
    public function sync(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $body = Input::body($request);
        $entries = $body['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > 500) {
            throw HttpException::validation(['entries' => ['Liste de 1 à 500 saisies attendue.']]);
        }
        $clean = array_values(array_filter($entries, 'is_array'));

        /** @var list<array<string, mixed>> $clean */
        return $this->responder->json(['data' => $this->marks->sync($principal, $establishmentId, $clean)]);
    }

    public function transition(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $to = $input->string('to', 30);
        $version = $input->int('expected_version', 1, PHP_INT_MAX, false);
        $input->done();

        return $this->move($request, (string) $to, $version);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->move($request, IpesDefaults::SUBMITTED);
    }

    public function sendBack(ServerRequestInterface $request): ResponseInterface
    {
        return $this->move($request, IpesDefaults::DRAFT);
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->move($request, IpesDefaults::VALIDATED);
    }

    public function lock(ServerRequestInterface $request): ResponseInterface
    {
        return $this->move($request, IpesDefaults::LOCKED);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        return $this->move($request, IpesDefaults::PUBLISHED);
    }

    private function move(ServerRequestInterface $request, string $to, ?int $version = null): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json(AssessmentService::resource($this->lifecycle->transition($principal, $establishmentId, self::id($request), $to, $version)));
    }

    public function liftAnonymity(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json(AssessmentService::resource($this->lifecycle->liftAnonymity($principal, $establishmentId, self::id($request))));
    }

    public function assessmentStatistics(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json($this->statistics->forAssessment($principal, $establishmentId, self::id($request)));
    }

    public function elementStatistics(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $group = $request->getQueryParams()['group_id'] ?? null;

        return $this->json($this->statistics->forElement($principal, $establishmentId, self::id($request, 'elementId'), is_string($group) && $group !== '' ? $group : null));
    }

    public function progress(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json($this->statistics->progress($principal, $establishmentId, self::filters($request), Access::selectedSchool($request)));
    }

    public function events(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->responder->json(['data' => $this->marks->events($principal, $establishmentId, self::id($request))]);
    }

    /** POST /higher/grade-marks/{id}/changes (API-12) : demande de correction motivée avec pièce. */
    public function requestCorrection(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $input->number('score', 0, 100, 0.01, false);
        $input->string('absence', 12, false);
        $input->string('reason', 500);
        $input->string('attachment_ref', 255);
        $input->reference('query_id', false, true);

        return $this->json($this->corrections->request($principal, $establishmentId, self::id($request), $input->done()), 201);
    }

    public function corrections(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->responder->json(['data' => $this->corrections->visible($principal, $establishmentId, self::filters($request, ['status', 'assessment_id', 'mark_id']))]);
    }

    public function correction(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json($this->corrections->find($principal, $establishmentId, self::id($request)));
    }

    public function approveCorrection(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->json($this->corrections->approve($principal, $establishmentId, self::id($request)));
    }

    public function rejectCorrection(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $reason = $input->string('reason', 500);
        $input->done();

        return $this->json($this->corrections->reject($principal, $establishmentId, self::id($request), (string) $reason));
    }

    public function queries(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->responder->json(['data' => $this->queries->visible($principal, $establishmentId, self::filters($request, ['status', 'assessment_id']), false)]);
    }

    public function respondQuery(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $input = Input::of($request);
        $decision = $input->enum('decision', ['accepted', 'rejected']);
        $response = $input->string('response', 1000);
        $input->done();

        return $this->json($this->queries->respond($principal, $establishmentId, self::id($request), (string) $decision, (string) $response));
    }

    /** @return array{0: Principal, 1: string} */
    public static function context(ServerRequestInterface $request): array
    {
        return [Access::principal($request), Access::establishmentId($request)];
    }

    public static function id(ServerRequestInterface $request, string $name = 'id'): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Filtres `filter[champ]=valeur` en liste blanche.
     *
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private static function filters(ServerRequestInterface $request, array $allowed = self::FILTERS): array
    {
        $raw = $request->getQueryParams()['filter'] ?? [];
        $filters = [];
        foreach (is_array($raw) ? $raw : [] as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw HttpException::validation(['filter' => ['Filtre non autorisé : ' . $field . '.']]);
            }
            if (is_string($value) && $value !== '') {
                $filters[(string) $field] = $value;
            }
        }

        return $filters;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => $data], $status);
    }
}
