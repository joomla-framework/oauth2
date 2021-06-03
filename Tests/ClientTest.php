<?php
/**
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2\Tests;

use Joomla\Application\AbstractWebApplication;
use Joomla\Http\Http;
use Joomla\Input\Input;
use Joomla\OAuth2\Client;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Test class for Client.
 *
 * @since  1.0
 */
class ClientTest extends TestCase
{
	const ACCESS_CODE = '4/wEr_dK8SDkjfpwmc98KejfiwJP-f4wm.kdowmnr82jvmeisjw94mKFIJE48mcEM';

	/**
	 * @var    Http  Mock client object.
	 */
	protected $httpClient;

	/**
	 * @var    Input  The input object to use in retrieving GET/POST data.
	 */
	protected $input;

	/**
	 * @var    AbstractWebApplication|\PHPUnit_Framework_MockObject_MockObject  The application object to send HTTP headers for redirects.
	 */
	protected $application;

	/**
	 * Sets up the fixture, for example, opens a network connection.
	 * This method is called before a test is executed.
	 *
	 * @return  void
	 */
	protected function setUp()
	{
		parent::setUp();

		$_SERVER['HTTP_HOST']       = 'mydomain.com';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REQUEST_URI']     = '/index.php';
		$_SERVER['SCRIPT_NAME']     = '/index.php';

		$this->httpClient  = $this->getMockBuilder('Joomla\Http\Http')->getMock();
		$this->input       = new Input(array());
		$this->application = $this->getMockForAbstractClass(
			'Joomla\Application\AbstractWebApplication',
			array(),
			'',
			true,
			true,
			true,
			array('redirect')
		);
	}

	/**
	 * Tests the auth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testAuth2()
	{
		$options = array(
			'tokenurl'     => 'https://accounts.google.com/o/oauth2/token',
			'clientsecret' => 'jeDs8rKw_jDJW8MMf-ff8ejs',
		);

		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);

		$this->input->set('code', self::ACCESS_CODE);

		$this->httpClient
			->expects($this->once())
			->method('post')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'encodedGrantOauthCallback'))
		;
		$result = $oauth2Client->authenticate();

		$this->assertEquals('accessvalue', $result['access_token']);
		$this->assertEquals('refreshvalue', $result['refresh_token']);
		$this->assertEquals(3600, $result['expires_in']);
		$this->assertLessThanOrEqual(1, time() - $result['created']);
	}

	/**
	 * Tests the auth method with JSON data
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testAuthJson()
	{
		$options = array(
			'tokenurl'     => 'https://accounts.google.com/o/oauth2/token',
			'clientsecret' => 'jeDs8rKw_jDJW8MMf-ff8ejs',
		);

		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);

		$this->input->set('code', self::ACCESS_CODE);

		$this->httpClient
			->expects($this->once())
			->method('post')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'jsonGrantOauthCallback'))
		;
		$result = $oauth2Client->authenticate();

		$this->assertEquals('accessvalue', $result['access_token']);
		$this->assertEquals('refreshvalue', $result['refresh_token']);
		$this->assertEquals(3600, $result['expires_in']);
		$this->assertLessThanOrEqual(1, time() - $result['created']);
	}

	/**
	 * Tests the isauth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testIsAuth1()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);

		$this->assertEquals(false, $oauth2Client->isAuthenticated());
	}

	/**
	 * Tests the isauth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testIsAuth2()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);

		$oauth2Client->setToken(
			array(
				'access_token'  => 'accessvalue',
				'refresh_token' => 'refreshvalue',
				'created'       => time(),
				'expires_in'    => 3600,
			)
		);

		$this->assertTrue($oauth2Client->isAuthenticated());
	}

	/**
	 * Tests the isauth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testIsAuth3()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);

		$oauth2Client->setToken(
			array(
				'created'    => time() - 4000,
				'expires_in' => 3600,
			)
		);

		$this->assertFalse($oauth2Client->isAuthenticated());
	}

	/**
	 * Tests the auth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testCreateUrl()
	{
		$options = array(
			'authurl'       => 'https://accounts.google.com/o/oauth2/auth',
			'clientid'      => '01234567891011.apps.googleusercontent.com',
			'scope'         => array(
				'https://www.googleapis.com/auth/adsense',
				'https://www.googleapis.com/auth/calendar'
			),
			'state'         => '123456',
			'redirecturi'   => 'http://localhost/oauth',
			'requestparams' => array('access_type' => 'offline', 'approval_prompt' => 'auto'),
		);

		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);

		$url = $oauth2Client->createUrl();

		$expected = 'https://accounts.google.com/o/oauth2/auth?response_type=code';
		$expected .= '&client_id=01234567891011.apps.googleusercontent.com';
		$expected .= '&redirect_uri=http%3A%2F%2Flocalhost%2Foauth';
		$expected .= '&scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fadsense';
		$expected .= '+https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcalendar';
		$expected .= '&state=123456&access_type=offline&approval_prompt=auto';

		$this->assertEquals($expected, $url);
	}

	/**
	 * Tests the auth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testQuery1()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token'  => 'accessvalue',
				'refresh_token' => 'refreshvalue',
				'created'       => time() - 1800,
				'expires_in'    => 600,
			)
		);

		$result = $oauth2Client->query(
			'https://www.googleapis.com/auth/calendar',
			array('param' => 'value'),
			array(),
			'get'
		);
		$this->assertFalse($result);
	}

	/**
	 * Tests the auth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testQuery2()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token'  => 'accessvalue',
				'refresh_token' => 'refreshvalue',
				'created'       => time() - 1800,
				'expires_in'    => 3600,
			)
		);

		$this->httpClient
			->expects($this->once())
			->method('post')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'queryOauthCallback'))
		;
		$result = $oauth2Client->query(
			'https://www.googleapis.com/auth/calendar',
			array('param' => 'value'),
			array(),
			'post'
		);

		$this->assertEquals($result->body, 'Lorem ipsum dolor sit amet.');
		$this->assertEquals(200, $result->code);
	}

	/**
	 * Tests the auth method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testQuery3()
	{
		$options      = array(
			'authmethod' => 'get',
		);
		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token'  => 'accessvalue',
				'refresh_token' => 'refreshvalue',
				'created'       => time() - 1800,
				'expires_in'    => 3600,
			)
		);

		$this->httpClient
			->expects($this->once())
			->method('get')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'getOauthCallback'))
		;
		$result = $oauth2Client->query(
			'https://www.googleapis.com/auth/calendar',
			array('param' => 'value'),
			array(),
			'get'
		);

		$this->assertEquals($result->body, 'Lorem ipsum dolor sit amet.');
		$this->assertEquals(200, $result->code);
	}

	/**
	 * Tests the setOption method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testSetOption()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setOption('key', 'value');

		$this->assertEquals('value', $oauth2Client->getOption('key'));
	}

	/**
	 * Tests the getOption method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testGetOption()
	{
		$options      = array(
			'key' => 'value',
		);
		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);

		$this->assertEquals('value', $oauth2Client->getOption('key'));
	}

	/**
	 * Tests the setToken method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testSetToken1()
	{
		$token = array(
			'access_token' => 'RANDOM STRING OF DATA',
		);

		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken($token);

		$this->assertEquals($token, $oauth2Client->getToken());
	}

	/**
	 * Tests the setToken method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testSetToken2()
	{
		$token = array(
			'access_token' => 'RANDOM STRING OF DATA',
			'expires_in'   => 3600,
		);

		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken($token);

		$this->assertEquals($token, $oauth2Client->getToken());
	}

	/**
	 * Tests the setToken method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testSetToken3()
	{
		$oauth2Client = new Client(array(), $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token' => 'RANDOM STRING OF DATA',
				'expires'      => 3600,
			)
		);

		$expected = array(
			'access_token' => 'RANDOM STRING OF DATA',
			'expires_in'   => 3600,
		);

		$this->assertEquals($expected, $oauth2Client->getToken());
	}

	/**
	 * Tests the refreshToken method
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testRefreshToken()
	{
		$options      = array(
			'tokenurl'     => 'https://accounts.google.com/o/oauth2/token',
			'clientid'     => '01234567891011.apps.googleusercontent.com',
			'clientsecret' => 'jeDs8rKw_jDJW8MMf-ff8ejs',
			'redirecturi'  => 'http://localhost/oauth',
			'userefresh'   => true,
		);
		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token'  => 'RANDOM STRING OF DATA',
				'expires'       => 3600,
				'refresh_token' => ' RANDOM STRING OF DATA'
			)
		);

		$this->httpClient
			->expects($this->once())
			->method('post')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'encodedGrantOauthCallback'))
		;
		$result = $oauth2Client->refreshToken();

		$this->assertEquals('accessvalue', $result['access_token']);
		$this->assertEquals('refreshvalue', $result['refresh_token']);
		$this->assertEquals(3600, $result['expires_in']);
		$this->assertLessThanOrEqual(1, time() - $result['created']);
	}

	/**
	 * Tests the refreshToken method with JSON
	 *
	 * @group   JOAuth2
	 * @return  void
	 */
	public function testRefreshTokenJson()
	{
		$options      = array(
			'tokenurl'     => 'https://accounts.google.com/o/oauth2/token',
			'clientid'     => '01234567891011.apps.googleusercontent.com',
			'clientsecret' => 'jeDs8rKw_jDJW8MMf-ff8ejs',
			'redirecturi'  => 'http://localhost/oauth',
			'userefresh'   => true,
		);
		$oauth2Client = new Client($options, $this->httpClient, $this->input, $this->application);
		$oauth2Client->setToken(
			array(
				'access_token'  => 'RANDOM STRING OF DATA',
				'expires'       => 3600,
				'refresh_token' => ' RANDOM STRING OF DATA'
			)
		);

		$this->httpClient
			->expects($this->once())
			->method('post')
			->willReturnCallback(array('\\Joomla\\OAuth2\\Tests\\Mock\\Callback', 'jsonGrantOauthCallback'))
		;
		$result = $oauth2Client->refreshToken();

		$this->assertEquals('accessvalue', $result['access_token']);
		$this->assertEquals('refreshvalue', $result['refresh_token']);
		$this->assertEquals(3600, $result['expires_in']);
		$this->assertLessThanOrEqual(1, time() - $result['created']);
	}
}
