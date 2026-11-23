<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Billing\Application\AccountingExport;

/** Export comptable CSV (journal des encaissements, ventes, remises) sur une période. */
final class ExportController extends BillingController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        BillingPolicy $policy,
        private readonly AccountingExport $export,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, BillingPolicy::MANAGE);
        $kind = $this->param($request, 'kind');
        $params = $request->getQueryParams();
        $errors = [];
        foreach (['from', 'to'] as $name) {
            $value = $params[$name] ?? null;
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                $errors[$name] = ['La date doit être au format AAAA-MM-JJ.'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $csv = $this->export->export($this->context->tenantId(), $this->context->establishmentId(), $kind, (string) $params['from'], (string) $params['to']);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $kind . '.csv"')
            ->withBody($this->streams->createStream($csv));
    }
}
