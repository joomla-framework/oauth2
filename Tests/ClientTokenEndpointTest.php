<?php

/**
 * @copyright  Copyright (C) 2005 - 2026 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2\Tests;

use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Input\Input;
use Joomla\OAuth2\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the client authentication at the token endpoint and the HTTPS check of \Joomla\OAuth2\Client.
 */
class ClientTokenEndpointTest extends TestCase
{
    /**
     * The token requests sent, as [url, data, headers].
     *
     * @var  array
     */
    private array $requests = [];

    /**
     * The warnings raised.
     *
     * @var  string[]
     */
    private array $warnings = [];

    /**
     * Creates a client.
     *
     * @param   array  $options  Additional client options
     * @param   array  $input    The request data
     *
     * @return  Client
     */
    private function createClient(array $options = [], array $input = ['code' => 'abc']): Client
    {
        $http = $this->createStub(Http::class);
        $http->method('post')->willReturnCallback(
            function ($url, $data, array $headers = []) {
                $this->requests[] = [$url, $data, $headers];

                return new Response('data://text/plain,access_token=token&refresh_token=refresh', 200);
            }
        );

        return new Client(
            $options + [
                'clientid'     => 'client:id',
                'clientsecret' => 'sec ret/+',
                'authurl'      => 'https://provider.test/authorize',
                'tokenurl'     => 'https://provider.test/token',
                'redirecturi'  => 'https://example.com/callback',
                'userefresh'   => true,
            ],
            $http,
            new Input($input)
        );
    }

    /**
     * Runs a callable and records the warnings it raises.
     *
     * @param   callable  $callable  The callable
     *
     * @return  mixed  The return value of the callable
     */
    private function recordWarnings(callable $callable)
    {
        set_error_handler(
            function (int $errno, string $errstr) {
                $this->warnings[] = $errstr;

                return true;
            },
            E_USER_WARNING
        );

        try {
            return $callable();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * By default, the client credentials are sent in the request body.
     */
    public function testCredentialsAreSentInBodyByDefault()
    {
        $this->createClient()->authenticate();

        $this->assertSame('client:id', $this->requests[0][1]['client_id']);
        $this->assertSame('sec ret/+', $this->requests[0][1]['client_secret']);
        $this->assertSame([], $this->requests[0][2]);
    }

    /**
     * With "clientauth" set to "basic", the form-encoded credentials are sent in an Authorization header, for the
     * code exchange and the refresh.
     */
    public function testCredentialsAreSentWithBasicAuthentication()
    {
        $client = $this->createClient(['clientauth' => 'basic']);
        $client->authenticate();
        $client->refreshToken();

        $expected = ['Authorization' => 'Basic ' . base64_encode('client%3Aid:sec+ret%2F%2B')];

        foreach ($this->requests as [$url, $data, $headers]) {
            $this->assertSame($expected, $headers);
            $this->assertArrayNotHasKey('client_id', $data);
            $this->assertArrayNotHasKey('client_secret', $data);
        }

        $this->assertSame('abc', $this->requests[0][1]['code']);
        $this->assertSame('refresh', $this->requests[1][1]['refresh_token']);
    }

    /**
     * An unknown client authentication method is rejected.
     */
    public function testUnknownClientAuthenticationThrows()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createClient(['clientauth' => 'jwt'])->authenticate();
    }

    /**
     * Endpoints that warn.
     *
     * @return  \Generator
     */
    public static function insecureEndpoints(): \Generator
    {
        yield 'http token endpoint' => [['tokenurl' => 'http://provider.test/token'], 'token endpoint "http://provider.test/token"'];
        yield 'http authorization endpoint' => [['authurl' => 'http://provider.test/authorize'], 'authorization endpoint "http://provider.test/authorize"'];
    }

    /**
     * A non-HTTPS endpoint raises a warning.
     *
     * @param   array   $options  The endpoint options
     * @param   string  $message  Expected part of the warning
     */
    #[DataProvider('insecureEndpoints')]
    public function testInsecureEndpointWarns(array $options, string $message)
    {
        $client = $this->createClient($options);

        $this->recordWarnings(
            function () use ($client) {
                $client->createUrl();
                $client->authenticate();
            }
        );

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString($message, $this->warnings[0]);
        $this->assertStringContainsString('does not use HTTPS', $this->warnings[0]);
    }

    /**
     * Endpoints that do not warn.
     *
     * @return  \Generator
     */
    public static function acceptedEndpoints(): \Generator
    {
        yield 'https' => [['tokenurl' => 'HTTPS://provider.test/token']];
        yield 'localhost' => [['tokenurl' => 'http://localhost:8080/token', 'authurl' => 'http://localhost:8080/authorize']];
        yield 'loopback IPv4' => [['tokenurl' => 'http://127.0.0.1/token']];
        yield 'loopback IPv6' => [['tokenurl' => 'http://[::1]/token']];
        yield 'allowinsecure' => [['tokenurl' => 'http://provider.test/token', 'allowinsecure' => true]];
    }

    /**
     * HTTPS, local endpoints and the "allowinsecure" option do not raise a warning.
     *
     * @param   array  $options  The endpoint options
     */
    #[DataProvider('acceptedEndpoints')]
    public function testAcceptedEndpointDoesNotWarn(array $options)
    {
        $client = $this->createClient($options);

        $this->recordWarnings(
            function () use ($client) {
                $client->createUrl();
                $client->authenticate();
            }
        );

        $this->assertSame([], $this->warnings);
    }
}
