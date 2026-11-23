<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Registre extensible des applications : chaque application enregistre son descripteur (le dernier enregistré l'emporte). */
final class ApplicationRegistry
{
    /** @var array<string, ApplicationDescriptor> */
    private array $descriptors = [];

    public function register(ApplicationDescriptor $descriptor): void
    {
        $this->descriptors[$descriptor->establishmentType] = $descriptor;
    }

    public function for(string $establishmentType): ?ApplicationDescriptor
    {
        return $this->descriptors[$establishmentType] ?? null;
    }

    /** @return list<ApplicationDescriptor> */
    public function all(): array
    {
        return array_values($this->descriptors);
    }
}
