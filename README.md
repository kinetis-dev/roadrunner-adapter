<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/roadrunner-adapter</strong>
  <br>
  <strong>RoadRunner runtime adapter for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/roadrunner-adapter"><img src="https://img.shields.io/packagist/v/kinetis/roadrunner-adapter?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/roadrunner-adapter"><img src="https://img.shields.io/packagist/dt/kinetis/roadrunner-adapter" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/roadrunner-adapter"><img src="https://img.shields.io/packagist/php-v/kinetis/roadrunner-adapter" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/roadrunner-adapter"><img src="https://img.shields.io/packagist/l/kinetis/roadrunner-adapter" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Speaks RoadRunner's own Goridge/`PSR7Worker` protocol — a persistent
worker loop, structurally the closest of Kinetis's four runtime adapters
to `FrankenPhpAdapter`'s, but built on RoadRunner's own PHP worker
library rather than a raw request-handling function. Converts to/from
PSR-7, including `multipart/form-data`/`application/x-www-form-urlencoded`
support parsed in userland — the same shape `kinetis/bref-adapter` needs
for the identical reason: a request body here is one in-memory string
with no live `php://input` behind it.

There's nothing to configure or call directly: install the package, and
`RuntimeDetector` picks it up automatically the moment `RR_MODE=http` is
set in the environment — RoadRunner's own `rr serve` sets this itself
when it spawns the worker.

```sh
composer require kinetis/roadrunner-adapter
```

**Two RoadRunner configuration settings are required**, not optional —
`http.raw_body: true`, and `http.max_request_size` to actually bound how
large a form body can grow before this adapter parses it (there's no
SAPI here to enforce `post_max_size`, and RoadRunner's own default is a
generous 1000 MB):

```yaml
http:
  address: 0.0.0.0:8080
  raw_body: true
  max_request_size: 10
```

`max_request_size` is a separate ceiling from `MAX_BODY_SIZE` (Kinetis's
own env var, default 2 MiB) — the two don't automatically agree; set
`MAX_BODY_SIZE=10485760` alongside `max_request_size: 10` above if you
want one consistent limit.

Requires PHP 8.4+ and `kinetis/framework`. See
[Runtime Adapters](https://kinetis.dev/docs/runtime-adapters.html) for
the full reasoning — why `raw_body: true` and `max_request_size` matter,
what's mapped and how, and the two disclosed, environment-caused
conformance gaps (a purely-numeric header name; occasional cookie
reordering) that are upstream RoadRunner behavior, not something this
package's own code can recover from.

## License

MIT — see [LICENSE](LICENSE).
