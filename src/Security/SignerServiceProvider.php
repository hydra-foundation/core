<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Binds the framework's {@see Signer} from APP_KEY.
 *
 * This is the one place APP_KEY is named. The key is read with
 * {@see Environment::required()} inside the binding closure, so a missing or
 * empty APP_KEY fails loud the first time anything resolves a Signer (the CSRF
 * guard, on the first request that touches a form) with a message naming the
 * variable — not silently, and not as a 500 buried deep in a request. A console
 * command that never signs anything never resolves the Signer and so never trips
 * the requirement.
 *
 * Registered explicitly in the app's Bootstrap, alongside the PSR-7 provider —
 * it is framework mechanism (every Hydra app signs the same way), not app policy.
 */
final class SignerServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(Signer::class, function () use ($container) {
            $environment = $container->get(Environment::class);

            return Signer::fromHex($environment->required('APP_KEY'));
        });
    }
}
