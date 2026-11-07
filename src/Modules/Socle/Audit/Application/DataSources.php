<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;
use Scholaris\Modules\Socle\Audit\Contracts\TenantDataSource;

/** Registre des sources de données enregistrées par les modules (équivalent d'un conteneur « taggé »). */
final class DataSources
{
    /** @var list<TenantDataSource> */
    private array $tenant = [];
    /** @var list<SubjectDataSource> */
    private array $subject = [];

    /**
     * @param iterable<TenantDataSource> $tenant
     * @param iterable<SubjectDataSource> $subject
     */
    public function __construct(iterable $tenant = [], iterable $subject = [])
    {
        foreach ($tenant as $source) {
            $this->register($source);
        }
        foreach ($subject as $source) {
            $this->registerSubject($source);
        }
    }

    public function register(TenantDataSource $source): void
    {
        $this->tenant[] = $source;
    }

    public function registerSubject(SubjectDataSource $source): void
    {
        $this->subject[] = $source;
    }

    /** @return list<TenantDataSource> */
    public function tenantSources(): array
    {
        return $this->tenant;
    }

    /** @return list<SubjectDataSource> */
    public function subjectSources(): array
    {
        return $this->subject;
    }
}
