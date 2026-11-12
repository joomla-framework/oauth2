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
 * Tests the request methods of \Joomla\OAuth2\Client::query().
 */
class ClientQueryMethodTest extends TestCase
{
    /**
     * Creates a client with a valid token.
     *
     * @param   Http  $http  The HTTP client
     *
     * @return  Client
     */
    private function createClient(Http $http): Client
    {
        $client = new Client([], $http, new Input([]));
        $client->setToken(['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);

        return $client;
    }

    /**
     * Method names in any case.
     *
     * @return  \Generator
     */
    public static function methods(): \Generator
    {
        foreach (['GET' => 'get', 'Head' => 'head', 'TRACE' => 'trace', 'POST' => 'post', 'Put' => 'put', 'PATCH' => 'patch', 'DELETE' => 'delete'] as $name => $method) {
            yield $name => [$name, $method];
        }
    }

    /**
     * The method name is case-insensitive.
     *
     * @param   string  $name    The method name passed to query()
     * @param   string  $method  The method expected to be called on the HTTP client
     */
    #[DataProvider('methods')]
    public function testMethodIsCaseInsensitive(string $name, string $method)
    {
        $http = $this->createMock(Http::class);
        $http->expects($this->once())->method($method)->willReturn(new Response('data://text/plain,ok', 200));

        $this->assertSame(200, $this->createClient($http)->query('https://provider.test/api', null, [], $name)->getStatusCode());
    }

    /**
     * The data of a DELETE request is sent.
     */
    public function testDeleteSendsData()
    {
        $http = $this->createMock(Http::class);
        $http->expects($this->once())
            ->method('delete')
            ->with($this->anything(), ['Authorization' => 'Bearer token'], 30, ['id' => 5])
            ->willReturn(new Response('data://text/plain,ok', 200));

        $this->createClient($http)->query('https://provider.test/api', ['id' => 5], [], 'delete', 30);
    }

    /**
     * An unknown method is still rejected.
     */
    public function testUnknownMethodThrows()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createClient($this->createStub(Http::class))->query('https://provider.test/api', null, [], 'CONNECT');
    }
}
