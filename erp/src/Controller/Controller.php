<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Application;
use Scholaris\Audit\AuditTrail;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Response;

/**
 * Base des controleurs : raccourcis vers les services de l'application.
 *
 * Aucun controle de securite ici. L'authentification, le CSRF et les
 * permissions sont appliques par Application avant l'appel du controleur, pour
 * qu'ils ne dependent jamais de la discipline de celui qui ecrit une action.
 */
abstract class Controller
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    protected function table(string $name): Table
    {
        return $this->app->table($name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function view(string $template, array $data = []): Response
    {
        return Response::html($this->app->view()->render($template, $data));
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** Redirige en affichant un message de confirmation sur la page suivante. */
    protected function redirectWithSuccess(string $to, string $message): Response
    {
        $this->app->session()->flash('success', $message);

        return Response::redirect($to);
    }

    protected function redirectWithError(string $to, string $message): Response
    {
        $this->app->session()->flash('error', $message);

        return Response::redirect($to);
    }

    /**
     * Charge une ligne ou interrompt en 404.
     *
     * Passe par Table, donc filtre sur l'etablissement courant : demander
     * l'identifiant d'une ligne d'un autre etablissement donne un 404, pas une
     * fuite. C'est la barriere anti-IDOR de l'application.
     *
     * @return array<string, mixed>
     */
    protected function findOrFail(string $table, string $id): array
    {
        $row = $this->table($table)->find($id);

        if ($row === null) {
            throw new HttpException(404);
        }

        return $row;
    }

    protected function currentUserId(): string
    {
        $id = $this->app->auth()->id();

        if ($id === null) {
            throw new HttpException(401);
        }

        return $id;
    }

    /** Annee academique active de l'etablissement, si elle existe. */
    protected function currentAcademicYearId(): ?string
    {
        $row = $this->table('academic_years')
            ->where('status', 'ACTIVE')
            ->orderBy('start_date', 'desc')
            ->first();

        return isset($row['id']) ? (string) $row['id'] : null;
    }
    /**
     * Journal des actes, avec valeur avant et valeur apres.
     *
     * Dans une administration publique, savoir qu'une donnee a change ne suffit
     * pas : il faut pouvoir dire ce qu'elle valait.
     */
    protected function trail(): AuditTrail
    {
        return new AuditTrail($this->app);
    }
}
