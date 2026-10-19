<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

/**
 * Verdict d'une ligne : ok, warning (reprise possible, à relire), duplicate (déjà repris : ignorée à l'application, idempotence)
 * ou error (bloque l'application du lot). `plan` porte les identifiants résolus pour l'application.
 */
final class RowVerdict
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const DUPLICATE = 'duplicate';
    public const ERROR = 'error';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, mixed> */
    public array $plan = [];

    public bool $duplicate = false;

    public ?string $personId = null;

    /** @param array<string, string> $row */
    public function __construct(public readonly int $line, public string $key, public readonly array $row)
    {
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function status(): string
    {
        if ($this->errors !== []) {
            return self::ERROR;
        }
        if ($this->duplicate) {
            return self::DUPLICATE;
        }

        return $this->warnings !== [] ? self::WARNING : self::OK;
    }

    public function applicable(): bool
    {
        return $this->errors === [] && !$this->duplicate;
    }
}
