<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\AttendanceHours;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\ClassExclusions;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\ClassRoster;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\ConductProposals;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\DisciplineScale;
use Scholaris\Modules\Apps\Secondary\Discipline\Domain\DisciplineDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Vie scolaire du secondaire (lot E2-B, noyau) : barème en vigueur, cumul des heures d'absence d'une classe sur une période (appel
 * du socle, D1), propositions du barème et leur décision (RG-PRE-1, RG-PRE-2), exclusions de cours (T867). Lecture des heures :
 * droits d'appel ou de discipline sur la classe ; propositions : surveillant général (`secondary.discipline.manage`) qui
 * lance le calcul, décision selon le niveau du palier (DisciplineDefaults::LEVEL_PERMISSIONS).
 */
final class DisciplineController extends Controller
{
    public const HOURS_READ = ['secondary.attendance.read', 'secondary.attendance.take', 'secondary.attendance.manage', 'secondary.discipline.manage', 'secondary.discipline.validate'];
    public const DISCIPLINE_READ = ['secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.discipline.council.preside', 'secondary.attendance.manage'];
    public const COMPUTE = ['secondary.discipline.manage'];
    public const EXCLUSION_WRITE = ['secondary.attendance.take', 'secondary.attendance.manage', 'secondary.discipline.manage'];
    public const EXCLUSION_READ = [...self::EXCLUSION_WRITE, 'secondary.attendance.read', 'secondary.discipline.validate'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly DisciplineScale $scale,
        private readonly AttendanceHours $hours,
        private readonly ClassRoster $roster,
        private readonly ConductProposals $proposals,
        private readonly ClassExclusions $exclusions,
        private readonly Listing $listing,
        private readonly ScopedByClass $scoped,
    ) {
        parent::__construct($responder, $access, $store);
    }

    /** Barème de l'établissement (règle `discipline_scale`), son état et l'exemple du CDC « à confirmer » (jamais appliqué d'office). */
    public function scale(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, [...self::DISCIPLINE_READ, ...self::HOURS_READ]);
        $current = $this->scale->current($principal->tenantId, $establishmentId);

        return $this->json($current + [
            'rule_key' => DisciplineScale::RULE_KEY,
            'hours_per_session' => DisciplineDefaults::HOURS_PER_SESSION,
            'example' => $this->scale->example(),
            'example_status' => 'to_confirm',
        ]);
    }

    /** Heures d'absence (justifiées, non justifiées) et retards des élèves d'une classe ; `class_id` obligatoire, `from`/`to` facultatifs. */
    public function hours(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::HOURS_READ);
        $query = $request->getQueryParams();
        $classId = is_string($query['class_id'] ?? null) && $query['class_id'] !== '' ? $query['class_id'] : null;
        if ($classId === null) {
            throw HttpException::validation(['class_id' => ['Ce paramètre est obligatoire.']]);
        }
        [$from, $to] = [self::queryDate($query, 'from'), self::queryDate($query, 'to')];
        $this->access->assertReadableClass($perimeter, $classId);
        $tenantId = $principal->tenantId;
        $className = $this->roster->assertClass($tenantId, $establishmentId, $classId);
        $period = $this->hours->period($tenantId, $establishmentId, $from, $to);
        $totals = $this->hours->byLearner($tenantId, $establishmentId, $period['from'], $period['to']);
        $open = [];
        foreach ($this->store->select(ConductProposals::TABLE, $tenantId, $establishmentId, ['class_id' => $classId, 'period_from' => $period['from'], 'period_to' => $period['to']]) as $row) {
            if ($row['status'] !== DisciplineDefaults::SUPERSEDED) {
                $open[(string) $row['person_id']][] = ['id' => (string) $row['id'], 'proposed_label' => (string) $row['proposed_label'], 'status' => (string) $row['status']];
            }
        }
        $students = [];
        foreach ($this->roster->ofClass($tenantId, $establishmentId, $classId) as $student) {
            $students[] = $student + AttendanceHours::hours($totals[$student['person_id']] ?? null) + ['proposals' => $open[$student['person_id']] ?? []];
        }

        return $this->json(['class_id' => $classId, 'class_name' => $className, 'period' => $period, 'hours_per_session' => DisciplineDefaults::HOURS_PER_SESSION, 'students' => $students]);
    }

    /** Calcule les propositions du barème pour une classe (ou tout l'établissement sans `class_id`) sur la période. */
    public function compute(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::COMPUTE);
        $input = Input::of($request);
        $input->reference('class_id', false, true);
        $input->date('from', false);
        $input->date('to', false);
        $input->string('period_label', 80, false);
        $data = $input->done();
        $period = $this->hours->period($principal->tenantId, $establishmentId, $data['from'] ?? null, $data['to'] ?? null, $data['period_label'] ?? null);

        return $this->json($this->proposals->compute($principal, $establishmentId, $perimeter, $data['class_id'] ?? null, $period));
    }

    public function proposals(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::DISCIPLINE_READ);

        return $this->page($this->listing->page(
            $request,
            ConductProposals::TABLE,
            $principal->tenantId,
            $establishmentId,
            ['status', 'class_id', 'person_id', 'decision_level'],
            ['created_at', 'unjustified_hours'],
            'created_at DESC',
            static fn (array $row): array => ConductProposals::resource($row),
            [],
            $this->scoped->sqlFilter($perimeter),
        ));
    }

    public function proposal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::DISCIPLINE_READ);
        $row = $this->store->findOrFail(ConductProposals::TABLE, $principal->tenantId, $establishmentId, self::id($request), 'Proposition introuvable.');
        $this->access->assertReadableClass($perimeter, is_string($row['class_id']) ? $row['class_id'] : null);

        return $this->json(ConductProposals::resource($row));
    }

    /** Décision d'une personne habilitée : `apply`, ou `reject` avec un motif (RG-PRE-2). */
    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::DISCIPLINE_READ);
        $input = Input::of($request);
        $decision = $input->enum('decision', ['apply', 'reject']);
        $input->string('reason', 1000, false);
        $data = $input->done();
        if ($decision === 'reject' && !isset($data['reason'])) {
            throw HttpException::validation(['reason' => ['Le rejet d\'une sanction proposée est motivé.']]);
        }

        return $this->json($this->proposals->decide($principal, $establishmentId, $perimeter, self::id($request), $decision === 'apply', $data['reason'] ?? null));
    }

    public function exclusions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::EXCLUSION_READ);

        return $this->page($this->listing->page(
            $request,
            ClassExclusions::TABLE,
            $principal->tenantId,
            $establishmentId,
            ['class_id', 'person_id', 'session_date'],
            ['session_date', 'created_at'],
            'created_at DESC',
            static fn (array $row): array => ClassExclusions::resource($row),
            [],
            $this->scoped->sqlFilter($perimeter),
        ));
    }

    /** Signale une exclusion de cours ; rejeu de la même `client_uuid` : 200 avec l'exclusion déjà enregistrée. */
    public function reportExclusion(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::EXCLUSION_WRITE);
        $input = Input::of($request);
        $input->reference('client_uuid');
        $input->reference('person_id');
        $input->reference('class_id');
        $input->date('session_date');
        $input->string('hour_label', 20);
        $input->reference('session_id', false, true);
        $input->string('reason', 500);
        [$resource, $replayed] = $this->exclusions->report($principal, $establishmentId, $perimeter, $input->done());

        return $this->json($resource + ['replayed' => $replayed], $replayed ? 200 : 201);
    }

    /** @param array<string, mixed> $query */
    private static function queryDate(array $query, string $field): ?string
    {
        $value = $query[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$field => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $value;
    }
}
