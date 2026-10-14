<?php
declare(strict_types=1);

namespace M4W\Service;

/** A file too large to travel by e-mail: the interface then suggests a file-transfer service. */
final class TooBigForMail extends \InvalidArgumentException
{
    public function __construct(public readonly string $fileName = '', public readonly int $fileId = 0, public readonly int $size = 0)
    {
        parent::__construct(t('compose.too_big', ['mb' => self::maxMb()]));
    }

    public static function maxMb(): int
    {
        return max(1, (int) \M4W\Core\Settings::get('security.max_attachment_mb', 25));
    }

    /** Details sent to the browser with the error. */
    public function details(): array
    {
        return ['too_big' => true, 'name' => $this->fileName, 'file_id' => $this->fileId, 'size' => $this->size, 'max_mb' => self::maxMb()];
    }

    /** File-transfer services offered instead (administration, one "Name|https://url" per line). @return array<int,array{name:string,url:string}> */
    public static function services(): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) \M4W\Core\Settings::get('files.transfer_services', '')) ?: [] as $line) {
            [$name, $url] = array_map('trim', array_pad(explode('|', $line, 2), 2, ''));
            if ($name !== '' && preg_match('#^https://[^\s"<>]+$#i', $url)) {
                $out[] = ['name' => mb_substr($name, 0, 60), 'url' => $url];
            }
        }
        return array_slice($out, 0, 12);
    }
}
