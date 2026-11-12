# OAuth2 package

TODO

## Token responses

`authenticate()` and `refreshToken()` decode the response of the token endpoint as JSON if its `Content-Type` starts
with `application/json`, and as a form-encoded string otherwise. The decoded token gets a `created` timestamp, is
stored with `setToken()` and returned.

A `Joomla\Http\Exception\UnexpectedResponseException` is thrown, and the stored token is kept, if the endpoint

* answers with a status code outside 200–399,
* sends a body that cannot be decoded,
* reports an error, even with status 200 (for example `{"error":"bad_verification_code"}`), or
* sends no `access_token`.
