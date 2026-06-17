<?php

declare(strict_types=1);

namespace Hydra\Core;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Contracts\ServiceProviderInterface;

/**
 * The core application
 */
final class Application
{
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];
    private bool $booted = false;

    public function __construct(
        private readonly ContainerInterface $container
    ) {}

    /**
     * The container every provider has registered into. Exposed so an alternate
     * entrypoint (e.g. a CLI) can resolve services from the same composition
     * root the HTTP path uses, instead of run()ing the HTTP kernel.
     */
    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $provider->register($this->container);
        $this->providers[] = $provider;

        // A provider registered after the app has booted still needs booting,
        // otherwise its boot() silently never runs.
        if ($this->booted) {
            $provider->boot($this->container);
        }

        return $this;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function run(): void
    {
        $this->boot();

        // The kernel is a service like any other — resolved only after every
        // provider has had a chance to register and boot its bindings.
        $kernel = $this->container->get(KernelInterface::class);

        $kernel->handle();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $kernel->terminate();
    }
}
