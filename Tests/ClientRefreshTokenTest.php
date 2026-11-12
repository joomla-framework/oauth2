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
use PHPUnit\Framework\TestCase;

/**
 * Tests the handling of the refresh token in \Joomla\OAuth2\Client::refreshToken().
 */
class ClientRefreshTokenTest extends TestCase
{
    /**
     * The data of the token requests sent.
     *
     * @var  array
     */
    private array $tokenRequests = [];

    /**
     * Creates a client whose token endpoint answers every request with the given JSON body.
     *
     * @param   string  $body  The JSON response body
     *
     * @return  Client
     */
    private function createClient(string $body): Client
    {
        $http = $this->createStub(Http::class);
        $http->method('post')->willReturnCallback(
            function ($url, $data) use ($body) {
                $this->tokenRequests[] = $data;

                return new Response('data://text/plain,' . $body, 200, ['Content-Type' => 'application/json']);
            }
        );
        $http->method('get')->willReturn(new Response('data://text/plain,ok', 200));

        $client = new Client(
            [
                'clientid'     => 'client',
                'clientsecret' => 'secret',
                'tokenurl'     => 'https://provider.test/token',
                'userefresh'   => true,
            ],
            $http,
            new Input([])
        );

        $client->setToken(['access_token' => 'old', 'refresh_token' => 'refresh', 'created' => time() - 7200, 'expires_in' => 3600]);

        return $client;
    }

    /**
     * The current refresh token is kept when the response does not contain a new one.
     */
    public function testRefreshTokenIsKeptWhenNotRotated()
    {
        $client = $this->createClient('{"access_token":"new","expires_in":3600}');

        $token = $client->refreshToken();

        $this->assertSame('new', $token['access_token']);
        $this->assertSame('refresh', $token['refresh_token']);
        $this->assertSame($token, $client->getToken());
    }

    /**
     * An explicitly passed refresh token is kept as well.
     */
    public function testExplicitRefreshTokenIsKeptWhenNotRotated()
    {
        $client = $this->createClient('{"access_token":"new","expires_in":3600}');

        $this->assertSame('explicit', $client->refreshToken('explicit')['refresh_token']);
    }

    /**
     * A rotated refresh token replaces the current one.
     */
    public function testRotatedRefreshTokenIsUsed()
    {
        $client = $this->createClient('{"access_token":"new","refresh_token":"rotated","expires_in":3600}');

        $this->assertSame('rotated', $client->refreshToken()['refresh_token']);
    }

    /**
     * After query() has refreshed an expired token, the token can be refreshed again.
     */
    public function testTokenCanBeRefreshedAgainAfterQuery()
    {
        $client = $this->createClient('{"access_token":"new","expires_in":3600}');

        $client->query('https://provider.test/api');
        $client->refreshToken();

        $this->assertSame('refresh', $this->tokenRequests[0]['refresh_token']);
        $this->assertSame('refresh', $this->tokenRequests[1]['refresh_token']);
    }
}
