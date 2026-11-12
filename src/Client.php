<?php

/**
 * Part of the Joomla Framework OAuth2 Package
 *
 * @copyright  Copyright (C) 2005 - 2021 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2;

use Joomla\Application\SessionAwareWebApplicationInterface;
use Joomla\Application\WebApplicationInterface;
use Joomla\Http\Exception\UnexpectedResponseException;
use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use Joomla\Http\Response;
use Joomla\Input\Input;
use Joomla\Session\SessionInterface;
use Joomla\Uri\Uri;

/**
 * Joomla Framework class for interacting with an OAuth 2.0 server.
 *
 * @since  1.0
 */
class Client
{
    /**
     * Options for the Client object.
     *
     * @var    array|\ArrayAccess
     * @since  1.0
     */
    protected $options;

    /**
     * The HTTP client object to use in sending HTTP requests.
     *
     * @var    Http
     * @since  1.0
     */
    protected $http;

    /**
     * The input object to use in retrieving GET/POST data.
     *
     * @var    Input
     * @since  1.0
     */
    protected $input;

    /**
     * The application object to send HTTP headers for redirects.
     *
     * @var    WebApplicationInterface
     * @since  1.0
     */
    protected $application;

    /**
     * Constructor.
     *
     * @param   array|\ArrayAccess        $options      OAuth2 Client options object
     * @param   ?Http                     $http         The HTTP client object
     * @param   ?Input                    $input        The input object
     * @param   ?WebApplicationInterface  $application  The application object
     *
     * @since   1.0
     */
    public function __construct($options = [], ?Http $http = null, ?Input $input = null, ?WebApplicationInterface $application = null)
    {
        if (!\is_array($options) && !($options instanceof \ArrayAccess)) {
            throw new \InvalidArgumentException(
                'The options param must be an array or implement the ArrayAccess interface.'
            );
        }

        $this->options     = $options;
        $this->http        = $http ?: (new HttpFactory())->getHttp($this->options);
        $this->input       = $input ?: ($application ? $application->getInput() : new Input());
        $this->application = $application;
    }

    /**
     * Tests if given response contains JSON header
     *
     * @param Response  $response The response object
     *
     * @return  boolean
     *
     */
    protected function isJsonResponse(Response $response)
    {
        foreach ($response->getHeader('Content-Type') as $type) {
            if (str_starts_with($type, 'application/json')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the access token or redirect to the authentication URL.
     *
     * On the callback from the authorization server, the returned state is verified first (see verifyState()), and an
     * error returned by the authorization server is thrown as a \RuntimeException.
     *
     * @return  array|boolean  The access token or false on failure
     *
     * @since   1.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     */
    public function authenticate()
    {
        $dataCode = $this->input->get('code', false, 'raw');
        $error    = $this->input->get('error', false, 'raw');

        if ($dataCode || $error) {
            $this->verifyState();
        }

        if ($error) {
            $description = $this->input->get('error_description', '', 'raw');

            throw new \RuntimeException(
                \sprintf(
                    'The authorization server returned the error "%s"%s.',
                    $error,
                    \is_string($description) && $description !== '' ? ': ' . $description : ''
                )
            );
        }

        if ($dataCode) {
            $data = [
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->getOption('redirecturi'),
                'client_id'     => $this->getOption('clientid'),
                'client_secret' => $this->getOption('clientsecret'),
                'code'          => $dataCode,
            ] + $this->getTokenRequestParameters();

            $response = $this->http->post($this->getOption('tokenurl'), $data);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
                throw new UnexpectedResponseException(
                    $response,
                    sprintf(
                        'Error code %s received requesting access token: %s.',
                        $response->getStatusCode(),
                        (string) $response->getBody()
                    )
                );
            }

            if ($this->isJsonResponse($response)) {
                $token = array_merge(json_decode((string) $response->getBody(), true), ['created' => time()]);
            } else {
                parse_str((string) $response->getBody(), $token);
                $token = array_merge($token, ['created' => time()]);
            }

            $this->setToken($token);

            return $token;
        }

        if ($this->getOption('sendheaders')) {
            if (!($this->application instanceof WebApplicationInterface)) {
                throw new \RuntimeException(
                    \sprintf('A "%s" implementation is required to process authentication.', WebApplicationInterface::class)
                );
            }

            $this->application->redirect($this->createUrl());
        }

        return false;
    }

    /**
     * Verify if the client has been authenticated
     *
     * @return  boolean  Is authenticated
     *
     * @since   1.0
     */
    public function isAuthenticated()
    {
        $token = $this->getToken();

        if (!$token || !array_key_exists('access_token', $token)) {
            return false;
        }

        if (array_key_exists('expires_in', $token) && $token['created'] + $token['expires_in'] < time() + 20) {
            return false;
        }

        return true;
    }

    /**
     * Create the URL for authentication.
     *
     * @return  string
     *
     * @since   1.0
     * @throws  \InvalidArgumentException
     */
    public function createUrl()
    {
        if (!$this->getOption('authurl') || !$this->getOption('clientid')) {
            throw new \InvalidArgumentException('Authorization URL and client_id are required');
        }

        $params = [
            'response_type' => 'code',
            'client_id'     => $this->getOption('clientid'),
        ];

        $redirect = $this->getOption('redirecturi');

        if ($redirect) {
            $params['redirect_uri'] = $redirect;
        }

        $scope = $this->getOption('scope');

        if ($scope) {
            $params['scope'] = \is_array($scope) ? implode(' ', $scope) : $scope;
        }

        $state = $this->createState();

        if ($state) {
            $params['state'] = $state;
        }

        if ($this->getOption('pkce')) {
            $params['code_challenge']        = $this->createCodeChallenge();
            $params['code_challenge_method'] = 'S256';
        }

        if (\is_array($this->getOption('requestparams'))) {
            foreach ($this->getOption('requestparams') as $key => $value) {
                $params[$key] = $value;
            }
        }

        // The parameters are added to the query as application/x-www-form-urlencoded (RFC 6749, Appendix B).
        $url       = $this->getOption('authurl');
        $separator = '?';

        if (str_contains($url, '?')) {
            $separator = str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&';
        }

        return $url . $separator . http_build_query($params, '', '&');
    }

    /**
     * Verify the state returned by the authorization server.
     *
     * Unless the "validatestate" option is false, the returned state must match the state of the authorization
     * request. If the application provides a session, that is the state createUrl() stored in it; it is removed from
     * the session by this check. Without a session, it is the "state" option, if one is set.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     * @throws  \RuntimeException  If the state does not match.
     */
    protected function verifyState(): void
    {
        if (!$this->getOption('validatestate', true)) {
            return;
        }

        $session = $this->getSession();

        if ($session !== null) {
            $key      = $this->getSessionKey('state');
            $expected = $session->get($key);
            $session->remove($key);
        } else {
            $expected = $this->getOption('state');

            if (!$expected) {
                return;
            }
        }

        $state = $this->input->get('state', '', 'raw');

        if (!\is_string($expected) || $expected === '' || !\is_string($state) || !hash_equals($expected, $state)) {
            throw new \RuntimeException('The state returned by the authorization server does not match the authorization request.');
        }
    }

    /**
     * Get the session used to store the state and the PKCE code verifier between the authorization request and the
     * callback.
     *
     * @return  SessionInterface|null  The session, or null if the application does not provide one
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getSession(): ?SessionInterface
    {
        if (!$this->application instanceof SessionAwareWebApplicationInterface) {
            return null;
        }

        try {
            return $this->application->getSession();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get the name of a session variable of this client.
     *
     * @param   string  $name  The name of the variable
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getSessionKey(string $name): string
    {
        return $this->getOption('sessionnamespace', 'oauth2') . '.' . $name;
    }

    /**
     * Get the state for the authorization request and store it in the session.
     *
     * @return  string|null  The configured state, a generated one, or null if no state is sent
     *
     * @since   __DEPLOY_VERSION__
     */
    private function createState(): ?string
    {
        $state   = $this->getOption('state');
        $session = $this->getOption('validatestate', true) ? $this->getSession() : null;

        if ($session === null) {
            return $state ? (string) $state : null;
        }

        if (!$state) {
            $state = bin2hex(random_bytes(16));
        }

        $session->set($this->getSessionKey('state'), (string) $state);

        return (string) $state;
    }

    /**
     * Create the PKCE code challenge (RFC 7636) for the authorization request.
     *
     * The code verifier is taken from the "codeverifier" option or generated and stored in the session.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     * @throws  \RuntimeException  If no code verifier is configured and there is no session to store one.
     */
    private function createCodeChallenge(): string
    {
        $verifier = $this->getOption('codeverifier');

        if (!$verifier) {
            $session = $this->getSession();

            if ($session === null) {
                throw new \RuntimeException(
                    'PKCE needs either the "codeverifier" option or a session to store the code verifier in.'
                );
            }

            // 32 random bytes give a 43 character verifier (RFC 7636, section 4.1).
            $verifier = $this->base64UrlEncode(random_bytes(32));
            $session->set($this->getSessionKey('codeverifier'), $verifier);
        }

        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }

    /**
     * Get the additional parameters of the token request for the authorization code.
     *
     * These are the PKCE code verifier and the "tokenparams" option.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     * @throws  \RuntimeException  If PKCE is enabled and no code verifier is available.
     */
    private function getTokenRequestParameters(): array
    {
        $params = [];

        if ($this->getOption('pkce')) {
            $verifier = $this->getOption('codeverifier');

            if (!$verifier) {
                $session = $this->getSession();

                if ($session !== null) {
                    $key      = $this->getSessionKey('codeverifier');
                    $verifier = $session->get($key);
                    $session->remove($key);
                }
            }

            if (!\is_string($verifier) || $verifier === '') {
                throw new \RuntimeException('PKCE is enabled, but no code verifier is available for the token request.');
            }

            $params['code_verifier'] = $verifier;
        }

        if (\is_array($this->getOption('tokenparams'))) {
            $params += $this->getOption('tokenparams');
        }

        return $params;
    }

    /**
     * Encode data with the URL-safe base64 alphabet and without padding.
     *
     * @param   string  $data  The data to encode
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Send a signed OAuth request.
     *
     * @param   string   $url      The URL for the request
     * @param   mixed    $data     Either an associative array or a string to be sent with the request
     * @param   array    $headers  The headers to send with the request
     * @param   string   $method   The method with which to send the request
     * @param   integer  $timeout  The timeout for the request
     *
     * @return  \Joomla\Http\Response
     *
     * @since   1.0
     * @throws  \InvalidArgumentException
     * @throws  \RuntimeException
     */
    public function query($url, $data = null, $headers = [], $method = 'get', $timeout = null)
    {
        $token = $this->getToken();

        if (array_key_exists('expires_in', $token) && $token['created'] + $token['expires_in'] < time() + 20) {
            if (!$this->getOption('userefresh')) {
                return false;
            }

            $token = $this->refreshToken($token['refresh_token']);
        }

        $url = new Uri($url);

        if (!$this->getOption('authmethod') || $this->getOption('authmethod') == 'bearer') {
            $headers['Authorization'] = 'Bearer ' . $token['access_token'];
        } elseif ($this->getOption('authmethod') == 'get') {
            $url->setVar($this->getOption('getparam', 'access_token'), $token['access_token']);
        }

        switch ($method) {
            case 'head':
            case 'get':
            case 'delete':
            case 'trace':
                $response = $this->http->$method($url, $headers, $timeout);

                break;

            case 'post':
            case 'put':
            case 'patch':
                $response = $this->http->$method($url, $data, $headers, $timeout);

                break;

            default:
                throw new \InvalidArgumentException('Unknown HTTP request method: ' . $method . '.');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            throw new UnexpectedResponseException(
                $response,
                sprintf(
                    'Error code %s received requesting data: %s.',
                    $response->getStatusCode(),
                    $response->getBody()
                )
            );
        }

        return $response;
    }

    /**
     * Get an option from the OAuth2 Client instance.
     *
     * @param   string  $key      The name of the option to get
     * @param   mixed   $default  Optional default value, returned if the requested option does not exist.
     *
     * @return  mixed  The option value
     *
     * @since   1.0
     */
    public function getOption($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option for the OAuth2 Client instance.
     *
     * @param   string  $key    The name of the option to set
     * @param   mixed   $value  The option value to set
     *
     * @return  Client  This object for method chaining
     *
     * @since   1.0
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Get the access token from the Client instance.
     *
     * @return  array  The access token
     *
     * @since   1.0
     */
    public function getToken()
    {
        return $this->getOption('accesstoken');
    }

    /**
     * Set an option for the Client instance.
     *
     * @param   array  $value  The access token
     *
     * @return  Client  This object for method chaining
     *
     * @since   1.0
     */
    public function setToken(array $value)
    {
        if (!array_key_exists('expires_in', $value) && array_key_exists('expires', $value)) {
            $value['expires_in'] = $value['expires'];
            unset($value['expires']);
        }

        $this->setOption('accesstoken', $value);

        return $this;
    }

    /**
     * Refresh the access token instance.
     *
     * @param   string  $token  The refresh token
     *
     * @return  array  The new access token
     *
     * @since   1.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     */
    public function refreshToken($token = null)
    {
        if (!$this->getOption('userefresh')) {
            throw new \RuntimeException('Refresh token is not supported for this OAuth instance.');
        }

        if (!$token) {
            $token = $this->getToken();

            if (!array_key_exists('refresh_token', $token)) {
                throw new \RuntimeException('No refresh token is available.');
            }

            $token = $token['refresh_token'];
        }

        $data = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token,
            'client_id'     => $this->getOption('clientid'),
            'client_secret' => $this->getOption('clientsecret'),
        ];

        $response = $this->http->post($this->getOption('tokenurl'), $data);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            throw new UnexpectedResponseException(
                $response,
                sprintf(
                    'Error code %s received refreshing token: %s.',
                    $response->getStatusCode(),
                    (string) $response->getBody()
                )
            );
        }

        if ($this->isJsonResponse($response)) {
            $token = array_merge(json_decode((string) $response->getBody(), true), ['created' => time()]);
        } else {
            parse_str((string) $response->getBody(), $token);
            $token = array_merge($token, ['created' => time()]);
        }

        $this->setToken($token);

        return $token;
    }
}
