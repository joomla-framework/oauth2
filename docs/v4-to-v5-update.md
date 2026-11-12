## Updating from v4 to v5

### Native types in `Joomla\OAuth2\Client`

The public and protected API of `Client` now has native parameter, return and property types. The values accepted
and returned are the same as documented in 4.x, except where noted below.

| Member | 5.0 signature |
|---|---|
| `__construct()` | `__construct(array\|\ArrayAccess $options = [], ?Http $http = null, ?Input $input = null, ?WebApplicationInterface $application = null)` |
| `authenticate()` | `authenticate(): array\|false` |
| `isAuthenticated()` | `isAuthenticated(): bool` |
| `createUrl()` | `createUrl(): string` |
| `query()` | `query(string $url, array\|string\|null $data = null, array $headers = [], string $method = 'get', ?int $timeout = null): Response` |
| `getOption()` | `getOption(string $key, mixed $default = null): mixed` |
| `setOption()` | `setOption(string $key, mixed $value): static` |
| `getToken()` | `getToken(): ?array` |
| `setToken()` | `setToken(array $value): static` |
| `refreshToken()` | `refreshToken(?string $token = null): array` |
| `isJsonResponse()` (protected) | `isJsonResponse(Response $response): bool` |
| `$options`, `$http`, `$input`, `$application` (protected) | `array\|\ArrayAccess`, `Http`, `Input`, `?WebApplicationInterface` |

Classes extending `Client` must use compatible signatures when they override these methods, and must not redeclare
the properties without their types.

Passing options that are neither an array nor `ArrayAccess` to the constructor now throws a `TypeError` instead of an
`InvalidArgumentException`.

### `query()` throws `ExpiredTokenException` instead of returning `false`

When the stored token has expired and the `userefresh` option is not set, `query()` returned `false`, so its result
could be a response or `false`. It now throws `Joomla\OAuth2\Exception\ExpiredTokenException` (a
`RuntimeException`) and always returns a `Joomla\Http\Response`.

```php
// v4
$response = $client->query($url);

if ($response === false) {
    // re-authenticate
}

// v5
try {
    $response = $client->query($url);
} catch (ExpiredTokenException $e) {
    // re-authenticate
}
```
