# Overview

The OAuth2 package is a client for the OAuth 2.0 authorization code flow: it builds the
authorization URL, exchanges the returned code for an access token, and signs subsequent API
requests with it.

```bash
composer require joomla/oauth2
```

The package is a single class, `Joomla\OAuth2\Client`.

## Configuration

Options are passed as an array (or any `ArrayAccess`) to the constructor and can be changed later
with `setOption()`:

| Option | Meaning |
|---|---|
| `clientid` | The client identifier issued by the provider |
| `clientsecret` | The client secret |
| `authurl` | The provider's authorization endpoint |
| `tokenurl` | The provider's token endpoint |
| `redirecturi` | Your callback URL |
| `scope` | String or array of scopes |
| `state` | Value passed through the authorization request |
| `requestparams` | Extra query parameters for the authorization URL |
| `sendheaders` | If truthy, `authenticate()` redirects the browser itself |
| `userefresh` | Allow refreshing an expired token |
| `authmethod` | `bearer` (default) or `get` |
| `getparam` | Query parameter name when `authmethod` is `get` |
| `accesstoken` | The stored token array |

## The flow

```php
use Joomla\OAuth2\Client;

$client = new Client(
    [
        'clientid'     => getenv('OAUTH_CLIENT_ID'),
        'clientsecret' => getenv('OAUTH_CLIENT_SECRET'),
        'authurl'      => 'https://provider.test/oauth/authorize',
        'tokenurl'     => 'https://provider.test/oauth/token',
        'redirecturi'  => 'https://example.com/callback',
        'scope'        => ['read', 'write'],
        'sendheaders'  => true,
    ],
    $http,          // Joomla\Http\Http
    $input,         // Joomla\Input\Input
    $application    // Joomla\Application\WebApplicationInterface
);

// On the callback URL: exchanges ?code=… for a token and returns it.
// Without a code and with sendheaders set, redirects to the provider instead.
$token = $client->authenticate();
```

`createUrl()` builds the authorization URL if you would rather redirect yourself:

```php
$application->redirect($client->createUrl());
```

## Using the token

```php
if (!$client->isAuthenticated()) {
    $application->redirect($client->createUrl());
}

$response = $client->query('https://provider.test/api/me');
$profile  = json_decode((string) $response->getBody());
```

`query()` accepts `$url`, `$data`, `$headers`, `$method` and `$timeout`. The method name must be
lower case — `'get'`, `'post'`, `'put'`, `'patch'`, `'delete'`, `'head'`, `'trace'`; anything else
raises an `InvalidArgumentException`.

## Storing and restoring the token

The token is an array; keep it wherever your session or database lives:

```php
$token = $client->getToken();          // ['access_token' => …, 'expires_in' => …, 'created' => …]
$session->set('oauth.token', $token);

// later
$client->setToken($session->get('oauth.token'));
```

`setToken()` normalises a provider's `expires` key to `expires_in`. Keep the `created` field —
`isAuthenticated()` and `query()` compute expiry from it, and a token restored without it produces
a warning and is treated as expired.

## Refreshing

```php
$client->setOption('userefresh', true);

$token = $client->refreshToken();               // uses the stored refresh_token
$token = $client->refreshToken($refreshToken);  // or an explicit one
```

Without `userefresh`, `refreshToken()` throws a `RuntimeException` and `query()` returns `false`
once the token has expired.

## Things to know before you build on this

**The `state` parameter is sent but never checked.** `createUrl()` includes `state` when you
configure one, but `authenticate()` accepts any `code` from the request without comparing the
returned `state` to what was sent. That check is what prevents an attacker from feeding you an
authorization code for *their* account, so implement it yourself:

```php
// Before redirecting
$state = bin2hex(random_bytes(16));
$session->set('oauth.state', $state);
$client->setOption('state', $state);

// On the callback, before calling authenticate()
$expected = (string) $session->get('oauth.state');
$session->remove('oauth.state');

if ($expected === '' || !hash_equals($expected, (string) $input->get('state', '', 'raw'))) {
    throw new \RuntimeException('Invalid OAuth state');
}
```

**There is no PKCE.** `code_challenge`/`code_verifier` are not implemented. If your provider
supports PKCE — and OAuth 2.1 requires it — add the parameters through `requestparams` and send the
verifier with the token request yourself.

**The JSON content type is matched exactly.** The token response is parsed as JSON only when the
`Content-Type` header equals `application/json`; a provider sending
`application/json; charset=UTF-8` falls through to `parse_str()`, which produces nonsense and an
apparently empty token. If you hit that, normalise the header in a decorator around the HTTP
client, or parse the token response yourself.

**`authmethod = get` puts the token in the URL.** Query strings end up in server logs, browser
history and `Referer` headers. Prefer the default `bearer`.

**Call `authenticate()` or `setToken()` before `query()`.** `query()` reads the stored token
without checking that one exists, so calling it first raises a `TypeError` rather than a clear
error.

## Not part of this package

* Only the authorization code grant. No `client_credentials`, no device flow, no JWT bearer.
* No OpenID Connect — no `id_token` handling, no discovery, no JWKS.
* No token storage abstraction, no token revocation (RFC 7009) or introspection (RFC 7662).
* No provider presets.
