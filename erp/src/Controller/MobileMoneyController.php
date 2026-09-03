<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use RuntimeException;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\Billing;
use Scholaris\Service\PawaPayGateway;
use Scholaris\Service\PawaPaySignature;
use Scholaris\Support\Http;
use Scholaris\Support\Validator;

/**
 * Encaissement Mobile Money via pawaPay.
 *
 * Le paiement n'est impute sur la facture qu'au statut COMPLETED, jamais a
 * l'initiation : une demande acceptee par la passerelle n'est pas un
 * encaissement, la famille devant encore valider sur son telephone.
 *
 * L'imputation elle-meme passe par Billing::recordPayment, comme un paiement en
 * especes : un reglement Mobile Money produit donc un recu numerote et un solde
 * recalcule, sans logique parallele.
 */
final class MobileMoneyController extends Controller
{
    /**
     * Operateurs proposes par defaut au Cameroun. Les identifiants exacts
     * dependent du compte : la commande "artisan pawapay:conf" les liste.
     */
    private const DEFAULT_PROVIDERS = [
        'MTN_MOMO_CMR' => 'MTN Mobile Money',
        'ORANGE_CMR' => 'Orange Money',
    ];

    public function form(Request $request): Response
    {
        $invoice = $this->findOrFail('invoices', (string) $request->attribute('id'));

        if (! PawaPayGateway::isConfigured($this->app->env())) {
            return $this->redirectWithError(
                '/finance/invoices/'.$invoice['id'],
                'La passerelle Mobile Money n est pas configuree (PAWAPAY_API_TOKEN absent).'
            );
        }

        return $this->view('finance.mobile-money', [
            'invoice' => $invoice,
            'student' => $this->table('students')->find((string) $invoice['student_id']),
            'providers' => $this->providers(),
            'environment' => PawaPayGateway::fromEnv($this->app->env())->environment(),
        ]);
    }

    public function initiate(Request $request): Response
    {
        $invoice = $this->findOrFail('invoices', (string) $request->attribute('id'));
        $back = '/finance/invoices/'.$invoice['id'];

        $validator = (new Validator($request))
            ->decimal('amount', 'montant', 1)
            ->required('phone_number', 'numero de telephone')
            ->in('provider', 'operateur', array_keys($this->providers()));

        if ($validator->fails()) {
            return $this->redirectWithError($back, implode(' ', $validator->errors()));
        }

        $data = $validator->validated();
        $amount = (float) $data['amount'];
        $balance = (float) $invoice['balance'];

        if (round($amount, 2) > round($balance, 2)) {
            return $this->redirectWithError(
                $back,
                'Le montant depasse le solde restant ('.number_format($balance, 0, ',', ' ').' FCFA).'
            );
        }

        // depositId genere par nous : il est a la fois notre reference et la
        // cle d'idempotence de pawaPay.
        $depositId = Table::uuid();
        $phone = PawaPayGateway::normalisePhone((string) $data['phone_number']);

        // La transaction est enregistree avant l'appel : si la passerelle
        // repond puis que le reseau coupe, la trace existe deja et le statut
        // pourra etre reconcilie.
        $transactionId = $this->table('payment_transactions')->insert([
            'id' => $depositId,
            'gateway_id' => null,
            'external_reference' => $depositId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'XAF',
            'phone_number' => $phone,
            'network' => $data['provider'],
            'status' => 'PENDING',
            'student_id' => $invoice['student_id'],
            'invoice_id' => $invoice['id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $gateway = PawaPayGateway::fromEnv($this->app->env());
            $result = $gateway->initiateDeposit($depositId, $amount, 'XAF', $phone, (string) $data['provider']);
        } catch (RuntimeException $e) {
            $this->updateTransaction($transactionId, 'FAILED', null, ['error' => $e->getMessage()]);

            return $this->redirectWithError($back, 'Passerelle injoignable : '.$e->getMessage());
        }

        $status = $result['status'];

        if ($status === PawaPayGateway::INITIATION_REJECTED) {
            $this->updateTransaction($transactionId, 'REJECTED', null, $result['raw']);

            return $this->redirectWithError(
                $back,
                'Demande refusee par la passerelle : '.($result['failureReason'] ?? 'motif non precise')
            );
        }

        $this->updateTransaction($transactionId, 'PROCESSING', $depositId, $result['raw']);

        return $this->redirectWithSuccess(
            '/finance/transactions/'.$transactionId,
            'Demande envoyee. La famille doit maintenant valider le paiement sur son telephone.'
        );
    }

    /**
     * Suivi d'une demande, avec consultation manuelle du statut.
     *
     * Le callback reste la voie normale ; cette page sert quand il tarde ou
     * s'est perdu.
     */
    public function show(Request $request): Response
    {
        $transaction = $this->findOrFail('payment_transactions', (string) $request->attribute('id'));

        return $this->view('finance.transaction', [
            'transaction' => $transaction,
            'invoice' => $transaction['invoice_id'] !== null
                ? $this->table('invoices')->find((string) $transaction['invoice_id'])
                : null,
            'providers' => $this->providers(),
        ]);
    }

    public function refresh(Request $request): Response
    {
        $transaction = $this->findOrFail('payment_transactions', (string) $request->attribute('id'));
        $back = '/finance/transactions/'.$transaction['id'];

        if (in_array((string) $transaction['status'], ['SUCCESS', 'FAILED', 'REJECTED'], true)) {
            return $this->redirect($back);
        }

        try {
            $gateway = PawaPayGateway::fromEnv($this->app->env());
            $result = $gateway->depositStatus((string) $transaction['external_reference']);
        } catch (RuntimeException $e) {
            return $this->redirectWithError($back, 'Passerelle injoignable : '.$e->getMessage());
        }

        if ($result['status'] === null) {
            return $this->redirectWithError($back, 'Statut indisponible pour le moment.');
        }

        $this->applyFinalStatus((string) $transaction['id'], $result['status'], $result['raw']);

        return $this->redirectWithSuccess($back, 'Statut mis a jour : '.$result['status']);
    }

    /**
     * Callback pawaPay.
     *
     * Route publique par necessite : elle est appelee par la passerelle, sans
     * session. La signature RFC-9421 remplace l'authentification, et le
     * traitement est fail-closed — un callback non verifie est refuse.
     */
    public function callback(Request $request): Response
    {
        $body = (string) file_get_contents('php://input');
        $headers = $this->incomingHeaders();

        $verification = $this->signature()->verify(
            'POST',
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            '/webhooks/pawapay',
            $headers,
            $body
        );

        if (! $verification['valid']) {
            error_log('[pawapay] callback refuse : '.$verification['reason']);

            return Response::json(['accepted' => false, 'reason' => 'signature'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return Response::json(['accepted' => false, 'reason' => 'payload'], 400);
        }

        $depositId = (string) ($payload['depositId'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if ($depositId === '' || $status === '') {
            return Response::json(['accepted' => false, 'reason' => 'champs manquants'], 400);
        }

        // La transaction est cherchee hors scope d'etablissement : le callback
        // n'a pas de session, donc pas d'etablissement courant. L'identifiant
        // est un UUID que nous avons genere, il designe donc sans ambiguite.
        $transaction = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT * FROM payment_transactions WHERE external_reference = :reference',
            ['reference' => $depositId]
        ));

        if ($transaction === null) {
            return Response::json(['accepted' => false, 'reason' => 'transaction inconnue'], 404);
        }

        $this->app->tenant()->set((string) $transaction['tenant_id']);
        $this->applyFinalStatus((string) $transaction['id'], $status, $payload);

        // pawaPay attend une reponse 200 : sans elle, le callback est rejoue.
        return Response::json(['accepted' => true]);
    }

    /**
     * Applique un statut definitif : imputation sur la facture si COMPLETED.
     *
     * @param  array<string, mixed>|null  $raw
     */
    private function applyFinalStatus(string $transactionId, string $status, ?array $raw): void
    {
        $transaction = $this->table('payment_transactions')->find($transactionId);

        if ($transaction === null) {
            return;
        }

        // Rejeu d'un callback deja traite : la passerelle repete ses
        // notifications, imputer deux fois creerait un paiement fantome.
        if ((string) $transaction['status'] === 'SUCCESS') {
            return;
        }

        if ($status !== PawaPayGateway::STATUS_COMPLETED) {
            $mapped = match ($status) {
                PawaPayGateway::STATUS_FAILED => 'FAILED',
                PawaPayGateway::STATUS_PROCESSING => 'PROCESSING',
                PawaPayGateway::STATUS_IN_RECONCILIATION => 'RECONCILIATION',
                default => $status,
            };

            $this->updateTransaction($transactionId, $mapped, null, $raw);

            return;
        }

        $invoiceId = $transaction['invoice_id'] ?? null;

        if ($invoiceId === null) {
            $this->updateTransaction($transactionId, 'SUCCESS', null, $raw);

            return;
        }

        $this->app->db()->transaction(function () use ($transactionId, $transaction, $invoiceId, $raw): void {
            $billing = new Billing($this->app->db(), $this->app->tenant());

            $billing->recordPayment(
                (string) $invoiceId,
                (float) $transaction['amount'],
                'MOBILE_MONEY',
                (string) $transaction['external_reference'],
                null,
                'Encaissement Mobile Money pawaPay'
            );

            $this->updateTransaction($transactionId, 'SUCCESS', null, $raw);
        });
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function updateTransaction(string $id, string $status, ?string $gatewayId, ?array $raw): void
    {
        $values = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'notified_at' => date('Y-m-d H:i:s'),
        ];

        if ($gatewayId !== null) {
            $values['gateway_id'] = $gatewayId;
        }

        if ($raw !== null) {
            $values['raw_response'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        $this->app->tenant()->global(function () use ($id, $values): void {
            $this->app->db()->execute(
                'UPDATE payment_transactions SET status = :status, updated_at = :updated_at,
                     notified_at = :notified_at'
                    .(isset($values['gateway_id']) ? ', gateway_id = :gateway_id' : '')
                    .(isset($values['raw_response']) ? ', raw_response = :raw_response' : '')
                    .' WHERE id = :id',
                $values + ['id' => $id]
            );
        });
    }

    private function signature(): PawaPaySignature
    {
        $env = $this->app->env();
        $configured = $env->get('PAWAPAY_PUBLIC_KEY', '') ?? '';

        $keys = [];

        if ($configured !== '') {
            // Cle collee dans .env : les retours a la ligne y sont echappes.
            $keys['configured'] = str_replace('\n', "\n", $configured);
        }

        $base = ($env->get('PAWAPAY_ENVIRONMENT', 'sandbox') ?? 'sandbox') === 'production'
            ? 'https://api.pawapay.io'
            : 'https://api.sandbox.pawapay.io';

        return new PawaPaySignature(
            $keys,
            $base.'/v2/public-keys',
            new Http(['Authorization' => 'Bearer '.($env->get('PAWAPAY_API_TOKEN', '') ?? '')])
        );
    }

    /**
     * @return array<string, string>
     */
    private function providers(): array
    {
        $configured = $this->app->env()->get('PAWAPAY_PROVIDERS', '') ?? '';

        if ($configured === '') {
            return self::DEFAULT_PROVIDERS;
        }

        $providers = [];

        // Format attendu : "MTN_MOMO_CMR=MTN Mobile Money,ORANGE_CMR=Orange Money"
        foreach (explode(',', $configured) as $entry) {
            $parts = explode('=', $entry, 2);

            if (count($parts) === 2) {
                $providers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $providers === [] ? self::DEFAULT_PROVIDERS : $providers;
    }

    /**
     * @return array<string, string>
     */
    private function incomingHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return array_change_key_case($headers, CASE_LOWER);
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
