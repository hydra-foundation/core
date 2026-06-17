<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind an abstract to a concrete implementation, resolved once and reused.
     *
     * A class-string is autowired; a callable is used as a factory. There is no
     * transient counterpart yet — every resolved entry is shared (YAGNI until a
     * use case needs per-resolution instances).
     */
    public function singleton(string $abstract, callable|string $concrete): void;

    /**
     * Register an already-constructed instance under an abstract.
     */
    public function instance(string $abstract, object $instance): void;

    /**
     * Determine if a binding exists.
     */
    public function bound(string $abstract): bool;
}
