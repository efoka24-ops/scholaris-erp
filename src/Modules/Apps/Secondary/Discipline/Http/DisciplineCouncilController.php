<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Discipline\Application\DisciplineCouncils;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Conseils de discipline (T866, API-15, RG-PRE-3) : convocation par le surveillant général ou le chef d'établissement, décision par
 * le président du conseil (`secondary.discipline.council.preside`).
 */
final class DisciplineCouncilController extends Controller
{
    public const READ = ['secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.discipline.council.preside'];
    public const CONVENE = ['secondary.discipline.manage', 'secondary.discipline.council.preside'];
    public const DECIDE = ['secondary.discipline.council.preside'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly DisciplineCouncils $councils,
        private readonly Listing $listing,
        private readonly ScopedByClass $scoped,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->page($this->listing->page(
            $request,
            DisciplineCouncils::TABLE,
            $principal->tenantId,
            $establishmentId,
            ['status', 'class_id', 'person_id'],
            ['hearing_on', 'created_at'],
            'hearing_on DESC',
            static fn (array $row): array => DisciplineCouncils::resource($row),
            [],
            $this->scoped->sqlFilter($perimeter),
        ));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $row = $this->store->findOrFail(DisciplineCouncils::TABLE, $principal->tenantId, $establishmentId, self::id($request), 'Conseil de discipline introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $row['class_id']);

        return $this->json(DisciplineCouncils::resource($row));
    }

    public function convene(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::CONVENE);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('class_id');
        $input->reference('proposal_id', false, true);
        $input->string('facts', 2000);
        $input->date('hearing_on');
        $input->custom('hearing_time', static function (mixed $v): string {
            if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                throw new \InvalidArgumentException('Heure au format HH:MM attendue.');
            }

            return $v;
        });
        $input->string('place', 160);
        $input->custom('members', static function (mixed $v): array {
            if (!is_array($v) || !array_is_list($v) || count($v) > 12) {
                throw new \InvalidArgumentException('Liste de 12 membres au plus attendue.');
            }
            $members = [];
            foreach ($v as $member) {
                if (!is_string($member) || trim($member) === '' || mb_strlen(trim($member)) > 120) {
                    throw new \InvalidArgumentException('Chaque membre est un texte de 120 caractères au plus.');
                }
                $members[] = trim($member);
            }

            return $members;
        }, false);

        return $this->json($this->councils->convene($principal, $establishmentId, $perimeter, $input->done()), 201);
    }

    /** Décision du conseil ; `final_exclusion` exige le procès-verbal et l'audition de l'élève et des parents (RG-PRE-3). */
    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::DECIDE);
        $input = Input::of($request);
        $input->custom('decision_kind', static function (mixed $v): string {
            if (!is_string($v) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v) !== 1) {
                throw new \InvalidArgumentException('Code de décision attendu (minuscules, chiffres, « _ », 40 caractères au plus).');
            }

            return $v;
        });
        $input->string('decision_reason', 1000);
        $input->string('minutes', 20000, false);
        $input->bool('student_heard', false);
        $input->bool('parents_heard', false);
        $input->int('exclusion_days', 1, 365, false, true);

        return $this->json($this->councils->decide($principal, $establishmentId, $perimeter, self::id($request), $input->done()));
    }
}
