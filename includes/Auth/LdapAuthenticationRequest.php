<?php

namespace Shanept\LdapAuth\Auth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Auth\PasswordDomainAuthenticationRequest;

class LdapAuthenticationRequest extends PasswordDomainAuthenticationRequest
{
    public function getFieldInfo()
    {
        $config = MediaWikiServices::getInstance()
                                   ->getConfigFactory()
                                   ->makeConfig('LdapAuth');

        $domains = $config->get('DomainNames');
        $required = $config->get('RequireDomain');

        $ret = parent::getFieldInfo();

        switch ($this->action) {
            case AuthManager::ACTION_REMOVE:
                return [];

            case AuthManager::ACTION_LINK:
            case AuthManager::ACTION_CREATE:
            case AuthManager::ACTION_LOGIN:
                if (count($domains) == 1 && !$required && isset($ret['username'])) {
                    $ret['domain'] = [
                        'type' => 'hidden',
                        'value' => $domains[0],
                    ];
                }

                break;

        }

        return $ret;
    }
}
