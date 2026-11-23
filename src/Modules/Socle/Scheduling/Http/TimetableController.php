<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Scheduling\Application\AccessGuard;
use Scholaris\Modules\Socle\Scheduling\Application\ViewService;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\Input;

/** Vues de l'emploi du temps : semaine par classe, par enseignant ou par salle ; consommation des volumes horaires. */
final class TimetableController extends BaseController
{
    private const VIEWS = ['structure_id', 'teacher_person_id', 'room_id'];

    public function __construct(JsonResponder $responder, RequestContext $context, AccessGuard $guard, private readonly ViewService $views)
    {
        parent::__construct($responder, $context, $guard);
    }

    public function week(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->scope($request);
        $params = $request->getQueryParams();
        $errors = [];
        $week = $params['week'] ?? null;
        try {
            $week = Input::date()($week);
        } catch (\InvalidArgumentException $e) {
            $errors['week'] = [$e->getMessage()];
        }
        $filter = [];
        foreach (self::VIEWS as $field) {
            if (isset($params[$field])) {
                try {
                    $filter[$field] = Input::ref()($params[$field]);
                } catch (\InvalidArgumentException $e) {
                    $errors[$field] = [$e->getMessage()];
                }
            }
        }
        if (count(array_intersect(self::VIEWS, array_keys($params))) !== 1) {
            $errors['view'] = ['Indiquez exactement un de structure_id, teacher_person_id ou room_id.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Paramètres invalides.');
        }

        $view = (string) array_key_first($filter);

        return $this->ok(['data' => [
            'view' => ['type' => $view, 'id' => $filter[$view] ?? null],
        ] + $this->views->week($this->context->tenantId(), $this->context->establishmentId(), (string) $week, $filter, $scope)]);
    }

    public function consumption(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->guard->hasFullRead($this->principal($request), $this->context->establishmentId())) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter le suivi des volumes horaires.');
        }
        $params = $request->getQueryParams();
        $errors = [];
        $unit = $asOf = $planned = null;
        try {
            $unit = Input::ref()($params['course_unit_id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $errors['course_unit_id'] = [$e->getMessage()];
        }
        try {
            $asOf = Input::date()($params['as_of'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $errors['as_of'] = [$e->getMessage()];
        }
        if (isset($params['planned_minutes'])) {
            $value = filter_var($params['planned_minutes'], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                $errors['planned_minutes'] = ['Nombre entier de minutes attendu.'];
            } else {
                $planned = $value;
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Paramètres invalides.');
        }

        return $this->ok(['data' => $this->views->consumption($this->context->tenantId(), $this->context->establishmentId(), (string) $unit, (string) $asOf, $planned)]);
    }
}
