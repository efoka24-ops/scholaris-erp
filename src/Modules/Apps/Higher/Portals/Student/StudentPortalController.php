<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Portals\Student;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;

/** Routes /higher/portal/student et /higher/portal/guarantor (périmètre « soi », permission self.read). */
final class StudentPortalController
{
    private const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    public function __construct(private readonly StudentPortalService $portal, private readonly JsonResponder $responder)
    {
    }

    public function student(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->portal->student(self::principal($request), self::establishment($request))]);
    }

    public function guarantor(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->portal->guarantor(self::principal($request), self::establishment($request))]);
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            throw HttpException::badRequest('L\'en-tête Idempotency-Key est obligatoire.');
        }
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body) || array_diff(array_keys($body), ['student_id', 'account_id', 'amount_xaf', 'channel', 'payer_phone']) !== []) {
            throw HttpException::validation(['body' => ['Champs permis : student_id, account_id, amount_xaf, channel, payer_phone.']]);
        }
        $errors = [];
        $student = $body['student_id'] ?? null;
        $account = $body['account_id'] ?? null;
        $amount = $body['amount_xaf'] ?? null;
        $channel = $body['channel'] ?? null;
        $phone = $body['payer_phone'] ?? null;
        if ($student !== null && (!is_string($student) || preg_match(self::ID, $student) !== 1)) {
            $errors['student_id'][] = 'Identifiant attendu.';
        }
        if (!is_string($account) || preg_match(self::ID, $account) !== 1) {
            $errors['account_id'][] = 'Identifiant du compte attendu.';
        }
        if (!is_int($amount) || $amount < 1 || $amount > 100000000) {
            $errors['amount_xaf'][] = 'Montant entier positif en XAF attendu.';
        }
        if (!in_array($channel, ['orange_money', 'mtn_momo'], true)) {
            $errors['channel'][] = 'orange_money ou mtn_momo.';
        }
        if (!is_string($phone) || preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            $errors['payer_phone'][] = 'Numéro à débiter attendu.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->responder->json(['data' => $this->portal->pay(
            self::principal($request),
            self::establishment($request),
            is_string($student) ? $student : null,
            (string) $account,
            (int) $amount,
            (string) $channel,
            (string) $phone,
            $key,
        )], 201);
    }

    private static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }
}
