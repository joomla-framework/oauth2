# OAuth2 package

TODO

## Sending requests

`query($url, $data, $headers, $method, $timeout)` sends a request with the access token. `$method` is one of `get`,
`head`, `trace`, `delete`, `post`, `put` and `patch`, in any case (`'GET'` works as well); anything else throws an
`InvalidArgumentException`. `$data` is sent with `post`, `put`, `patch` and `delete` requests.
