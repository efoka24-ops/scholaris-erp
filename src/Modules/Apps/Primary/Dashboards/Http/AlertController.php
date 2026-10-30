<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\AbsenceAlerts;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Alertes d'absences, paramètres « à confirmer » et aperçu du passage de classe (clôture d'année). */
final class AlertController extends Controller
{
    public const PROMOTION_READ = ['primary.promotion.decide', 'primary.settings.manage', 'primary.dashboard.read'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly AbsenceAlerts $alerts,
        private readonly ClassRoster $classes,
        private readonly PrimarySettings $settings,
        private readonly SettingsReader $reader,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $items = $this->alerts->open($principal, $establishmentId);
        $names = $this->classes->names($principal->tenantId, array_values(array_unique(array_column($items, 'person_id'))));

        return $this->json(['items' => array_map(static fn (array $a): array => $a + ['name' => $names[(string) $a['person_id']] ?? (string) $a['person_id']], $items)]);
    }

    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('note', 255);
        $v = $input->done();

        return $this->json($this->alerts->resolve($principal, $establishmentId, self::id($request), (string) $v['note']));
    }

    public function defaults(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, ['primary.settings.manage', 'primary.dashboard.read', 'primary.referential.read']);

        return $this->json(['items' => $this->settings->describe($principal->tenantId, $establishmentId)]);
    }

    /** Aperçu du passage : décisions des conseils validés du dernier trimestre et classe suivante de la table de passage. */
    public function promotionPreview(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::PROMOTION_READ);
        $this->access->assertWhole($principal, $perimeter, 'pri_year_closure');
        $year = $request->getQueryParams()['academic_year_id'] ?? '';
        $t = $principal->tenantId;
        $next = $this->reader->value($t, $establishmentId, 'people.promotion.next_structure', []);
        $next = is_array($next) ? $next : [];
        $classes = [];
        $totals = ['admitted' => 0, 'adjourned' => 0, 'cycle_end' => 0, 'undecided' => 0, 'no_next_class' => 0];
        foreach ($this->store->select(CouncilService::COUNCILS, $t, $establishmentId, ['academic_year_id' => is_string($year) ? $year : '', 'is_final_term' => 1], 'class_structure_id', 500) as $council) {
            $counts = ['admitted' => 0, 'adjourned' => 0, 'cycle_end' => 0, 'undecided' => 0];
            foreach ($this->store->select(CouncilService::LINES, $t, $establishmentId, ['council_id' => (string) $council['id']], 'id', 5000) as $line) {
                $decision = $council['status'] === 'validated' && is_string($line['decision']) && isset($counts[$line['decision']]) ? $line['decision'] : 'undecided';
                ++$counts[$decision];
            }
            $classId = (string) $council['class_structure_id'];
            $target = $next[$classId] ?? null;
            $missingTarget = $counts['admitted'] > 0 && (!is_string($target) || $target === '');
            $classes[] = ['class_id' => $classId, 'council_status' => (string) $council['status'], 'next_class_id' => is_string($target) ? $target : null, 'missing_next_class' => $missingTarget] + $counts;
            foreach ($counts as $k => $v) {
                $totals[$k] += $v;
            }
            $totals['no_next_class'] += $missingTarget ? $counts['admitted'] : 0;
        }

        return $this->json(['academic_year_id' => $year, 'classes' => $classes, 'totals' => $totals]);
    }
}
