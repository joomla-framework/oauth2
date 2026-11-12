# OAuth2 package

TODO

## Using the token

`query()` sends a request with the stored access token. Set a token with `authenticate()` or `setToken()` first;
without one, `query()` throws a `RuntimeException`.

A token expires `expires_in` seconds after its `created` timestamp (20 seconds earlier, to allow for delays). Once it
has expired, `isAuthenticated()` returns `false`, and `query()` refreshes it if the `userefresh` option is set, or
returns `false` otherwise. If an expired token has no `refresh_token`, `query()` and `refreshToken()` throw a
`RuntimeException` ("No refresh token is available.").

Keep the `created` field when storing and restoring a token. A token without `created` or `expires_in` cannot be
checked for expiry and is treated as valid; if it has in fact expired, the API answers with an error.
