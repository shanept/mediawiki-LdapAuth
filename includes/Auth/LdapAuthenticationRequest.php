<?php

namespace Shanept\LdapAuth\Auth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Auth\PasswordDomainAuthenticationRequest;

class LdapAuthenticationRequest extends PasswordDomainAuthenticationRequest
{
    public function getFieldInfo()
    {
        if ($this->action !== AuthManager::ACTION_LOGIN)
            exit(sprintf('%s: Invalid action %s', self::class, $this->action));

        $config = MediaWikiServices::getInstance()
                                   ->getConfigFactory()
                                   ->makeConfig('LdapAuth');
        $domains = $config->get('LdapAuthDomainNames');
        $required = $config->get('LdapAuthRequireDomain');

        $ret = parent::getFieldInfo();

        if (count($domains) == 1 && !$required && isset($ret['username'])) {
            $ret['domain'] = [
                'type' => 'hidden',
                'value' => $domains[0],
            ];
        }

        return $ret;
    }
}
