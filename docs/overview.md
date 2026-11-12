# OAuth2 package

TODO

## Authorization request and callback

`createUrl()` builds the authorization URL from the `authurl`, `clientid`, `redirecturi`, `scope`, `state` and
`requestparams` options. The parameters are appended to the query of `authurl` (an existing query is kept as it is)
and encoded once as `application/x-www-form-urlencoded`, as RFC 6749, Appendix B requires, so values containing `&`,
`=`, `+` or spaces arrive unchanged.

### State

The `state` parameter protects the callback against cross-site request forgery: a code is only exchanged for a token
if the callback returns the state of the authorization request.

* If the application implements `Joomla\Application\SessionAwareWebApplicationInterface` and has a session,
  `createUrl()` generates a random state (unless the `state` option is set) and stores it in the session.
  `authenticate()` compares the returned state with it before the code is exchanged and removes it from the session.
  A callback without a stored state is rejected.
* Without a session, the returned state is compared with the `state` option, if it is set on the callback.
* A mismatch throws a `RuntimeException`, and no token request is sent.

The session variables are named `<sessionnamespace>.state` and `<sessionnamespace>.codeverifier`; the
`sessionnamespace` option defaults to `oauth2`. Give each client its own namespace if several share a session.
Set the `validatestate` option to `false` if you generate and verify the state yourself.

### Errors returned by the authorization server

If the callback carries an `error` parameter (for example `access_denied` when the user declines), `authenticate()`
throws a `RuntimeException` with the error and its `error_description` after verifying the state, instead of
redirecting to the authorization server again.

### PKCE

Set the `pkce` option to `true` to use Proof Key for Code Exchange (RFC 7636). `createUrl()` then generates a code
verifier, stores it in the session and sends its `S256` challenge; `authenticate()` sends the verifier with the token
request and removes it from the session. Without a session, set the verifier yourself with the `codeverifier` option
(43 to 128 characters) on both requests.

```php
$client->setOption('pkce', true);

// Redirect to the provider
$application->redirect($client->createUrl());

// On the callback
$token = $client->authenticate();
```

### Additional token request parameters

The `tokenparams` option takes an array of additional parameters for the token request that exchanges the code
(for example `resource` or `audience`). They do not replace the standard parameters.
