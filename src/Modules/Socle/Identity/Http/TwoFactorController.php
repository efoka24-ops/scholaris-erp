<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Support\QrSvg;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorService;
use Scholaris\Modules\Socle\Identity\Domain\Exception\TwoFactorRejected;
use Scholaris\Modules\Socle\Identity\Http\Requests\CodeRequest;

/** Double authentification : activation (TOTP + codes de secours), vérification, SMS, code de secours. */
final class TwoFactorController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly PermissionResolver $resolver,
        private readonly JsonResponder $responder,
        private readonly Problems $problems,
        private readonly Actor $actor,
    ) {
    }

    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor->user($request);
        $payload = $this->twoFactor->setup($user);
        // QR code du lien otpauth:// : scanné par l'application d'authentification au lieu de recopier la clé.
        $payload['qr_data_uri'] = QrSvg::dataUri($payload['otpauth_uri']);
        $payload['required_for'] = $this->resolver->sensitiveRoles($user->tenantId, $user->personId, $this->actor->locale($request));

        return $this->responder->json($payload);
    }

    public function confirm(ServerRequestInterface $request): ResponseInterface
    {
        $input = CodeRequest::from($request);
        try {
            $codes = $this->twoFactor->confirm($this->actor->user($request), $this->actor->token(), $input->code);
        } catch (TwoFactorRejected $e) {
            return $this->rejected($request, $e);
        }

        return $this->responder->json(['two_factor_enabled' => true, 'backup_codes' => $codes]);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $input = CodeRequest::from($request);
        try {
            $this->twoFactor->verify($this->actor->user($request), $this->actor->token(), $input->code, $input->method, $input->trustDevice);
        } catch (TwoFactorRejected $e) {
            return $this->rejected($request, $e);
        }

        return $this->responder->json(['verified' => true, 'trusted' => $input->trustDevice]);
    }

    public function sms(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->twoFactor->sendSmsCode($this->actor->user($request)));
    }

    public function recovery(ServerRequestInterface $request): ResponseInterface
    {
        $input = CodeRequest::from($request);
        try {
            $left = $this->twoFactor->useBackupCode($this->actor->user($request), $this->actor->token(), $input->code, $input->trustDevice);
        } catch (TwoFactorRejected $e) {
            return $this->rejected($request, $e);
        }

        return $this->responder->json(['verified' => true, 'backup_codes_left' => $left]);
    }

    private function rejected(ServerRequestInterface $request, TwoFactorRejected $e): ResponseInterface
    {
        if ($e->attemptsLeft === 0) {
            return $this->problems->build($request, 401, 'Session fermée', $e->getMessage(), 'two-factor-exhausted', ['attempts_left' => 0], ['WWW-Authenticate' => 'Bearer']);
        }

        return $this->problems->build($request, 422, 'Code refusé', $e->getMessage(), 'two-factor-invalid', ['attempts_left' => $e->attemptsLeft]);
    }
}
