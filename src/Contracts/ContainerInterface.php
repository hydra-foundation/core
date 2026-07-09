<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind an abstract to a concrete implementation, resolved once and reused.
     *
     * A class-string is autowired; a callable is used as a factory. The only
     * factory signature every adapter must honor is the zero-argument closure
     * that captures what it needs — the dominant style in Hydra's providers.
     * Adapters MAY additionally inject type-hinted factory parameters from the
     * container (the app's PHP-DI adapter does), but code written against this
     * interface should not rely on that. There is no transient counterpart yet
     * — every resolved entry is shared (YAGNI until a use case needs
     * per-resolution instances).
     */
    public function singleton(string $abstract, callable|string $concrete): void;

    /**
     * Register an already-constructed instance under an abstract.
     */
    public function instance(string $abstract, object $instance): void;

    /**
     * Whether the container can RESOLVE the abstract — not whether it was
     * explicitly registered. An autowiring adapter (like the app's PHP-DI one)
     * answers true for any instantiable class it could construct on demand, so
     * this cannot distinguish "someone wired X" from "X happens to be
     * autowirable". Use it for capability checks ("can I get() this without it
     * throwing?"), never as evidence that a provider ran.
     */
    public function bound(string $abstract): bool;
}
