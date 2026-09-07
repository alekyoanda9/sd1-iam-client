<?php

namespace Sd1\IamSsoClient\Exceptions;

use Exception;

/**
 * Dilempar saat alur OAuth gagal secara lokal (state tidak cocok, code
 * hilang, dsb) — beda dengan IamApiException yang berarti OMI-IAM sendiri
 * yang menolak/error.
 */
class IamAuthenticationException extends Exception
{
}
