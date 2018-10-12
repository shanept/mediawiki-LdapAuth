<?php

namespace Shanept\LdapAuth\Hooks;

use RuntimeException;
use Shanept\LdapAuth\Exceptions\ConfigException;

use GlobalVarConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Auth\AuthManagerAuthPlugin;

class Config {
	/**
	 * The active configuration register
	 *
	 * @var \Config
	 */
	protected $config;

	/**
	 * The prefix for the config values
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * Default option values as per the extensions.json file
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * JSON decoded array of extension.json
	 *
	 * @var array
	 */
	protected $extension;

	public static function makeConfig() {
		$inst = new self;
		$inst->initOptions();

		return new GlobalVarConfig( $inst->prefix );
	}

	/**
	 * Executes the hook, normalizing all extension config
	 *
	 * @param AuthManagerAuthPlugin $wgAuth
	 */
	public static function go( AuthManagerAuthPlugin $wgAuth ) {
		$inst = new self;

		$inst->config = MediaWikiServices::getInstance()
			->getConfigFactory()->makeConfig( 'LdapAuth' );

		$inst->initOptions();
		$inst->normalizeConfig();
	}

	/**
	 * Initializes the instance with details on the extension's default setting
	 * and values
	 */
	protected function initOptions() {
		if ( isset( $this->options ) ) {
			return;
		}

		$contents = file_get_contents( __DIR__ . '/../../extension.json' );

		if ( false === $contents ) {
			throw new RuntimeException( 'LdapAuth could not open extension.json' );
		}

		$this->extension = json_decode( $contents, true );
		$this->options = [];
		$this->prefix = $this->extension['config_prefix'] ?: 'wgLdapAuth';

		foreach ( $this->extension['config'] as $option => $value ) {
			$this->options[$option] = $value;
		}
	}

	/**
	 * Normalizes the entire configuration
	 */
	protected function normalizeConfig() {
		foreach ( $this->options as $option => $value ) {
			$this->normalizeSetting( $option );
		}
	}

	/**
	 * Proxy method used to normalize any setting. Will determine whether to
	 * choose a specific or a general normalization method
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSetting( $setting ) {
		$fn_name = "normalizeSetting{$setting}";

		if ( method_exists( $this, $fn_name ) ) {
			call_user_func( [ $this, $fn_name ], $setting );
		} else {
			$this->normalizeSettingGeneral( $setting );
		}
	}

	/**
	 * Normalization specifically for the UseLocal setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingUseLocal( $setting ) {
		// Intentionally left empty
	}

	/**
	 * Normalization specifically for the RequireDomain setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingRequireDomain( $setting ) {
		// Intentionally left empty
	}

	/**
	 * Normalization specifically for the DomainNames setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingDomainNames( $setting ) {
		$value = &$GLOBALS["{$this->prefix}{$setting}"];

		if ( !is_array( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
	}

	/**
	 * Normalization specifically for the Servers setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingServers( $setting ) {
		$this->normalizeSettingGeneral( $setting );

		$values = &$GLOBALS["{$this->prefix}{$setting}"];

		$values = array_map( function ( $value ) {
			return preg_split( '/[\s,]+/', $value );
		}, $values );
	}

	/**
	 * Normalization specifically for the MapGroups setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingMapGroups( $setting ) {
		$domains = $this->config->get( "DomainNames" );
		$value = &$GLOBALS["{$this->prefix}{$setting}"];
		$keys = array_keys( $value );
		$merged = array_merge( $keys, $domains );

		// If we have exactly the same count, the value must
		// contain every single domain. We won't populate.
		if ( count( $merged ) == count( $keys ) ) {
			return;
		}

		$default = $GLOBALS["{$this->prefix}{$setting}"];
		$value = $this->populateDomainValues( $value, $default );

		// Remove non-domain values from array
		$value = array_filter( $value, function ( $key ) use ( $domains ) {
			return in_array( $key, $domains );
		}, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Normalization specifically for the EncryptionType setting
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingEncryptionType( $setting ) {
		$this->normalizeSettingGeneral( $setting );

		$valid_values = [ 'none', 'ssl', 'tls' ];
		$values = &$GLOBALS["{$this->prefix}{$setting}"];

		foreach ( $values as $index => $value ) {
			if ( false === $value ) {
				$values[$index] = 'none';
			} elseif ( false === in_array( $value, $valid_values, true ) ) {
				throw new ConfigException( sprintf(
					'Invalid encryption type "%s"',
					$value
				) );
			}
		}
	}

	/**
	 * Used to normalize a generic setting. This will just ensure that any
	 * domain-specific values are set, using the default value if required
	 *
	 * @param string $setting The setting to normalize
	 */
	protected function normalizeSettingGeneral( $setting ) {
		$value = &$GLOBALS["{$this->prefix}{$setting}"];
		$default = $this->retrieveDefaultConfigValue( $setting );
		$value = $this->populateDomainValues( $value, $default );
	}

	/**
	 * Provides the default value of a configuration setting as per
	 * extension.json
	 *
	 * @param string $setting The setting to query
	 *
	 * @return scalar|array The default configuration value
	 */
	protected function retrieveDefaultConfigValue( $setting ) {
		return $this->extension['config'][$setting]['value'];
	}

	/**
	 * Used to transform the setting value into an array of domain-specific
	 * settings
	 *
	 * @param mixed $value The working value
	 * @param mixed $with_default_value The value to provide for domain-specific values
	 *
	 * @return array $value The domain-specific values
	 */
	protected function populateDomainValues( $value, $with_default_value ): array {
		$domains = $this->config->get( "DomainNames" );

		// If we are passed a scalar type, this is our default value.
		// Change what we have been given, and set value up as an array.
		if ( !is_array( $value ) ) {
			$with_default_value = $value;
			$value = [];
		}

		// Now we force a default value on all unset domains.
		foreach ( $domains as $domain ) {
			if ( isset( $value[$domain] ) ) {
				continue;
			}

			$value[$domain] = $with_default_value;
		}

		return $value;
	}
}
