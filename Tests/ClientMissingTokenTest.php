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
 * Tests \Joomla\OAuth2\Client with a missing token, refresh token or creation time.
 */
class ClientMissingTokenTest extends TestCase
{
    /**
     * The HTTP client.
     *
     * @var  Http
     */
    private Http $http;

    /**
     * Creates a client.
     *
     * @param   array  $options  Additional client options
     *
     * @return  Client
     */
    private function createClient(array $options = []): Client
    {
        $this->http = $this->createMock(Http::class);

        return new Client(
            $options + [
                'clientid'     => 'client',
                'clientsecret' => 'secret',
                'tokenurl'     => 'https://provider.test/token',
            ],
            $this->http,
            new Input([])
        );
    }

    /**
     * Runs a callable and fails on any PHP warning or notice it raises.
     *
     * @param   callable  $callable  The callable
     *
     * @return  mixed  The return value of the callable
     */
    private function withoutWarnings(callable $callable)
    {
        set_error_handler(
            function (int $errno, string $errstr) {
                throw new \ErrorException($errstr, 0, $errno);
            }
        );

        try {
            return $callable();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * query() without a token throws a RuntimeException instead of a TypeError, and sends no request.
     */
    public function testQueryWithoutTokenThrows()
    {
        $client = $this->createClient();
        $this->http->expects($this->never())->method('get');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No access token is available.');

        $client->query('https://provider.test/api');
    }

    /**
     * query() with an expired token without refresh token reports the missing refresh token without a warning.
     */
    public function testQueryWithExpiredTokenWithoutRefreshToken()
    {
        $client = $this->createClient(['userefresh' => true]);
        $client->setToken(['access_token' => 'token', 'created' => time() - 7200, 'expires_in' => 3600]);
        $this->http->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No refresh token is available.');

        $this->withoutWarnings(fn () => $client->query('https://provider.test/api'));
    }

    /**
     * refreshToken() without any token throws a RuntimeException instead of a TypeError.
     */
    public function testRefreshTokenWithoutTokenThrows()
    {
        $client = $this->createClient(['userefresh' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No refresh token is available.');

        $client->refreshToken();
    }

    /**
     * A token without creation time is not known to expire: no warning, and it is used.
     */
    public function testTokenWithoutCreatedIsUsed()
    {
        $client = $this->createClient();
        $client->setToken(['access_token' => 'token', 'expires_in' => 3600]);

        $this->assertTrue($this->withoutWarnings(fn () => $client->isAuthenticated()));

        $this->http->expects($this->once())
            ->method('get')
            ->with($this->anything(), ['Authorization' => 'Bearer token'])
            ->willReturn(new Response('data://text/plain,ok', 200));

        $this->assertSame(200, $this->withoutWarnings(fn () => $client->query('https://provider.test/api'))->getStatusCode());
    }

    /**
     * Without a token, isAuthenticated() is false.
     */
    public function testIsAuthenticatedWithoutToken()
    {
        $this->assertFalse($this->createClient()->isAuthenticated());
    }
}
