<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Academic\Application\Permissions;
use Scholaris\Modules\Apps\Primary\Academic\Application\ReferentialService;
use Scholaris\Modules\Apps\Primary\Academic\Domain\EvaluationMode;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Listing;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Profils de niveau, domaines, matières par niveau et compétences (EX-PRI-05, 16, 17) ; export CSV/XLSX du référentiel. */
final class ReferentialController extends Controller
{
    use ExportsList;

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly Listing $listing,
        private readonly ReferentialService $service,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function profiles(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, ReferentialService::TABLE_PROFILES, ['section_org_unit_id', 'is_final_level', 'is_exam_level'], ['sort_order', 'cycle_code'], 'sort_order', Resources::profile(...));
    }

    public function showProfile(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(Resources::profile($this->one($request, ReferentialService::TABLE_PROFILES, 'Profil de niveau introuvable.')));
    }

    public function createProfile(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('level_structure_id', false);
        $input->string('name', 160, false);
        $input->reference('section_org_unit_id', false, true);
        $input->string('cycle_code', 20, false, true);
        $input->int('sort_order', 0, 999, false);
        $input->bool('is_final_level', false);
        $input->bool('is_exam_level', false);
        $input->enum('language', ['fr', 'en'], false);

        return $this->json(Resources::profile($this->service->createProfile($principal, $establishmentId, $input->done())), 201);
    }

    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->reference('section_org_unit_id', false, true);
        $input->string('cycle_code', 20, false, true);
        $input->int('sort_order', 0, 999, false);
        $input->bool('is_final_level', false);
        $input->bool('is_exam_level', false);
        $input->enum('language', ['fr', 'en'], false);

        return $this->json(Resources::profile($this->service->updateProfile($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function domains(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, ReferentialService::TABLE_DOMAINS, ['academic_year_id', 'active'], ['sort_order', 'code'], 'sort_order', Resources::domain(...));
    }

    public function createDomain(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->code('code', 40);
        $input->string('label_fr');
        $input->string('label_en', 160, false, true);
        $input->int('sort_order', 0, 999, false);

        return $this->json(Resources::domain($this->service->createDomain($principal, $establishmentId, $input->done())), 201);
    }

    public function updateDomain(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('label_fr');
        $input->string('label_en', 160, false, true);
        $input->int('sort_order', 0, 999, false);
        $input->bool('active', false);

        return $this->json(Resources::domain($this->service->updateDomain($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function levelSubjects(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, ReferentialService::TABLE_SUBJECTS, ['academic_year_id', 'level_structure_id', 'domain_id', 'active'], ['sort_order', 'subject_code'], 'sort_order', Resources::levelSubject(...));
    }

    public function createLevelSubject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->reference('level_structure_id');
        $input->reference('domain_id');
        $input->code('subject_code', 40);
        $input->string('subject_name');
        $input->enum('evaluation_mode', EvaluationMode::ALL, false);
        $input->int('sort_order', 0, 999, false);

        return $this->json(Resources::levelSubject($this->service->createLevelSubject($principal, $establishmentId, $input->done())), 201);
    }

    public function updateLevelSubject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->reference('domain_id');
        $input->enum('evaluation_mode', EvaluationMode::ALL);
        $input->int('sort_order', 0, 999, false);
        $input->bool('active', false);

        return $this->json(Resources::levelSubject($this->service->updateLevelSubject($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function competencies(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, ReferentialService::TABLE_COMPETENCIES, ['academic_year_id', 'level_structure_id', 'domain_id', 'subject_id', 'active'], ['sort_order', 'code'], 'sort_order', Resources::competency(...));
    }

    public function createCompetency(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->reference('level_structure_id');
        $input->reference('domain_id');
        $input->reference('subject_id', false);
        $input->code('code', 40);
        $input->string('label_fr', 255);
        $input->string('label_en', 255, false, true);
        $input->int('sort_order', 0, 999, false);

        return $this->json(Resources::competency($this->service->createCompetency($principal, $establishmentId, $input->done())), 201);
    }

    public function updateCompetency(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->reference('domain_id');
        $input->string('label_fr', 255);
        $input->string('label_en', 255, false, true);
        $input->int('sort_order', 0, 999, false);
        $input->bool('active', false);

        return $this->json(Resources::competency($this->service->updateCompetency($principal, $establishmentId, self::id($request), $input->done())));
    }

    /** GET /primary/referential/export?kind=domains|subjects|competencies|levels&academic_year_id=&format=csv|xlsx */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, Permissions::READ);
        $query = $request->getQueryParams();
        $kind = is_string($query['kind'] ?? null) ? $query['kind'] : 'competencies';
        $year = is_string($query['academic_year_id'] ?? null) ? $query['academic_year_id'] : null;
        $where = $year === null ? [] : ['academic_year_id' => $year];
        $t = $principal->tenantId;
        $rows = [];
        switch ($kind) {
            case 'levels':
                $headers = ['level_structure_id', 'cycle', 'order', 'final', 'exam', 'language'];
                foreach ($this->store->select(ReferentialService::TABLE_PROFILES, $t, $establishmentId, [], 'sort_order', 1000) as $r) {
                    $rows[] = [$r['level_structure_id'], $r['cycle_code'], $r['sort_order'], $r['is_final_level'], $r['is_exam_level'], $r['language']];
                }
                break;
            case 'domains':
                $headers = ['code', 'label_fr', 'label_en', 'order', 'active'];
                foreach ($this->store->select(ReferentialService::TABLE_DOMAINS, $t, $establishmentId, $where, 'sort_order, code', 1000) as $r) {
                    $rows[] = [$r['code'], $r['label_fr'], $r['label_en'], $r['sort_order'], $r['active']];
                }
                break;
            case 'subjects':
                $headers = ['level_structure_id', 'subject_code', 'subject_name', 'domain_id', 'mode', 'order', 'active'];
                foreach ($this->store->select(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, $where, 'level_structure_id, sort_order', 5000) as $r) {
                    $rows[] = [$r['level_structure_id'], $r['subject_code'], $r['subject_name'], $r['domain_id'], $r['evaluation_mode'], $r['sort_order'], $r['active']];
                }
                break;
            case 'competencies':
                $headers = ['level_structure_id', 'code', 'label_fr', 'label_en', 'domain_id', 'subject_id', 'order', 'active'];
                foreach ($this->store->select(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, $where, 'level_structure_id, sort_order', 5000) as $r) {
                    $rows[] = [$r['level_structure_id'], $r['code'], $r['label_fr'], $r['label_en'], $r['domain_id'], $r['subject_id'], $r['sort_order'], $r['active']];
                }
                break;
            default:
                throw HttpException::validation(['kind' => ['Valeurs possibles : levels, domains, subjects, competencies.']]);
        }

        return $this->csvResponse($this->responses, $this->streams, 'primaire-referentiel-' . $kind . '.csv', $headers, $rows, $request);
    }

    /**
     * @param list<string> $filters
     * @param list<string> $sorts
     * @param \Closure(array<string, mixed>): array<string, mixed> $map
     */
    private function list(ServerRequestInterface $request, string $table, array $filters, array $sorts, string $defaultSort, \Closure $map): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, Permissions::READ);

        return $this->page($this->listing->page($request, $table, $principal->tenantId, $establishmentId, $filters, $sorts, $defaultSort, $map));
    }

    /** @return array<string, mixed> */
    private function one(ServerRequestInterface $request, string $table, string $missing): array
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, Permissions::READ);

        return $this->store->findOrFail($table, $principal->tenantId, $establishmentId, self::id($request), $missing);
    }
}
