<?php

declare(strict_types=1);


namespace App\Exception;

class OaiException extends \Exception
{
    public string $oaiCode {
        get {
            return $this->oaiCode;
        }
    }

    public function __construct(string $oaiCode, string $message)
    {
        parent::__construct($message);
        $this->oaiCode = $oaiCode;
    }

}
