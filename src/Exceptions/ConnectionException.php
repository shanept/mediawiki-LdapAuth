<?php

namespace Shanept\LdapAuth\Exceptions;

use Symfony\Component\Ldap\Exception\ConnectionException as CeBase;

class ConnectionException extends CeBase implements i18nException {
	use i18nTrait;
}
