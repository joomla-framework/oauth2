<?php

/**
 * Part of the Joomla Framework OAuth2 Package
 *
 * @copyright  Copyright (C) 2005 - 2026 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\OAuth2\Exception;

/**
 * Exception thrown when a request needs an access token that has expired and cannot be refreshed.
 *
 * @since  __DEPLOY_VERSION__
 */
class ExpiredTokenException extends \RuntimeException
{
}
