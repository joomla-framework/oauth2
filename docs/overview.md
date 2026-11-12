# OAuth2 package

TODO

## Expired tokens

Once the stored token has expired, `query()` refreshes it if the `userefresh` option is set. Otherwise it throws a
`Joomla\OAuth2\Exception\ExpiredTokenException`; authenticate again in that case.
