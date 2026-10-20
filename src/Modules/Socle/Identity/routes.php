<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Identity\Http\ActivationController;
use Scholaris\Modules\Socle\Identity\Http\AuthController;
use Scholaris\Modules\Socle\Identity\Http\MeController;
use Scholaris\Modules\Socle\Identity\Http\NamedAccessController;
use Scholaris\Modules\Socle\Identity\Http\RoleAssignmentController;
use Scholaris\Modules\Socle\Identity\Http\TwoFactorController;
use Scholaris\Modules\Socle\Identity\Http\UserController;

/**
 * Routes du service S01 Identité. Connexion et activation : publiques. Profil, contextes, appareils et 2FA : authentifiés
 * sans établissement actif (`tenant` => false). Option `pre_2fa` : accessible avec un jeton dont la double authentification
 * exigée n'est pas encore franchie. Affectations de rôles et comptes : exigent X-Establishment-Id.
 */
return static function (RouteRegistry $routes): void {
    $public = ['public' => true];
    $session = ['tenant' => false];
    $beforeTwoFactor = ['tenant' => false, 'pre_2fa' => true];

    $routes->add('POST', '/auth/login', [AuthController::class, 'login'], $public);
    $routes->add('POST', '/auth/login-authenticator', [AuthController::class, 'loginAuthenticator'], $public);
    $routes->add('POST', '/auth/activation/request', [ActivationController::class, 'request'], $public);
    $routes->add('POST', '/auth/activation/verify', [ActivationController::class, 'verify'], $public);
    $routes->add('POST', '/auth/activation/password', [ActivationController::class, 'password'], $public);
    $routes->add('POST', '/auth/password/forgot', [ActivationController::class, 'forgot'], $public);

    $routes->add('POST', '/auth/logout', [AuthController::class, 'logout'], $beforeTwoFactor);
    $routes->add('POST', '/auth/not-me', [AuthController::class, 'notMe'], $beforeTwoFactor);
    $routes->add('POST', '/auth/2fa/verify', [TwoFactorController::class, 'verify'], $beforeTwoFactor);
    $routes->add('POST', '/auth/2fa/sms', [TwoFactorController::class, 'sms'], $beforeTwoFactor);
    $routes->add('POST', '/auth/2fa/recovery', [TwoFactorController::class, 'recovery'], $beforeTwoFactor);
    $routes->add('POST', '/auth/2fa/setup', [TwoFactorController::class, 'setup'], $beforeTwoFactor);
    $routes->add('POST', '/auth/2fa/confirm', [TwoFactorController::class, 'confirm'], $beforeTwoFactor);

    $routes->add('GET', '/me', [MeController::class, 'show'], $beforeTwoFactor);
    $routes->add('GET', '/me/contexts', [MeController::class, 'contexts'], $session);
    $routes->add('POST', '/me/context', [MeController::class, 'switchContext'], $session);
    $routes->add('GET', '/me/todo', [MeController::class, 'todo'], $session);
    $routes->add('GET', '/me/devices', [AuthController::class, 'devices'], $session);
    $routes->add('DELETE', '/me/devices/{id}', [AuthController::class, 'revokeDevice'], $session);
    $routes->add('DELETE', '/me/sessions', [AuthController::class, 'logoutEverywhere'], $beforeTwoFactor);

    $routes->add('GET', '/role-assignments', [RoleAssignmentController::class, 'index']);
    $routes->add('POST', '/role-assignments', [RoleAssignmentController::class, 'store']);
    $routes->add('POST', '/role-assignments/delegations', [RoleAssignmentController::class, 'delegate']);
    $routes->add('DELETE', '/role-assignments/{id}', [RoleAssignmentController::class, 'destroy']);

    $routes->add('POST', '/users', [UserController::class, 'store']);

    // PP3 (T607) : journal des consultations nominatives de l'établissement actif (directeur).
    $routes->add('GET', '/audit/named-access', [NamedAccessController::class, 'index']);
};
