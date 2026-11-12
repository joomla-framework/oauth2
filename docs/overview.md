# OAuth2 package

TODO

## Refreshing the token

With the `userefresh` option set, `refreshToken()` exchanges the refresh token (the stored one, or the one passed as
argument) for a new access token, and `query()` does so automatically once the stored token has expired.

Authorization servers do not have to issue a new refresh token with every refresh (RFC 6749, section 6). If the
response contains none, the refresh token that was used is kept in the new token, so the token can be refreshed again.
