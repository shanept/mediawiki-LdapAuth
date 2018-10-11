<?php

namespace Shanept\LdapAuth\Hooks;

use Shanept\LdapAuth\Exceptions\ConfigException;

use GlobalVarConfig;
use MediaWiki\MediaWikiServices;
use MediaWiki\Auth\AuthManagerAuthPlugin;

class Config
{
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

    public static function makeConfig()
    {
        $inst = new self;
        $inst->init_options();

        return new GlobalVarConfig($inst->prefix);
    }

    public static function go(AuthManagerAuthPlugin $wgAuth)
    {
        $inst = new self;

        $inst->config = MediaWikiServices::getInstance()
                           ->getConfigFactory()
                           ->makeConfig('LdapAuth');

        $inst->init_options();
        $inst->normalize_config();
    }

    protected function init_options()
    {
        if (isset($this->options))
            return;

        $contents = file_get_contents(__DIR__ . '/../../extension.json');
        if (false === $contents) {
            throw RuntimeException("LdapAuth could not open extension.json");
        }

        $this->extension = json_decode($contents, true);

        $this->prefix = $this->extension['config_prefix'] ?: 'wgLdapAuth';

        $this->options = [];
        foreach ($this->extension['config'] as $option=>$value) {
            $this->options[$option] = $value;
        }
    }

    protected function normalize_config()
    {
        foreach ($this->options as $option=>$value) {
            $this->normalize_setting($option);
        }
    }

    protected function normalize_setting($setting)
    {
        $fn_name = 'normalize_setting_' . strtolower($setting);

        if (method_exists($this, $fn_name)) {
            call_user_func([$this, $fn_name], $setting);
        } else {
            $this->normalize_setting_general($setting);
        }
    }

    protected function normalize_setting_uselocal($setting)
    {
        // Intentionally left empty
    }

    protected function normalize_setting_requiredomain($setting)
    {
        // Intentionally left empty
    }

    protected function normalize_setting_domainnames($setting)
    {
        $value = &$GLOBALS["{$this->prefix}{$setting}"];

        if (!is_array($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }
    }

    protected function normalize_setting_servers($setting)
    {
        $this->normalize_setting_general($setting);

        $values = &$GLOBALS["{$this->prefix}{$setting}"];

        $values = array_map(function($value) {
            return preg_split('/[\s,]+/', $value);
        }, $values);
    }

    protected function normalize_setting_mapgroups($setting)
    {
        $value = &$GLOBALS["{$this->prefix}{$setting}"];
        $keys = array_keys($value);
        $domains = $this->config->get("DomainNames");
        $merged = array_merge($keys, $domains);

        // If we have exactly the same count, the value must
        // contain every single domain. We won't populate.
        if (count($merged) == count($keys))
            return;

        $default = $GLOBALS["{$this->prefix}{$setting}"];
        $value = $this->populate_domain_values($value, $default);

        // Remove non-domain values from array
        $value = array_filter($value, function($key) use($domains) {
            return in_array($key, $domains);
        }, ARRAY_FILTER_USE_KEY);
    }

    protected function normalize_setting_encryptiontype($setting)
    {
        $this->normalize_setting_general($setting);

        $valid_values = ['none', 'ssl', 'tls'];

        $values = &$GLOBALS["{$this->prefix}{$setting}"];
        foreach ($values as $index=>$value) {
            if (false === $value) {
                $values[$index] = 'none';
            } else if (false === in_array($value, $valid_values, true)) {
                throw new ConfigException(sprintf(
                    'Invalid encryption type "%s"',
                    $value
                ));
            }
        }
    }

    protected function normalize_setting_general($setting)
    {
        $value = &$GLOBALS["{$this->prefix}{$setting}"];
        $default = $this->retrieve_default_config_value($setting);
        $value = $this->populate_domain_values($value, $default);
    }

    protected function retrieve_default_config_value(string $setting)
    {
        return $this->extension['config'][$setting]['value'];
    }

    protected function populate_domain_values($value, $with_default_value): array
    {
        $domains = $this->config->get("DomainNames");

        // If we are passed a scalar type, this is our default value.
        // Change what we have been given, and set value up as an array.
        if (!is_array($value)) {
            $with_default_value = $value;
            $value = [];
        }

        // Now we force a default value on all unset domains.
        foreach ($domains as $domain) {
            if (isset($value[$domain]))
                continue;

            $value[$domain] = $with_default_value;
        }

        return $value;
    }
}
