# OAuth2 package

TODO

## Client authentication and HTTPS

The `clientauth` option selects how the client authenticates at the token endpoint (RFC 6749, section 2.3.1):

| Value | Method |
|---|---|
| `post` (default) | `client_id` and `client_secret` in the request body (`client_secret_post`) |
| `basic` | HTTP Basic `Authorization` header with the form-encoded `client_id` and `client_secret` (`client_secret_basic`) |

```php
$client->setOption('clientauth', 'basic');
```

The client secret, the authorization code and the tokens must not travel unencrypted. `createUrl()`, `authenticate()`
and `refreshToken()` raise an `E_USER_WARNING` if `authurl` or `tokenurl` does not use `https://`. Endpoints on
`localhost`, `127.0.0.1` and `::1` are exempt; set the `allowinsecure` option to `true` to silence the warning for
other hosts.
