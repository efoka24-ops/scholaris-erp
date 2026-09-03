<?php

declare(strict_types=1);

namespace Scholaris\Http\Exception;

use RuntimeException;

/**
 * Erreur portant un code HTTP. Permet a un controleur d'interrompre le
 * traitement (403, 404, 422) sans que le point d'entree ait a distinguer les
 * pannes techniques des refus metier.
 */
class HttpException extends RuntimeException
{
    private int $statusCode;

    public function __construct(int $statusCode, string $message = '')
    {
        $this->statusCode = $statusCode;

        parent::__construct($message === '' ? self::defaultMessage($statusCode) : $message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    private static function defaultMessage(int $status): string
    {
        return [
            400 => 'Requete invalide.',
            401 => 'Authentification requise.',
            403 => 'Acces refuse.',
            404 => 'Page introuvable.',
            405 => 'Methode non autorisee.',
            419 => 'Session expiree, merci de recommencer.',
            422 => 'Donnees invalides.',
        ][$status] ?? 'Erreur.';
    }
}
