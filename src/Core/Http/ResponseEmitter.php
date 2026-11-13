<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Http\Message\ResponseInterface;

/** Envoie une réponse PSR-7 au client (SAPI). */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $index => $value) {
                    header(sprintf('%s: %s', $name, $value), $index === 0);
                }
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }
}
