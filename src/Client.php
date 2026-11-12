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
use Joomla\OAuth2\Exception\ExpiredTokenException;
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
    protected array|\ArrayAccess $options;

    /**
     * The HTTP client object to use in sending HTTP requests.
     *
     * @var    Http
     * @since  1.0
     */
    protected Http $http;

    /**
     * The input object to use in retrieving GET/POST data.
     *
     * @var    Input
     * @since  1.0
     */
    protected Input $input;

    /**
     * The application object to send HTTP headers for redirects.
     *
     * @var    WebApplicationInterface|null
     * @since  1.0
     */
    protected ?WebApplicationInterface $application;

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
    public function __construct(
        array|\ArrayAccess $options = [],
        ?Http $http = null,
        ?Input $input = null,
        ?WebApplicationInterface $application = null
    ) {
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
    protected function isJsonResponse(Response $response): bool
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
     * @return  array|false  The access token or false on failure
     *
     * @since   1.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     */
    public function authenticate(): array|false
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
    public function isAuthenticated(): bool
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
    public function createUrl(): string
    {
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
     * @param   string             $url      The URL for the request
     * @param   array|string|null  $data     Either an associative array or a string to be sent with the request
     * @param   array              $headers  The headers to send with the request
     * @param   string             $method   The method with which to send the request
     * @param   ?integer           $timeout  The timeout for the request
     *
     * @return  Response
     *
     * @since   1.0
     * @throws  ExpiredTokenException  If the token has expired and the "userefresh" option is not set.
     * @throws  \InvalidArgumentException
     * @throws  \RuntimeException
     */
    public function query(string $url, array|string|null $data = null, array $headers = [], string $method = 'get', ?int $timeout = null): Response
    {
        $token = $this->getToken();

        if (array_key_exists('expires_in', $token) && $token['created'] + $token['expires_in'] < time() + 20) {
            if (!$this->getOption('userefresh')) {
                throw new ExpiredTokenException('The access token has expired and refreshing it is not enabled.');
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
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option for the OAuth2 Client instance.
     *
     * @param   string  $key    The name of the option to set
     * @param   mixed   $value  The option value to set
     *
     * @return  $this
     *
     * @since   1.0
     */
    public function setOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Get the access token from the Client instance.
     *
     * @return  array|null  The access token, or null if none has been set
     *
     * @since   1.0
     */
    public function getToken(): ?array
    {
        return $this->getOption('accesstoken');
    }

    /**
     * Set an option for the Client instance.
     *
     * @param   array  $value  The access token
     *
     * @return  $this
     *
     * @since   1.0
     */
    public function setToken(array $value): static
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
     * @param   ?string  $token  The refresh token
     *
     * @return  array  The new access token
     *
     * @since   1.0
     * @throws  UnexpectedResponseException
     * @throws  \RuntimeException
     */
    public function refreshToken(?string $token = null): array
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
