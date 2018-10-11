<?php

namespace Shanept\LdapAuth\Exceptions;

use Throwable;

trait i18nTrait {
	/**
	 * The localization message key
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * Parameters to be passed into the localized message
	 *
	 * @var array
	 */
	protected $params;

	/**
	 * @param string $message Non-localized exception message
	 * @param string $key The localization message key
	 * @param array $params Parameters to be passed into the localized message
	 * @param int $code The exception code
	 * @param Throwable|null $previous
	 */
	public function __construct(
		$message = '',
		$key = '',
		array $params = [],
		$code = 0,
		Throwable $previous = null
	) {
		parent::__construct( $message, $code, $previous );

		$this->key = $key;
		$this->params = $params;
	}

	/**
	 * @inheritDoc
	 */
	public function getTranslationKey() {
		return $this->key;
	}

	/**
	 * @inheritDoc
	 */
	public function getTranslationParams() {
		return $this->params;
	}
}
