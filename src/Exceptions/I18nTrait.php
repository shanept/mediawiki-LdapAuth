<?php

namespace Shanept\LdapAuth\Exceptions;

use Throwable;

trait I18nTrait {
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
	 * I know, smelly code. Overwriting the __construct, I'm calling parent...
	 * so why isn't this abstract?
	 *
	 * This trait is used by all execptions in this plugin to allow
	 * the exceptions to also carry internationalization information
	 * up to the handler. Because the exceptions all extend *other*
	 * classes, this is the only way to achieve multiple inheritence.
	 *
	 * As I still need to keep note of the normal PHP exception info,
	 * it is passed up to the parent - whatever that may be, to be
	 * handled normally.
	 *
	 * I'll try not to break anything...
	 * @suppress PhanTraitParentReference
	 *
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
