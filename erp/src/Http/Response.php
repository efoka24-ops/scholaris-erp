<?php

declare(strict_types=1);

namespace Scholaris\Http;

/**
 * Reponse HTTP.
 *
 * Les en-tetes de securite sont poses par defaut sur chaque reponse HTML :
 * les oublier page par page serait la regle plutot que l'exception.
 */
final class Response
{
    private string $content;

    private int $status;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Sert un fichier stocke, en telechargement.
     *
     * Le type est impose plutot que devine par le navigateur, et le nom
     * propose est echappe : un document depose sous un nom contenant des
     * guillemets ne doit pas pouvoir injecter d'en-tete supplementaire.
     *
     * Content-Disposition vaut « attachment » sans exception. Afficher un
     * document televerse dans l'onglet reviendrait a executer son contenu sur
     * l'origine de l'application : un HTML depose comme piece jointe y
     * lirait la session de celui qui l'ouvre.
     */
    public static function download(string $content, string $filename, string $mimeType): self
    {
        $safeName = str_replace(["\r", "\n", '"'], '', $filename);

        return new self($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name.': '.$value, true);
            }
        }

        echo $this->content;
    }
}
