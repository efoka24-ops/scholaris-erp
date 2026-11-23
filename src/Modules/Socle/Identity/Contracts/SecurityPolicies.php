<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Règles de sécurité réglables depuis le back-office de la plateforme (C-112 point 6, super administrateur seul) :
 * double authentification obligatoire par rôle (rôles sensibles, sous réserve d'AUTH_2FA_REQUIRED) et durée d'une session.
 * Les rôles de la plateforme exigent TOUJOURS la double authentification : ils ne se modifient pas (`locked`).
 */
interface SecurityPolicies
{
    public const SESSION_HOURS_DEFAULT = 8;
    public const SESSION_HOURS_MIN = 1;
    public const SESSION_HOURS_MAX = 72;

    /** @return list<array{code: string, label_fr: string, label_en: string, level: string, two_factor_required: bool, locked: bool}> */
    public function roles(): array;

    /** 404 rôle inconnu, 409 rôle de la plateforme (toujours exigée). Journalisé. */
    public function setTwoFactorRequired(string $role, bool $required, string $actorId): void;

    /** Durée de validité d'une nouvelle session, en heures (hors « rester connecté », 30 jours). */
    public function sessionHours(): int;

    /** 422 hors bornes. Journalisé. */
    public function setSessionHours(int $hours, string $actorId): void;
}
