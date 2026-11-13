<?php

declare(strict_types=1);

namespace Tests\Support\People;

use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentRequest;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberProvider;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberRequest;

/**
 * Doublure d'application qui s'enregistre aux deux points d'extension de l'inscription : garde-fou (D3) et numéro (D5).
 * Le numéro est le même pour une personne déjà numérotée (un seul matricule en double cursus).
 */
final class EnrolmentExtensions implements EnrolmentGuard, EnrollmentNumberProvider
{
    /** @var list<EnrolmentRequest> */
    public array $guarded = [];

    public ?string $refusal = null;

    /** @var list<string> types d'établissement numérotés par l'application */
    public array $types = ['higher'];

    /** @var array<string, string> personne => numéro attribué */
    public array $numbers = [];

    public function refusal(EnrolmentRequest $request): ?string
    {
        $this->guarded[] = $request;

        return $this->refusal;
    }

    public function supports(EnrollmentNumberRequest $request): bool
    {
        return in_array($request->establishmentType, $this->types, true);
    }

    public function next(EnrollmentNumberRequest $request): string
    {
        return $this->numbers[$request->personId] ??= sprintf('%02dEC-CF-%04d', $request->year % 100, 42 + count($this->numbers));
    }
}
