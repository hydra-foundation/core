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

`Environment` reads `<basePath>/.env` once, at construction, and exposes typed
accessors: `get()`, `has()`, `string()`, `int()`, `bool()`, and `required()`.
A missing `.env` is tolerated.

- **Precedence** — the real process environment wins: a variable already set
  in `$_ENV`, `$_SERVER`, or via the OS (`getenv`) overrides the `.env` file's
  value, so deployments override checked-in defaults without editing the file.
  `.env` values fill the gaps (and are exported to `$_ENV`/`putenv`) but never
  clobber a variable the process already has.
- **`int()`** — a present-but-non-integer value throws rather than silently
  coercing to `0`.
- **`bool()`** — accepts, case-insensitively: `true`/`false`, `1`/`0`,
  `yes`/`no`, `on`/`off`. A missing key returns the default; any other present
  value (including an empty string) throws, same policy as `int()`.
- **`required()`** — returns the value or throws when the key is unset or
  empty, naming the missing key. Use it for config the app cannot run without.

Construct `Environment` exactly once per process, in your composition root,
and share the instance (Hydra's kernel binds the one the bootstrap builds into
the container). There is deliberately no internal static cache — the single
construction is explicit.

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
