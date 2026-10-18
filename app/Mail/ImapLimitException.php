<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * The provider (or our own pacing) holds back a connection:
 *   limit    the server said so (too many connections, [UNAVAILABLE], BYE at the greeting…)
 *   refused  the connection itself was refused or cut before the greeting (possible block)
 *   paused   not tried: the provider limited us a moment ago (see ImapLimits)
 *   busy     not tried: as many connections as allowed are already open or opened
 */
final class ImapLimitException extends \RuntimeException
{
    /** What servers answer when they limit connections (Dovecot, Cyrus, OVH, Gmail, Outlook…). */
    private const SAYS_LIMIT = '/\[(UNAVAILABLE|LIMIT|INUSE)\]|too many|maximum number|exceeded|try again later|rate.?limit|throttl|temporarily/i';

    public function __construct(string $message, public readonly string $kind = 'limit', public readonly int $until = 0)
    {
        parent::__construct($message);
    }

    public static function saysLimit(string $answer): bool
    {
        return (bool) preg_match(self::SAYS_LIMIT, $answer);
    }
}
