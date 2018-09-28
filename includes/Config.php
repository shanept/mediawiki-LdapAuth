<?php

namespace Shanept\\LdapAuth;

class Config
{
    protected $setting;

    public function __construct(string $setting)
    {
        $this->setting = $setting;
    }

    public function get()
    {
        return $this->get($this->setting);
    }

    protected function _get($setting)
    {
        $value = retrieve($setting);

        switch($setting) {
            case 'Domains':
                if (!is_array($value)) {
                    $value = preg_split('/ ,/', $value);
                }

                break;
            case 'Servers':
            case 'BindDN':
            case 'BindPass':
            case 'BaseDNs':
            case 'SearchTree':
            case 'SearchFilter':
            case 'EncryptionType':
            case 'UseFullDN':
            case 'IsActiveDirectory':
                $default = self::retrieve_default_config_value($setting);
                $value = self::populate_domain_values($value, $default);

                break;
            case 'MapGroups':
                $keys = array_keys($value);
                $domains = $this->_get('Domains');
                $merged = array_merge($keys, $domains);

                // If we don't have exactly the same count, the value doesn't
                // contain every single domain. We must populate.
                if (count($merged) != count($keys)) {
                    $default = self::retrieve_default_config_value($setting);
                    $value = self::populate_domain_values($value, $default);
                }

                break;
        }

        return $value;
    }

    protected function retrieve(string $setting)
    {
        if (!isset($GLOBALS["wgLdap{$setting}"])) {
            throw Exception("$setting not set");
        }

        return $GLOBALS["wgLdap{$setting}"];
    }

    protected retrieve_default_config_value(string $setting)
    {
        return false;
    }

    protected function populate_domain_values($value, $with_default_value): array
    {
        $domains = $this->_get('Domains');

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
