<?php

namespace Joomla\OAuth2\Tests\Mock;

use Joomla\Http\Response;

class Callback
{

	/**
	 * Dummy
	 *
	 * @param   string   $url      Path to the resource.
	 * @param   mixed    $data     Either an associative array or a string to be sent with the request.
	 * @param   array    $headers  An array of name-value pairs to include in the header of the request
	 * @param   integer  $timeout  Read timeout in seconds.
	 *
	 * @return  Response
	 *
	 * @since   1.0
	 */
	public static function encodedGrantOauthCallback($url, $data, array $headers = null, $timeout = null)
	{
		return new Response(
			self::toStream('access_token=accessvalue&refresh_token=refreshvalue&expires_in=3600'),
			200,
			array('Content-Type' => 'x-www-form-urlencoded')
		);
	}

	/**
	 * Dummy
	 *
	 * @param   string   $url      Path to the resource.
	 * @param   mixed    $data     Either an associative array or a string to be sent with the request.
	 * @param   array    $headers  An array of name-value pairs to include in the header of the request
	 * @param   integer  $timeout  Read timeout in seconds.
	 *
	 * @return  Response
	 *
	 * @since   1.0
	 */
	public static function jsonGrantOauthCallback($url, $data, array $headers = null, $timeout = null)
	{
		return new Response(
			self::toStream('{"access_token":"accessvalue","refresh_token":"refreshvalue","expires_in":3600}'),
			200,
			array('Content-Type' => 'application/json')
		);
	}

	/**
	 * Dummy
	 *
	 * @param   string   $url      Path to the resource.
	 * @param   mixed    $data     Either an associative array or a string to be sent with the request.
	 * @param   array    $headers  An array of name-value pairs to include in the header of the request
	 * @param   integer  $timeout  Read timeout in seconds.
	 *
	 * @return  Response
	 *
	 * @since   1.0
	 */
	public static function queryOauthCallback($url, $data, array $headers = null, $timeout = null)
	{
		return new Response(
			self::toStream('Lorem ipsum dolor sit amet.'),
			200,
			array('Content-Type' => 'text/html')
		);
	}

	/**
	 * Dummy
	 *
	 * @param   string   $url      Path to the resource.
	 * @param   array    $headers  An array of name-value pairs to include in the header of the request.
	 * @param   integer  $timeout  Read timeout in seconds.
	 *
	 * @return  Response
	 *
	 * @since   1.0
	 */
	public static function getOauthCallback($url, array $headers = null, $timeout = null)
	{
		return new Response(
			self::toStream('Lorem ipsum dolor sit amet.'),
			200,
			array('Content-Type' => 'text/html')
		);
	}

	/**
	 * @param   string  $string  The content of the stream
	 *
	 * @return resource
	 */
	private static function toStream($string)
	{
		$stream = fopen('php://memory', 'rb+');
		fwrite($stream, $string);
		rewind($stream);

		return $stream;
	}
}
