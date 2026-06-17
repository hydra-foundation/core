# Hydra Core

The framework-agnostic foundation: the application object, the contracts every
other package depends on, environment loading, and a base service provider.
Core defines interfaces and orchestration — it ships no concrete container,
kernel, or HTTP layer. Those are bound by the application at runtime.

## Contracts

The public API every other package codes against.

- **`KernelInterface`** — `handle()` runs the request lifecycle and emits the
  response (returns nothing); `terminate()` performs post-response clean-up.
- **`ServiceProviderInterface`** — `register()` binds services into the
  container; `boot()` runs once all providers are registered.
- **`ContainerInterface`** — extends PSR-11's `ContainerInterface` and adds
  `singleton()` (autowires a class-string or uses a callable factory; every
  entry is shared — there is no transient binding yet), `instance()` (register
  an already-constructed object), and `bound()` (does a binding exist). Core
  declares this interface only; the concrete container is supplied by the app.

## Environment

`Environment` reads `<basePath>/.env` and exposes typed accessors: `get()`,
`has()`, `string()`, `int()`, and `bool()`. A missing `.env` is tolerated;
a present-but-non-integer value passed to `int()` throws rather than silently
coercing to `0`.

## Providers

`Providers\ServiceProvider` is a concrete base class with empty `register()`
and `boot()` methods. App and package providers extend it and override what
they need; bindings are registered here.

## Application

`Application` accepts a `ContainerInterface` and owns the boot sequence:

- `register(ServiceProviderInterface)` — registers a provider (and boots it
  immediately if the app has already booted).
- `boot()` — boots every registered provider exactly once.
- `run()` — boots, resolves the `KernelInterface` from the container, calls
  `handle()`, flushes the response with `fastcgi_finish_request()` when
  available, then calls `terminate()`.
