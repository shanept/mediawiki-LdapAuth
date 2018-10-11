<?php

namespace Shanept\LdapAuth\Exceptions;

use Throwable;
use RuntimeException;

trait i18nTrait
{
    protected $key;
    protected $params;

    public function __construct(
        string $message = '',
        string $key = '',
        array $params = [],
        int $code = 0,
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->key = $key;
        $this->params = $params;
    }

    public function getTranslationKey()
    {
        return $this->key;
    }

    public function getTranslationParams()
    {
        return $this->params;
    }
}
