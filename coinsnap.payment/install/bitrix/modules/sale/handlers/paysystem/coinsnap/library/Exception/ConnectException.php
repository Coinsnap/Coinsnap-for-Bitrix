<?php

declare(strict_types=1);

namespace Coinsnap\Exception;

class ConnectException extends CSException
{
    public function __construct(string $curlErrorMessage, int $curlErrorCode)
    {
        parent::__construct($curlErrorMessage, $curlErrorCode);
    }
}
