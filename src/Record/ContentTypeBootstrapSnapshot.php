<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

/**
 * Shares one catalog between models and Admin contributions within a single runtime scope
 *
 * A new request scope loads new definitions; saves become fully visible after the redirect
 */
final class ContentTypeBootstrapSnapshot
{
    /** @var list<RecordModule>|null */
    private ?array $modules = null;

    /** @var array<int,list<array<string,mixed>>> */
    private array $fields = [];

    /** @var (\Closure():array{modules:list<RecordModule>,fields:array<int,list<array<string,mixed>>>})|null */
    private ?\Closure $loader = null;

    private bool $loading = false;

    private ?\Throwable $failure = null;

    /**
     * Defers database-backed catalog loading until its first runtime consumer
     *
     * @param \Closure():array{modules:list<RecordModule>,fields:array<int,list<array<string,mixed>>>} $loader
     */
    public function defer(\Closure $loader): void
    {
        if ($this->modules !== null || $this->loader !== null || $this->loading || $this->failure !== null) {
            throw new \LogicException('Content Type bootstrap snapshot is already configured.');
        }

        $this->loader = $loader;
    }

    /**
     * @param list<RecordModule> $modules
     * @param array<int,list<array<string,mixed>>> $fields
     */
    private function initialize(array $modules, array $fields): void
    {
        $this->modules = $modules;
        $this->fields = $fields;
    }

    /** @return list<RecordModule> */
    public function modules(): array
    {
        $this->load();

        return $this->modules ?? throw new \LogicException('Content Type snapshot was not loaded.');
    }

    /** @return array<int,list<array<string,mixed>>> */
    public function fields(): array
    {
        $this->load();

        return $this->fields;
    }

    /**
     * Materializes the catalog once and reuses it for the rest of the request
     */
    private function load(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->modules !== null) {
            return;
        }
        if ($this->loading || $this->loader === null) {
            throw new \LogicException('Content Type snapshot is loading or has not been configured.');
        }

        $this->loading = true;
        try {
            $snapshot = ($this->loader)();
            $this->initialize($snapshot['modules'], $snapshot['fields']);
        } catch (\Throwable $failure) {
            $this->failure = $failure;
            throw $failure;
        } finally {
            $this->loading = false;
            $this->loader = null;
        }
    }
}
