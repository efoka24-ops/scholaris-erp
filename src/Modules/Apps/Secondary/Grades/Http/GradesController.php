<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Grades\Application\CommonTestService;
use Scholaris\Modules\Apps\Secondary\Grades\Application\FraudService;
use Scholaris\Modules\Apps\Secondary\Grades\Application\GradeNotifications;
use Scholaris\Modules\Apps\Secondary\Grades\Application\GradesSettings;
use Scholaris\Modules\Apps\Secondary\Grades\Application\SheetService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Notes du secondaire (T868 à T870) : paramètres et préréglage, grille de l'enseignant, contrôle d'affectation journalisé avant
 * toute saisie (TST-09), relevé de séquence (DOC-08), saisines pour fraude, épreuves communes, notifications NOT-04 et NOT-11.
 * Les épreuves, les notes, l'import par fichier joint, le cycle (verrouillage avec deuxième authentification) et les corrections
 * passent par les routes du socle Assessment (D4).
 */
final class GradesController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly GradesSettings $settings,
        private readonly SheetService $sheets,
        private readonly FraudService $frauds,
        private readonly CommonTestService $commonTests,
        private readonly GradeNotifications $notifications,
        private readonly Listing $listing,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->settings->show($principal, $establishmentId));
    }

    public function preset(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $keys = $input->custom('keys', static function (mixed $v): array {
            if (!is_array($v) || !array_is_list($v) || count($v) > 20) {
                throw new \InvalidArgumentException('Liste de clés de paramètres attendue.');
            }

            return array_values(array_filter($v, 'is_string'));
        }, false);
        $input->done();

        return $this->json($this->settings->preset($principal, $establishmentId, $keys));
    }

    public function sheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->sheet($principal, $establishmentId, self::query($request, 'class_id'), self::query($request, 'period_id')));
    }

    public function entryCheck(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $classId = (string) $input->reference('class_id');
        $subjectId = (string) $input->reference('subject_id');
        $input->done();

        return $this->json($this->sheets->entryCheck($principal, $establishmentId, $classId, $subjectId));
    }

    public function statement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $subject = $request->getQueryParams()['subject_id'] ?? null;

        return $this->json($this->sheets->statement($principal, $establishmentId, self::query($request, 'class_id'), self::query($request, 'period_id'), is_string($subject) && $subject !== '' ? $subject : null));
    }

    public function fraudReports(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->frauds->assertCanList($principal, $establishmentId);
        $map = static fn (array $row): array => FraudService::resource($row);

        return $this->page($this->listing->page($request, FraudService::TABLE, $principal->tenantId, $establishmentId, ['status', 'class_id', 'person_id'], ['created_at'], 'created_at DESC', $map));
    }

    public function reportFraud(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('class_id');
        $input->reference('subject_id');
        $input->reference('assessment_id');
        $input->reference('person_id');
        $input->reference('period_id', false, true);
        $input->string('note', 500, false, true);

        return $this->json($this->frauds->report($principal, $establishmentId, $input->done()), 201);
    }

    public function handleFraud(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('outcome', 500);

        return $this->json($this->frauds->handle($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function commonTests(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, SheetService::READ);
        $map = static fn (array $row): array => CommonTestService::resource($row);

        return $this->page($this->listing->page($request, CommonTestService::TABLE, $principal->tenantId, $establishmentId, ['academic_year_id', 'level_code', 'period_id', 'status'], ['held_on', 'created_at'], 'created_at', $map));
    }

    public function createCommonTest(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->code('level_code');
        $input->reference('subject_id');
        $input->reference('period_id');
        $input->string('title');
        $input->date('held_on', false, true);
        $input->references('assessment_ids', 100, false);

        return $this->json($this->commonTests->create($principal, $establishmentId, $input->done()), 201);
    }

    public function updateCommonTest(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('title');
        $input->date('held_on', false, true);
        $input->references('assessment_ids', 100);
        $input->enum('status', CommonTestService::STATUSES);

        return $this->json($this->commonTests->update($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function published(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('class_id');
        $input->reference('assessment_id');
        $input->string('subject_label');
        $input->string('label');
        $input->string('link', 255, false);

        return $this->json($this->notifications->published($principal, $establishmentId, $input->done()));
    }

    public function lateReminders(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('class_id');
        $input->reference('period_id');
        $input->string('period_label', 80, false);
        $input->date('due_on');
        $input->references('subject_ids', 40);

        return $this->json($this->notifications->late($principal, $establishmentId, $input->done()));
    }

    private static function query(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation([$name => ['Paramètre obligatoire.']]);
        }

        return $value;
    }
}
