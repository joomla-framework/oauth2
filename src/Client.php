<?php

/**
 * Part of the Joomla Framework OAuth2 Package
 *
 * @copyright  Copyright (C) 2005 - 2021 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2;

use Joomla\Application\WebApplicationInterface;
use Joomla\Http\Exception\UnexpectedResponseException;
use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use Joomla\Http\Response;
use Joomla\Input\Input;
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
     * @return  array|boolean  The access token or false on failure
     *
     * @since   1.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     */
    public function authenticate()
    {
        $dataCode = $this->input->get('code', false, 'raw');

        if ($dataCode) {
            $data = [
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->getOption('redirecturi'),
                'client_id'     => $this->getOption('clientid'),
                'client_secret' => $this->getOption('clientsecret'),
                'code'          => $dataCode,
            ];

            $response = $this->sendTokenRequest($data);

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
        $this->checkEndpoint($this->getOption('authurl'), 'authorization endpoint');

        if (!$this->getOption('authurl') || !$this->getOption('clientid')) {
            throw new \InvalidArgumentException('Authorization URL and client_id are required');
        }

        $url = new Uri($this->getOption('authurl'));
        $url->setVar('response_type', 'code');
        $url->setVar('client_id', urlencode($this->getOption('clientid')));

        $redirect = $this->getOption('redirecturi');

        if ($redirect) {
            $url->setVar('redirect_uri', urlencode($redirect));
        }

        $scope = $this->getOption('scope');

        if ($scope) {
            $scope = \is_array($scope) ? implode(' ', $scope) : $scope;
            $url->setVar('scope', urlencode($scope));
        }

        $state = $this->getOption('state');

        if ($state) {
            $url->setVar('state', urlencode($state));
        }

        if (\is_array($this->getOption('requestparams'))) {
            foreach ($this->getOption('requestparams') as $key => $value) {
                $url->setVar($key, urlencode($value));
            }
        }

        return (string) $url;
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

        $response = $this->sendTokenRequest($data);

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

    /**
     * Send a request to the token endpoint, authenticating the client as the "clientauth" option says.
     *
     * With "clientauth" set to "basic", the client credentials are sent in an HTTP Basic Authorization header
     * (client_secret_basic, RFC 6749, section 2.3.1) instead of the request body (client_secret_post, the default).
     *
     * @param   array  $data  The data of the token request, including client_id and client_secret
     *
     * @return  Response
     *
     * @since   __DEPLOY_VERSION__
     * @throws  \InvalidArgumentException  If the "clientauth" option has an unknown value.
     */
    private function sendTokenRequest(array $data): Response
    {
        $url = $this->getOption('tokenurl');
        $this->checkEndpoint($url, 'token endpoint');

        $headers = [];

        switch ($this->getOption('clientauth', 'post')) {
            case 'post':
                break;

            case 'basic':
                // The credentials are form-encoded before they are joined and base64-encoded (RFC 6749, section 2.3.1).
                $headers['Authorization'] = 'Basic ' . base64_encode(
                    urlencode((string) $data['client_id']) . ':' . urlencode((string) $data['client_secret'])
                );

                unset($data['client_id'], $data['client_secret']);

                break;

            default:
                throw new \InvalidArgumentException(
                    \sprintf('Unknown client authentication method "%s"; use "post" or "basic".', $this->getOption('clientauth'))
                );
        }

        return $this->http->post($url, $data, $headers);
    }

    /**
     * Warn if an endpoint does not use HTTPS.
     *
     * Requests to localhost and the "allowinsecure" option are exempt.
     *
     * @param   mixed   $url   The URL of the endpoint
     * @param   string  $name  The name of the endpoint for the warning
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function checkEndpoint($url, string $name): void
    {
        if ($this->getOption('allowinsecure') || !\is_string($url) || $url === '') {
            return;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
            return;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if (\in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
            return;
        }

        trigger_error(
            \sprintf(
                'The OAuth2 %s "%s" does not use HTTPS, so credentials and tokens are sent unencrypted.'
                . ' Use an https:// URL, or set the "allowinsecure" option if this is intended.',
                $name,
                $url
            ),
            E_USER_WARNING
        );
    }
}
