<?php

/**
 * @copyright  Copyright (C) 2005 - 2026 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2\Tests;

use Joomla\Application\SessionAwareWebApplicationInterface;
use Joomla\Application\WebApplicationInterface;
use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Input\Input;
use Joomla\OAuth2\Client;
use Joomla\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the authorization request and the callback handling of \Joomla\OAuth2\Client: state, provider errors, PKCE
 * and the encoding of the authorization URL.
 */
class ClientAuthorizationTest extends TestCase
{
    /**
     * The variables of the fake session.
     *
     * @var  array
     */
    private array $sessionData = [];

    /**
     * The data of the token requests sent.
     *
     * @var  array
     */
    private array $tokenRequests = [];

    /**
     * Creates a session stub backed by $sessionData.
     *
     * @return  SessionInterface
     */
    private function createSession(): SessionInterface
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturnCallback(fn (string $name, $default = null) => $this->sessionData[$name] ?? $default);
        $session->method('set')->willReturnCallback(
            function (string $name, $value = null) {
                $this->sessionData[$name] = $value;

                return null;
            }
        );
        $session->method('remove')->willReturnCallback(
            function (string $name) {
                $value = $this->sessionData[$name] ?? null;
                unset($this->sessionData[$name]);

                return $value;
            }
        );

        return $session;
    }

    /**
     * Creates an HTTP client that records the token requests and answers them with a token.
     *
     * @return  Http
     */
    private function createHttp(): Http
    {
        $http = $this->createStub(Http::class);
        $http->method('post')->willReturnCallback(
            function ($url, $data) {
                $this->tokenRequests[] = $data;

                return new Response(
                    'data://text/plain,{"access_token":"accessvalue","expires_in":3600}',
                    200,
                    ['Content-Type' => 'application/json']
                );
            }
        );

        return $http;
    }

    /**
     * Creates a client.
     *
     * @param   array                         $input        The request data
     * @param   array                         $options      Additional client options
     * @param   ?WebApplicationInterface      $application  The application, by default one with the fake session
     *
     * @return  Client
     */
    private function createClient(array $input = [], array $options = [], ?WebApplicationInterface $application = null): Client
    {
        if ($application === null) {
            $application = $this->createStub(SessionAwareWebApplicationInterface::class);
            $application->method('getSession')->willReturn($this->createSession());
        }

        return new Client(
            $options + [
                'clientid'     => 'client',
                'clientsecret' => 'secret',
                'authurl'      => 'https://provider.test/authorize',
                'tokenurl'     => 'https://provider.test/token',
                'redirecturi'  => 'https://example.com/callback',
            ],
            $this->createHttp(),
            new Input($input),
            $application
        );
    }

    /**
     * Extracts the query variables from a URL.
     *
     * @param   string  $url  The URL
     *
     * @return  array
     */
    private function getQueryVars(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $vars);

        return $vars;
    }

    /**
     * createUrl() generates a random state and stores it in the session.
     */
    public function testCreateUrlGeneratesAndStoresState()
    {
        $vars = $this->getQueryVars($this->createClient()->createUrl());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $vars['state']);
        $this->assertSame($vars['state'], $this->sessionData['oauth2.state']);
    }

    /**
     * A configured state is sent unchanged and stored in the session.
     */
    public function testCreateUrlStoresConfiguredState()
    {
        $vars = $this->getQueryVars($this->createClient([], ['state' => 'configured'])->createUrl());

        $this->assertSame('configured', $vars['state']);
        $this->assertSame('configured', $this->sessionData['oauth2.state']);
    }

    /**
     * A code is exchanged when the returned state matches the stored one, and the state is removed from the session.
     */
    public function testCallbackWithMatchingStateExchangesCode()
    {
        $this->sessionData['oauth2.state'] = 'expected';

        $token = $this->createClient(['code' => 'abc', 'state' => 'expected'])->authenticate();

        $this->assertSame('accessvalue', $token['access_token']);
        $this->assertCount(1, $this->tokenRequests);
        $this->assertArrayNotHasKey('oauth2.state', $this->sessionData);
    }

    /**
     * A code returned with a different state is not exchanged.
     */
    public function testCallbackWithForeignStateIsRejected()
    {
        $this->sessionData['oauth2.state'] = 'expected';
        $client                            = $this->createClient(['code' => 'abc', 'state' => 'attacker']);

        try {
            $client->authenticate();
            $this->fail('A callback with a foreign state must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('state', $e->getMessage());
        }

        $this->assertSame([], $this->tokenRequests);
        $this->assertNull($client->getToken());
        $this->assertArrayNotHasKey('oauth2.state', $this->sessionData);
    }

    /**
     * A code is not exchanged when the session holds no state, i.e. when no authorization request was started.
     */
    public function testCallbackWithoutStoredStateIsRejected()
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->createClient(['code' => 'abc', 'state' => 'attacker'])->authenticate();
        } finally {
            $this->assertSame([], $this->tokenRequests);
        }
    }

    /**
     * Without a session, the returned state is compared with the "state" option.
     */
    public function testStateIsComparedWithOptionWithoutSession()
    {
        $application = $this->createStub(WebApplicationInterface::class);

        $this->expectException(\RuntimeException::class);

        try {
            $this->createClient(['code' => 'abc', 'state' => 'attacker'], ['state' => 'expected'], $application)->authenticate();
        } finally {
            $this->assertSame([], $this->tokenRequests);
        }
    }

    /**
     * The state handling can be switched off for callers that verify the state themselves.
     */
    public function testValidateStateCanBeDisabled()
    {
        $client = $this->createClient(['code' => 'abc', 'state' => 'anything'], ['validatestate' => false]);

        $this->assertSame('accessvalue', $client->authenticate()['access_token']);
        $this->assertArrayNotHasKey('state', $this->getQueryVars($client->createUrl()));
        $this->assertSame([], $this->sessionData);
    }

    /**
     * An error returned by the authorization server is thrown instead of redirecting to it again.
     */
    public function testErrorCallbackThrowsInsteadOfRedirecting()
    {
        $this->sessionData['oauth2.state'] = 'expected';

        $application = $this->createMock(SessionAwareWebApplicationInterface::class);
        $application->method('getSession')->willReturn($this->createSession());
        $application->expects($this->never())->method('redirect');

        $client = $this->createClient(
            ['error' => 'access_denied', 'error_description' => 'The user denied the request', 'state' => 'expected'],
            ['sendheaders' => true],
            $application
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The authorization server returned the error "access_denied": The user denied the request.');

        $client->authenticate();
    }

    /**
     * The state of an error callback is verified as well.
     */
    public function testErrorCallbackWithForeignStateIsRejected()
    {
        $this->sessionData['oauth2.state'] = 'expected';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('state');

        $this->createClient(['error' => 'access_denied', 'state' => 'attacker'])->authenticate();
    }

    /**
     * With PKCE, createUrl() sends the S256 challenge of a generated verifier, and the verifier is sent with the token
     * request.
     */
    public function testPkceWithGeneratedVerifier()
    {
        $client = $this->createClient([], ['pkce' => true]);
        $vars   = $this->getQueryVars($client->createUrl());

        $verifier = $this->sessionData['oauth2.codeverifier'];

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
        $this->assertSame('S256', $vars['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $vars['code_challenge']);

        $this->createClient(['code' => 'abc', 'state' => $vars['state']], ['pkce' => true])->authenticate();

        $this->assertSame($verifier, $this->tokenRequests[0]['code_verifier']);
        $this->assertArrayNotHasKey('oauth2.codeverifier', $this->sessionData);
    }

    /**
     * A configured code verifier is used as is; the challenge matches the example of RFC 7636, Appendix B.
     */
    public function testPkceWithConfiguredVerifier()
    {
        $options = ['pkce' => true, 'codeverifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'validatestate' => false];
        $vars    = $this->getQueryVars($this->createClient([], $options)->createUrl());

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $vars['code_challenge']);

        $this->createClient(['code' => 'abc'], $options)->authenticate();

        $this->assertSame('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', $this->tokenRequests[0]['code_verifier']);
    }

    /**
     * With PKCE but without a verifier, no token request is sent.
     */
    public function testPkceWithoutVerifierThrows()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('code verifier');

        $this->createClient(['code' => 'abc'], ['pkce' => true, 'validatestate' => false])->authenticate();
    }

    /**
     * The "tokenparams" option adds parameters to the token request, without replacing the standard ones.
     */
    public function testTokenParamsAreSent()
    {
        $this->createClient(
            ['code' => 'abc'],
            ['validatestate' => false, 'tokenparams' => ['resource' => 'https://api.test', 'code' => 'other']]
        )->authenticate();

        $this->assertSame('https://api.test', $this->tokenRequests[0]['resource']);
        $this->assertSame('abc', $this->tokenRequests[0]['code']);
    }

    /**
     * The values of the authorization URL are form-encoded exactly once, and an existing query is kept.
     */
    public function testCreateUrlEncodesValues()
    {
        $url = $this->createClient(
            [],
            [
                'authurl'       => 'https://provider.test/authorize?tenant=a%2Fb',
                'clientid'      => 'id&x=1',
                'scope'         => ['openid', 'profile'],
                'state'         => 's&redirect_uri=https://evil.test',
                'requestparams' => ['prompt' => 'select account', 'login_hint' => 'a+b@example.com'],
                'validatestate' => false,
            ]
        )->createUrl();

        $this->assertSame(
            'https://provider.test/authorize?tenant=a%2Fb&response_type=code&client_id=id%26x%3D1'
            . '&redirect_uri=https%3A%2F%2Fexample.com%2Fcallback&scope=openid+profile'
            . '&state=s%26redirect_uri%3Dhttps%3A%2F%2Fevil.test&prompt=select+account&login_hint=a%2Bb%40example.com',
            $url
        );
    }
}
