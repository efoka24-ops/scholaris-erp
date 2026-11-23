<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Identity\Application\ActivationService;
use Scholaris\Modules\Socle\Identity\Domain\Exception\CodeRejected;
use Scholaris\Modules\Socle\Identity\Http\Requests\IdentifierRequest;
use Scholaris\Modules\Socle\Identity\Http\Requests\SetPasswordRequest;
use Scholaris\Modules\Socle\Identity\Http\Requests\VerifyCodeRequest;

/** Activation du compte et mot de passe oublié : demande de code, vérification, nouveau mot de passe. */
final class ActivationController
{
    public function __construct(
        private readonly ActivationService $activation,
        private readonly JsonResponder $responder,
        private readonly Problems $problems,
    ) {
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $input = IdentifierRequest::from($request);

        return $this->responder->json($this->activation->request($input->identifier, $input->channel, false));
    }

    public function forgot(ServerRequestInterface $request): ResponseInterface
    {
        $input = IdentifierRequest::from($request);

        return $this->responder->json($this->activation->request($input->identifier, $input->channel, true));
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $input = VerifyCodeRequest::from($request);
        try {
            $ticket = $this->activation->verify($input->identifier, $input->code);
        } catch (CodeRejected $e) {
            return $this->problems->build($request, 422, 'Code refusé', $e->getMessage(), 'code-' . $e->reason, ['attempts_left' => $e->attemptsLeft]);
        }

        return $this->responder->json(['ticket' => $ticket, 'expires_in' => ActivationService::TICKET_TTL]);
    }

    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $input = SetPasswordRequest::from($request);
        $this->activation->setPassword($input->ticket, $input->password);

        return $this->responder->json(['status' => 'active', 'detail' => 'Votre mot de passe est enregistré. Vous pouvez vous connecter.']);
    }
}
