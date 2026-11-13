<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;

/** Inscription d'un enfant (secrétaire ou directrice). */
final class EnrolmentController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly EnrolmentService $enrolments)
    {
        parent::__construct($responder, $access);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.enrol']);
        $input = Input::of($request);
        $input->string('first_name', 120);
        $input->string('last_name', 120);
        $input->date('birth_date');
        $input->enum('gender', ['male', 'female'], false);
        $input->string('birth_place', 160, false);
        $input->string('nationality', 80, false);
        $input->reference('section_id');
        $input->string('allergies', 500, false);
        $input->bool('photo_consent', false);
        $input->string('consent_evidence', 120, false);
        $input->bool('confirm_different', false);
        $input->custom('guardians', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 6) {
                throw new \InvalidArgumentException('Entre 1 et 6 parents ou tuteurs attendus.');
            }
            $out = [];
            foreach ($value as $guardian) {
                if (!is_array($guardian) || !in_array($guardian['kind'] ?? null, EnrolmentService::GUARDIAN_KINDS, true)) {
                    throw new \InvalidArgumentException('Chaque parent porte un « kind » parmi ' . implode(', ', EnrolmentService::GUARDIAN_KINDS) . '.');
                }
                $entry = ['kind' => (string) $guardian['kind']];
                if (isset($guardian['person_id'])) {
                    if (!is_string($guardian['person_id']) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $guardian['person_id']) !== 1) {
                        throw new \InvalidArgumentException('Identifiant de parent invalide.');
                    }
                    $entry['person_id'] = $guardian['person_id'];
                } else {
                    $identity = $guardian['identity'] ?? null;
                    if (!is_array($identity) || !is_string($identity['first_name'] ?? null) || !is_string($identity['last_name'] ?? null)) {
                        throw new \InvalidArgumentException('Chaque parent porte « person_id » ou « identity » (prénom et nom).');
                    }
                    $entry['identity'] = array_intersect_key($identity, array_flip(['first_name', 'last_name', 'phone', 'email', 'locale', 'gender']));
                }
                $out[] = $entry;
            }

            return $out;
        });

        return $this->json($this->enrolments->enrol($principal, $perimeter, $input->done()), 201);
    }
}
