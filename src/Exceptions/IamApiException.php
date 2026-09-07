<?php

namespace Sd1\IamSsoClient\Exceptions;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class IamApiException extends Exception
{
    /** @var array */
    protected $payload;

    public function __construct(string $message, int $code = 0, array $payload = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public static function connectionFailed(RequestException $e): self
    {
        return new self(
            'Tidak dapat menghubungi OMI-IAM: ' . $e->getMessage(),
            0,
            [],
            $e
        );
    }
}
