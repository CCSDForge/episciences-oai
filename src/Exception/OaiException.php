<?php

declare(strict_types=1);


namespace App\Exception;

class OaiException extends \Exception
{
    public function __construct(
        public readonly string $oaiCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
