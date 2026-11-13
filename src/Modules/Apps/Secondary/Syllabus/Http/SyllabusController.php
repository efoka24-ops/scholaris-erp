<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Syllabus\Application\CoverageService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Application\LessonLogService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Application\ProgressionService;
use Scholaris\Modules\Apps\Secondary\Syllabus\Application\SyllabusAccess;

/**
 * Cahier de textes du secondaire (T862, T863) : API-06 séance signée (hors ligne), progressions, couverture, visas, DOC-25, DOC-26,
 * rappels NOT-12. Lecture par le censeur, l'animateur, l'enseignant dans ses classes et l'inspecteur (lectures journalisées).
 */
final class SyllabusController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly SyllabusAccess $syllabus,
        private readonly ProgressionService $progressions,
        private readonly LessonLogService $logs,
        private readonly CoverageService $coverage,
        private readonly Listing $listing,
        private readonly ScopedByClass $scoped,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function createLog(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [$log, $created] = $this->logs->create($principal, $establishmentId, Input::body($request));

        return $this->json($log + ['replayed' => !$created], $created ? 201 : 200);
    }

    public function logs(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [$perimeter, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $this->syllabus->logInspection($principal, $inspector, 'se_lesson_log', $establishmentId);
        $map = static fn (array $row): array => LessonLogService::log($row);

        return $this->page($this->listing->page($request, LessonLogService::TABLE, $principal->tenantId, $establishmentId, ['academic_year_id', 'structure_id', 'se_subject_id', 'teacher_person_id', 'session_date'], ['session_date'], 'session_date DESC', $map, [], $this->scoped->sqlFilter($perimeter, 'structure_id')));
    }

    public function progressionList(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $this->syllabus->logInspection($principal, $inspector, 'se_progression', $establishmentId);
        $map = static fn (array $row): array => ProgressionService::progression($row);

        return $this->page($this->listing->page($request, ProgressionService::TABLE, $principal->tenantId, $establishmentId, ['academic_year_id', 'se_subject_id', 'level_code', 'status'], ['level_code', 'subject_label'], 'level_code, subject_label', $map));
    }

    public function createProgression(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->progressions->create($principal, $establishmentId, Input::body($request)), 201);
    }

    public function showProgression(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->progressions->show($principal, $establishmentId, self::id($request)));
    }

    public function replaceLessons(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->progressions->replaceLessons($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function approveProgression(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->progressions->approve($principal, $establishmentId, self::id($request)));
    }

    public function classCoverage(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->syllabus->readable($principal, $establishmentId);

        return $this->json($this->coverage->classCoverage($principal, $establishmentId, self::query($request, 'academic_year_id'), self::query($request, 'class_id'), self::day($request, 'year_start')));
    }

    public function report(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->syllabus->readable($principal, $establishmentId);

        return $this->json($this->coverage->report($principal, $establishmentId, self::query($request, 'academic_year_id'), self::day($request, 'year_start')));
    }

    public function logbook(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->syllabus->readable($principal, $establishmentId);

        return $this->json($this->coverage->logbook($principal, $establishmentId, self::query($request, 'academic_year_id'), self::query($request, 'class_id'), self::optional($request, 'subject_ref')));
    }

    public function visas(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [$perimeter, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $this->syllabus->logInspection($principal, $inspector, 'se_lesson_visa', $establishmentId);
        $map = static fn (array $row): array => CoverageService::visa($row);

        return $this->page($this->listing->page($request, CoverageService::VISAS, $principal->tenantId, $establishmentId, ['academic_year_id', 'structure_id', 'se_subject_id', 'visa_role'], ['visa_on'], 'visa_on DESC', $map, [], $this->scoped->sqlFilter($perimeter, 'structure_id')));
    }

    public function addVisa(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->coverage->addVisa($principal, $establishmentId, Input::body($request)), 201);
    }

    public function remind(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->coverage->remind($principal, $establishmentId, Input::body($request)));
    }

    private static function optional(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation([$name => ['Identifiant invalide.']]);
        }

        return $value;
    }

    private static function query(ServerRequestInterface $request, string $name): string
    {
        return self::optional($request, $name) ?? throw HttpException::validation([$name => ['Paramètre obligatoire.']]);
    }

    private static function day(ServerRequestInterface $request, string $name): ?string
    {
        $value = self::optional($request, $name);
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$name => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $value;
    }
}
