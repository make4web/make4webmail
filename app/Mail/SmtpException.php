<?php
declare(strict_types=1);

namespace M4W\Mail;

final class SmtpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $smtpCode = 0, public readonly bool $permanent = false)
    {
        parent::__construct($message, $smtpCode);
    }
}
