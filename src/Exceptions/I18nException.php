<?php

namespace Shanept\LdapAuth\Exceptions;

interface I18nException {
	/**
	 * Returns the translation key
	 *
	 * @return string The translation key
	 */
	public function getTranslationKey();

	/**
	 * Returns the translation parameters
	 *
	 * @return array The parameters to be included in localization
	 */
	public function getTranslationParams();
}
