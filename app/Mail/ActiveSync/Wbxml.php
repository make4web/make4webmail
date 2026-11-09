<?php
declare(strict_types=1);

namespace M4W\Mail\ActiveSync;

/**
 * WBXML as Exchange ActiveSync uses it (MS-ASWBXML): tags by code page, no attributes, strings
 * inline, opaque data for MIME and attachments. A document is a tree of [name, children|string]
 * where a name is "Page:Tag" (for instance "AirSync:Sync").
 */
final class Wbxml
{
    private const SWITCH_PAGE = 0x00;
    private const END = 0x01;
    private const STR_I = 0x03;
    private const OPAQUE = 0xC3;

    /** Tag tokens by code page (MS-ASWBXML 2.1.2.1), mail and what goes with it. */
    public const PAGES = [
        0 => ['AirSync', [0x05 => 'Sync', 'Responses', 'Add', 'Change', 'Delete', 'Fetch', 'SyncKey', 'ClientId', 'ServerId', 'Status', 'Collection', 'Class', 'Version', 'CollectionId', 'GetChanges', 'MoreAvailable', 'WindowSize', 'Commands', 'Options', 'FilterType', 'Truncation', 'RtfTruncation', 'Conflict', 'Collections', 'ApplicationData', 'DeletesAsMoves', 'NotifyGUID', 'Supported', 'SoftDelete', 'MIMESupport', 'MIMETruncation', 'Wait', 'Limit', 'Partial', 'ConversationMode', 'MaxItems', 'HeartbeatInterval']],
        1 => ['Contacts', [0x05 => 'Anniversary', 'AssistantName', 'AssistantPhoneNumber', 'Birthday', 'Body', 'BodySize', 'BodyTruncated', 'Business2PhoneNumber', 'BusinessAddressCity', 'BusinessAddressCountry', 'BusinessAddressPostalCode', 'BusinessAddressState', 'BusinessAddressStreet', 'BusinessFaxNumber', 'BusinessPhoneNumber', 'CarPhoneNumber', 'Categories', 'Category', 'Children', 'Child', 'CompanyName', 'Department', 'Email1Address', 'Email2Address', 'Email3Address', 'FileAs', 'FirstName', 'Home2PhoneNumber', 'HomeAddressCity', 'HomeAddressCountry', 'HomeAddressPostalCode', 'HomeAddressState', 'HomeAddressStreet', 'HomeFaxNumber', 'HomePhoneNumber', 'JobTitle', 'LastName', 'MiddleName', 'MobilePhoneNumber', 'OfficeLocation', 'OtherAddressCity', 'OtherAddressCountry', 'OtherAddressPostalCode', 'OtherAddressState', 'OtherAddressStreet', 'PagerNumber', 'RadioPhoneNumber', 'Spouse', 'Suffix', 'Title', 'WebPage', 'YomiCompanyName', 'YomiFirstName', 'YomiLastName', 'CompressedRTF', 'Picture', 'Alias', 'WeightedRank']],
        2 => ['Email', [0x05 => 'Attachment', 'Attachments', 'AttName', 'AttSize', 'Att0Id', 'AttMethod', 'AttRemoved', 'Body', 'BodySize', 'BodyTruncated', 'DateReceived', 'DisplayName', 'DisplayTo', 'Importance', 'MessageClass', 'Subject', 'Read', 'To', 'Cc', 'From', 'ReplyTo', 'AllDayEvent', 'Categories', 'Category', 'DTStamp', 'EndTime', 'InstanceType', 'BusyStatus', 'Location', 'MeetingRequest', 'Organizer', 'RecurrenceId', 'Reminder', 'ResponseRequested', 'Recurrences', 'Recurrence', 'Recurrence_Type', 'Recurrence_Until', 'Recurrence_Occurrences', 'Recurrence_Interval', 'Recurrence_DayOfWeek', 'Recurrence_DayOfMonth', 'Recurrence_WeekOfMonth', 'Recurrence_MonthOfYear', 'StartTime', 'Sensitivity', 'TimeZone', 'GlobalObjId', 'ThreadTopic', 'MIMEData', 'MIMETruncated', 'MIMESize', 'InternetCPID', 'Flag', 'FlagStatus', 'ContentClass', 'FlagType', 'CompleteTime', 'DisallowNewTimeProposal']],
        3 => ['AirNotify', []],
        4 => ['Calendar', [0x05 => 'TimeZone', 'AllDayEvent', 'Attendees', 'Attendee', 'Email', 'Name', 'Body', 'BodyTruncated', 'BusyStatus', 'Categories', 'Category', 'CompressedRTF', 'DtStamp', 'EndTime', 'Exception', 'Exceptions', 'Deleted', 'ExceptionStartTime', 'Location', 'MeetingStatus', 'OrganizerEmail', 'OrganizerName', 'Recurrence', 'Type', 'Until', 'Occurrences', 'Interval', 'DayOfWeek', 'DayOfMonth', 'WeekOfMonth', 'MonthOfYear', 'Reminder', 'Sensitivity', 'Subject', 'StartTime', 'UID', 'AttendeeStatus', 'AttendeeType', 0x33 => 'DisallowNewTimeProposal', 'ResponseRequested', 'AppointmentReplyTime', 'ResponseType', 'CalendarType', 'IsLeapMonth', 'FirstDayOfWeek', 'OnlineMeetingConfLink', 'OnlineMeetingExternalLink']],
        5 => ['Move', [0x05 => 'MoveItems', 'Move', 'SrcMsgId', 'SrcFldId', 'DstFldId', 'Response', 'Status', 'DstMsgId']],
        6 => ['GetItemEstimate', [0x05 => 'GetItemEstimate', 'Version', 'Collections', 'Collection', 'Class', 'CollectionId', 'DateTime', 'Estimate', 'Response', 'Status']],
        7 => ['FolderHierarchy', [0x05 => 'Folders', 'Folder', 'DisplayName', 'ServerId', 'ParentId', 'Type', 'Response', 'Status', 'ContentClass', 'Changes', 'Add', 'Delete', 'Update', 'SyncKey', 'FolderCreate', 'FolderDelete', 'FolderUpdate', 'FolderSync', 'Count', 'Version']],
        8 => ['MeetingResponse', []],
        9 => ['Tasks', []],
        10 => ['ResolveRecipients', []],
        11 => ['ValidateCert', []],
        12 => ['Contacts2', [0x05 => 'CustomerId', 'GovernmentId', 'IMAddress', 'IMAddress2', 'IMAddress3', 'ManagerName', 'CompanyMainPhone', 'AccountName', 'NickName', 'MMS']],
        13 => ['Ping', [0x05 => 'Ping', 'AutdState', 'Status', 'HeartbeatInterval', 'Folders', 'Folder', 'Id', 'Class', 'MaxFolders']],
        14 => ['Provision', [0x05 => 'Provision', 'Policies', 'Policy', 'PolicyType', 'PolicyKey', 'Data', 'Status', 'RemoteWipe', 'EASProvisionDoc', 'DevicePasswordEnabled']],
        15 => ['Search', [0x05 => 'Search', 'Stores', 'Store', 'Name', 'Query', 'Options', 'Range', 'Status', 'Response', 'Result', 'Properties', 'Total']],
        16 => ['GAL', []],
        17 => ['AirSyncBase', [0x05 => 'BodyPreference', 'Type', 'TruncationSize', 'AllOrNone', 0x0A => 'Body', 'Data', 'EstimatedDataSize', 'Truncated', 'Attachments', 'Attachment', 'DisplayName', 'FileReference', 'Method', 'ContentId', 'ContentLocation', 'IsInline', 'NativeBodyType', 'ContentType', 'Preview', 'BodyPartPreference', 'BodyPart', 'Status']],
        18 => ['Settings', [0x05 => 'Settings', 'Status', 'Get', 'Set', 'Oof', 'OofState', 'StartTime', 'EndTime', 'OofMessage', 'AppliesToInternal', 'AppliesToExternalKnown', 'AppliesToExternalUnknown', 'Enabled', 'ReplyMessage', 'BodyType', 'DevicePassword', 'Password', 'DeviceInformation', 'Model', 'IMEI', 'FriendlyName', 'OS', 'OSLanguage', 'PhoneNumber', 'UserInformation', 'EmailAddresses', 'SmtpAddress', 'UserAgent', 'EnableOutboundSMS', 'MobileOperator', 'PrimarySmtpAddress', 'Accounts', 'Account', 'AccountId', 'AccountName', 'UserDisplayName', 'SendDisabled']],
        19 => ['DocumentLibrary', []],
        20 => ['ItemOperations', [0x05 => 'ItemOperations', 'Fetch', 'Store', 'Options', 'Range', 'Total', 'Properties', 'Data', 'Status', 'Response', 'Version', 'Schema', 'Part', 'EmptyFolderContents', 'DeleteSubFolders', 'UserName', 'Password', 'Move', 'DstFldId', 'ConversationId', 'MoveAlways']],
        21 => ['ComposeMail', [0x05 => 'SendMail', 'SmartForward', 'SmartReply', 'SaveInSentItems', 'ReplaceMime', 'Type', 'Source', 'FolderId', 'ItemId', 'LongId', 'InstanceId', 'Mime', 'ClientId', 'Status', 'AccountId']],
        22 => ['Email2', [0x05 => 'UmCallerID', 'UmUserNotes', 'UmAttDuration', 'UmAttOrder', 'ConversationId', 'ConversationIndex', 'LastVerbExecuted', 'LastVerbExecutionTime', 'ReceivedAsBcc', 'Sender', 'CalendarType', 'IsLeapMonth', 'AccountId', 'FirstDayOfWeek', 'MeetingMessageType']],
        23 => ['Notes', []],
        24 => ['RightsManagement', []],
    ];

    /** @return array<int, array<int,string>> page => token => tag, with consecutive tokens filled in */
    private static function tables(): array
    {
        static $t = null;
        if ($t === null) {
            $t = [];
            foreach (self::PAGES as $page => [, $tags]) {
                $out = [];
                $next = 0x05;
                foreach ($tags as $k => $name) {
                    $next = is_int($k) && $k >= 0x05 ? $k : $next;
                    $out[$next++] = $name;
                }
                $t[$page] = $out;
            }
        }
        return $t;
    }

    /** "Page:Tag" → [page, token] */
    private static function token(string $name): array
    {
        static $rev = null;
        if ($rev === null) {
            $rev = [];
            foreach (self::tables() as $page => $tags) {
                foreach ($tags as $tok => $tag) {
                    $rev[self::PAGES[$page][0] . ':' . $tag] = [$page, $tok];
                }
            }
        }
        if (!isset($rev[$name])) {
            throw new \InvalidArgumentException('WBXML: unknown tag ' . $name);
        }
        return $rev[$name];
    }

    // ------------------------------------------------------------------ decode

    /** @return ?array [name, children] root element, null for an empty body */
    public static function decode(string $b): ?array
    {
        if ($b === '') {
            return null;
        }
        $p = 0;
        $p++;                          // version
        self::mbInt($b, $p);           // public id
        self::mbInt($b, $p);           // charset (UTF-8)
        $p += self::mbInt($b, $p);     // string table (unused by ActiveSync)
        $page = 0;
        $stack = [['#root', []]];
        $len = strlen($b);
        while ($p < $len) {
            $t = ord($b[$p++]);
            if ($t === self::SWITCH_PAGE) {
                $page = ord($b[$p++]);
            } elseif ($t === self::END) {
                $node = array_pop($stack);
                $stack[count($stack) - 1][1][] = $node;
            } elseif ($t === self::STR_I) {
                $end = strpos($b, "\0", $p);
                $stack[count($stack) - 1][1] = substr($b, $p, $end - $p);
                $p = $end + 1;
            } elseif ($t === self::OPAQUE) {
                $n = self::mbInt($b, $p);
                $stack[count($stack) - 1][1] = substr($b, $p, $n);
                $p += $n;
            } else {
                $tok = $t & 0x3F;
                $name = (self::PAGES[$page][0] ?? 'Page' . $page) . ':' . (self::tables()[$page][$tok] ?? 'Tag' . $tok);
                if ($t & 0x40) {
                    $stack[] = [$name, []];
                } else {
                    $stack[count($stack) - 1][1][] = [$name, ''];
                }
            }
        }
        return $stack[0][1][0] ?? null;
    }

    private static function mbInt(string $b, int &$p): int
    {
        $v = 0;
        do {
            $c = ord($b[$p++] ?? "\0");
            $v = ($v << 7) | ($c & 0x7F);
        } while ($c & 0x80);
        return $v;
    }

    // ------------------------------------------------------------------ encode

    /** @param array $node [name, children|string|['opaque' => bytes]] */
    public static function encode(array $node): string
    {
        $page = 0;
        return "\x03\x01\x6A\x00" . self::node($node, $page);
    }

    private static function node(array $node, int &$page): string
    {
        [$name, $content] = $node + [1 => ''];
        [$pg, $tok] = self::token($name);
        $out = '';
        if ($pg !== $page) {
            $out .= "\x00" . chr($pg);
            $page = $pg;
        }
        if ($content === '' || $content === []) {
            return $out . chr($tok);
        }
        $out .= chr($tok | 0x40);
        if (is_array($content) && isset($content['opaque'])) {
            $out .= chr(self::OPAQUE) . self::mb(strlen($content['opaque'])) . $content['opaque'];
        } elseif (is_array($content)) {
            foreach ($content as $child) {
                if ($child !== null) {
                    $out .= self::node($child, $page);
                }
            }
        } else {
            $out .= chr(self::STR_I) . str_replace("\0", '', (string) $content) . "\0";
        }
        return $out . "\x01";
    }

    private static function mb(int $n): string
    {
        $out = chr($n & 0x7F);
        while ($n >>= 7) {
            $out = chr(($n & 0x7F) | 0x80) . $out;
        }
        return $out;
    }

    // ------------------------------------------------------------------ reading a tree

    /** The first child named so (short name: "SyncKey", or full "AirSync:SyncKey"). */
    public static function child(?array $node, string $name): ?array
    {
        foreach (is_array($node[1] ?? null) ? $node[1] : [] as $c) {
            if (is_array($c) && ($c[0] === $name || str_ends_with($c[0], ':' . $name))) {
                return $c;
            }
        }
        return null;
    }

    /** @return array[] every child named so */
    public static function children(?array $node, string $name): array
    {
        return array_values(array_filter(is_array($node[1] ?? null) ? $node[1] : [], static fn($c) => is_array($c) && ($c[0] === $name || str_ends_with($c[0], ':' . $name))));
    }

    public static function text(?array $node, string $name, string $default = ''): string
    {
        $c = self::child($node, $name);
        return $c === null ? $default : (is_string($c[1]) ? $c[1] : $default);
    }
}
