<?php
declare(strict_types=1);

namespace M4W\Dav;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Tells clients the server sends the invitations itself (RFC 6638), so phones and Thunderbird
 * do not send their own e-mails as well. The sending is done by the CalDAV backend.
 */
final class AutoSchedule extends ServerPlugin
{
    public function getFeatures()
    {
        return ['calendar-auto-schedule'];
    }

    public function getPluginName()
    {
        return 'm4w-auto-schedule';
    }

    public function initialize(Server $server)
    {
    }
}
