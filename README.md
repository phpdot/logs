# phpdot/logs

The observability engine for the PHPdot framework — distributed tracing and structured logging behind one interface.

Packages trace and log against a single injected `TracerInterface`; the application binds the backend (a `Writer`). The same code writes to [tracelog](https://github.com/phpdot/tracelog) (rich encrypted files), [psr-bridge](https://github.com/phpdot/psr-bridge) (Monolog / any PSR-3 logger), or nothing — without changing a line. One `trace_id` ties every log and span in a request together, across packages.

## Usage

Inject the tracer and use it — anywhere, no setup required:

```php
use PHPdot\Contracts\Logs\TracerInterface;

final class OrderService
{
    public function __construct(private readonly TracerInterface $tracer) {}

    public function place(int $id): void
    {
        $this->tracer->trace('order.place', 'internal', function ($span) use ($id): void {
            $span->setAttribute('order.id', $id);
            $this->tracer->info('order placed', ['id' => $id]);
        });
    }
}
```

If no span is active, the tracer mints a trace context on the first call — so logging always correlates.

## Channels

Scope a package to its own stream; `tracelog` writes each channel to `{channel}.log`:

```php
$log = $tracer->channel('http');
$log->info('GET /orders', ['status' => 200]);   // → http.log, same trace_id
```

## Request boundary

At the server entry point, the kernel opens one root span per request — continuing an inbound W3C `traceparent` when present — and flushes on the way out. Your packages never call it:

```php
$kernel->handle($route, fn () => $dispatcher->handle($request), $request->header('traceparent'));
```

## Backends

The app binds `WriterInterface` to pick where records go — the only line that changes:

- [phpdot/tracelog](https://github.com/phpdot/tracelog) — encrypted, per-channel files
- [phpdot/psr-bridge](https://github.com/phpdot/psr-bridge) — any PSR-3 logger (Monolog)
- `NullWriter` (built in, the default) — discard

No sampling: if logging is enabled, every log and span is stored.

## Requirements

- PHP >= 8.4

## License

MIT
