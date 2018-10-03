<?php

namespace Shanept\LdapAuth\Exceptions;

interface i18nException
{
    public function getTranslationKey();
    public function getTranslationParams();
}
