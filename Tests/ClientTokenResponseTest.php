<?php

/**
 * @copyright  Copyright (C) 2005 - 2026 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2\Tests;

use Joomla\Http\Exception\UnexpectedResponseException;
use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Input\Input;
use Joomla\OAuth2\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the validation of the token endpoint's responses in \Joomla\OAuth2\Client.
 */
class ClientTokenResponseTest extends TestCase
{
    /**
     * The token set before the request.
     *
     * @var  array
     */
    private const PREVIOUS_TOKEN = ['access_token' => 'previous', 'refresh_token' => 'refresh', 'created' => 1, 'expires_in' => 3600];

    /**
     * Creates a client whose token endpoint answers with the given response.
     *
     * @param   Response  $response  The response of the token endpoint
     *
     * @return  Client
     */
    private function createClient(Response $response): Client
    {
        $http = $this->createStub(Http::class);
        $http->method('post')->willReturn($response);

        $client = new Client(
            [
                'clientid'     => 'client',
                'clientsecret' => 'secret',
                'tokenurl'     => 'https://provider.test/token',
                'userefresh'   => true,
            ],
            $http,
            new Input(['code' => 'abc'])
        );

        $client->setToken(self::PREVIOUS_TOKEN);

        return $client;
    }

    /**
     * Invalid token responses.
     *
     * @return  \Generator
     */
    public static function invalidResponses(): \Generator
    {
        yield 'invalid JSON' => ['not json', 'application/json', 'could not be decoded'];
        yield 'empty JSON body' => ['', 'application/json', 'could not be decoded'];
        yield 'JSON error' => ['{"error":"bad_verification_code","error_description":"The code is wrong."}', 'application/json', 'returned the error "bad_verification_code": The code is wrong.'];
        yield 'form-encoded error' => ['error=invalid_grant', 'application/x-www-form-urlencoded', 'returned the error "invalid_grant"'];
        yield 'JSON without access token' => ['{"token_type":"bearer"}', 'application/json', 'contains no access token'];
        yield 'empty form-encoded body' => ['', 'text/plain', 'contains no access token'];
    }

    /**
     * authenticate() throws for an invalid token response and keeps the previous token.
     *
     * @param   string  $body         The response body
     * @param   string  $contentType  The response content type
     * @param   string  $message      Expected part of the exception message
     */
    #[DataProvider('invalidResponses')]
    public function testAuthenticateRejectsInvalidResponse(string $body, string $contentType, string $message)
    {
        $client = $this->createClient(new Response('data://text/plain,' . rawurlencode($body), 200, ['Content-Type' => $contentType]));

        try {
            $client->authenticate();
            $this->fail('An invalid token response must be rejected.');
        } catch (UnexpectedResponseException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(self::PREVIOUS_TOKEN, $client->getToken());
    }

    /**
     * refreshToken() throws for an invalid token response and keeps the previous token.
     *
     * @param   string  $body         The response body
     * @param   string  $contentType  The response content type
     * @param   string  $message      Expected part of the exception message
     */
    #[DataProvider('invalidResponses')]
    public function testRefreshTokenRejectsInvalidResponse(string $body, string $contentType, string $message)
    {
        $client = $this->createClient(new Response('data://text/plain,' . rawurlencode($body), 200, ['Content-Type' => $contentType]));

        try {
            $client->refreshToken();
            $this->fail('An invalid token response must be rejected.');
        } catch (UnexpectedResponseException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(self::PREVIOUS_TOKEN, $client->getToken());
    }

    /**
     * A valid JSON response with a charset is still accepted.
     */
    public function testValidResponseIsAccepted()
    {
        $client = $this->createClient(
            new Response('data://text/plain,{"access_token":"new","expires_in":60}', 200, ['Content-Type' => 'application/json; charset=utf-8'])
        );

        $token = $client->authenticate();

        $this->assertSame('new', $token['access_token']);
        $this->assertSame(60, $token['expires_in']);
        $this->assertSame($token, $client->getToken());
    }
}
