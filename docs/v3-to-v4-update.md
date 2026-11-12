# Updating from v3 to v4

Release 4.0.0 raises the PHP requirement and follows `joomla/http` to PSR-7 responses. The public
signatures of `Client` are unchanged, but what `query()` hands back is a different kind of object.

## At a glance

| | v3 (3.0.0) | v4 (4.0.0) |
|---|---|---|
| PHP | `^8.1.0` | `^8.3.0` |
| Response objects | `Joomla\Http\Response` with public properties | PSR-7 `ResponseInterface` |
| Nullable constructor arguments | implicit | explicit (`?Http`, `?Input`, …) |

## Minimum supported PHP version raised

All Framework packages now require **PHP 8.3** or newer.

## Responses are PSR-7 objects

`joomla/http` 4.0 returns PSR-7 responses, and this package was adapted to them. Internally the
change looks like this:

```php
// v3
$response->code;
$response->body;
$response->headers['Content-Type'];

// v4
$response->getStatusCode();
$response->getBody()->getContents();
$response->getHeader('Content-Type');    // an array of values
```

For your own code this matters at one point: **the return value of `Client::query()`**. It is now a
`Psr\Http\Message\ResponseInterface`:

```php
// v3
$response = $client->query($url);
$data     = json_decode($response->body);
$status   = $response->code;

// v4
$response = $client->query($url);
$data     = json_decode((string) $response->getBody());
$status   = $response->getStatusCode();
```

Prefer `(string) $response->getBody()` over `getBody()->getContents()`: the cast rewinds the
stream, so reading it twice still works.

## Constructor arguments are explicitly nullable

```php
// v3
public function __construct($options = [], Http $http = null, Input $input = null, WebApplicationInterface $application = null)

// v4
public function __construct($options = [], ?Http $http = null, ?Input $input = null, ?WebApplicationInterface $application = null)
```

This silences the PHP 8.4 deprecation for implicitly nullable parameters. Callers are unaffected.

## Dependency changes

| Package | v3 (3.0.0) | v4 (4.0.0) |
|---|---|---|
| `php` | `^8.1.0` | `^8.3.0` |
| `joomla/application` | `^3.0` | `^4.0` |
| `joomla/http` | `^3.0` | `^4.0` |
| `joomla/input` | `^3.0` | `^4.0` |
| `joomla/session` | `^3.0` | `^4.0` |
| `joomla/uri` | `^3.0` | `^4.0` |

## Worth doing while you are here

The `state` parameter is still not validated by this package, and PKCE is still not implemented.
If you have not added those yourself, see
[the overview](overview.md#things-to-know-before-you-build-on-this) — both are a handful of lines
in your callback handler.
