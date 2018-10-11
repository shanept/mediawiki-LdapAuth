<?php

namespace Shanept\LdapAuth\Exceptions;

use Symfony\Component\Ldap\Exception\ConnectionException as CeBase;

class ConnectionException extends CeBase implements I18nException {
	use I18nTrait;
}
