<?php

namespace Shanept\LdapAuth\Exceptions;

use ConfigException as CeBase;

class ConfigException extends CeBase implements I18nException {
	use I18nTrait;
}
