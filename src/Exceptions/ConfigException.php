<?php

namespace Shanept\LdapAuth\Exceptions;

use ConfigException as CeBase;

class ConfigException extends CeBase implements i18nException {
	use i18nTrait;
}
