<?php
declare(strict_types=1);

namespace M4W\Dav;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Service\Contacts;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\DAV\Exception;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCard;

/**
 * Two address books: the person's contacts (read and write, the same as on the web; addresses
 * only collected from mail are left out) and the organisation directory (read-only).
 */
final class CardDavBackend extends AbstractBackend
{
    private const MINE = 1;
    private const DIRECTORY = 2;

    public function __construct(private readonly array $user)
    {
    }

    private function uid(): int
    {
        return (int) $this->user['id'];
    }

    public function getAddressBooksForUser($principalUri)
    {
        if ($principalUri !== Principals::uri($this->user)) {
            return [];
        }
        $mine = DB::one('SELECT COUNT(*) n, MAX(updated_at) t, MAX(id) i FROM contacts WHERE user_id = :u AND auto_collected = 0', ['u' => $this->uid()]);
        $dir = DB::one("SELECT COUNT(*) n, MAX(updated_at) t, MAX(id) i FROM users WHERE status = 'active'");
        return [
            ['id' => self::MINE, 'uri' => 'contacts', 'principaluri' => $principalUri, '{DAV:}displayname' => t('dav.my_contacts'),
                '{http://calendarserver.org/ns/}getctag' => md5(implode(':', $mine ?? []))],
            ['id' => self::DIRECTORY, 'uri' => 'annuaire', 'principaluri' => $principalUri, '{DAV:}displayname' => t('dav.directory'),
                '{http://calendarserver.org/ns/}getctag' => md5(implode(':', $dir ?? []))],
        ];
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch)
    {
    }

    public function createAddressBook($principalUri, $url, array $properties)
    {
        throw new Exception\Forbidden();
    }

    public function deleteAddressBook($addressBookId)
    {
        throw new Exception\Forbidden();
    }

    /** @return array<string,array{uri:string,carddata:string}> */
    private function cards(int $book, ?array $uris = null): array
    {
        $out = [];
        if ($book === self::MINE) {
            foreach (DB::all('SELECT * FROM contacts WHERE user_id = :u AND auto_collected = 0', ['u' => $this->uid()]) as $c) {
                if ($c['uri'] === '') {
                    $c['uri'] = 'm4w-' . $c['id'] . '.vcf';
                    DB::update('contacts', ['uri' => $c['uri']], 'id = :id', ['id' => $c['id']]);
                }
                if ($uris === null || in_array($c['uri'], $uris, true)) {
                    $out[$c['uri']] = ['uri' => $c['uri'], 'carddata' => Contacts::card($c), 'lastmodified' => (int) $c['updated_at']];
                }
            }
        } elseif ($book === self::DIRECTORY) {
            $org = (string) Settings::get('brand.company', '');
            foreach (DB::all("SELECT * FROM users WHERE status = 'active' ORDER BY id") as $u) {
                $uri = 'user-' . $u['id'] . '.vcf';
                if ($uris !== null && !in_array($uri, $uris, true)) {
                    continue;
                }
                $name = $u['display_name'] ?: trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'];
                $v = new VCard(['VERSION' => '3.0', 'UID' => 'm4w-user-' . $u['id'], 'FN' => $name, 'N' => [$u['last_name'], $u['first_name'], '', '', '']]);
                $v->add('EMAIL', $u['email'], ['TYPE' => ['INTERNET', 'WORK']]);
                if ($u['phone'] !== '') {
                    $v->add('TEL', $u['phone'], ['TYPE' => 'WORK']);
                }
                if ($u['mobile'] !== '') {
                    $v->add('TEL', $u['mobile'], ['TYPE' => 'CELL']);
                }
                if ($org !== '' || $u['department'] !== '') {
                    $v->ORG = array_values(array_filter([$org ?: ' ', $u['department']], static fn($x) => $x !== ''));
                }
                if ($u['job_title'] !== '') {
                    $v->TITLE = $u['job_title'];
                }
                $out[$uri] = ['uri' => $uri, 'carddata' => $v->serialize(), 'lastmodified' => (int) $u['updated_at']];
            }
        }
        foreach ($out as &$c) {
            $c['etag'] = '"' . md5($c['carddata']) . '"';
            $c['size'] = strlen($c['carddata']);
        }
        return $out;
    }

    public function getCards($addressbookId)
    {
        return array_values(array_map(static function ($c) {
            unset($c['carddata']);
            return $c;
        }, $this->cards((int) $addressbookId)));
    }

    public function getCard($addressBookId, $cardUri)
    {
        return $this->cards((int) $addressBookId, [(string) $cardUri])[(string) $cardUri] ?? false;
    }

    public function getMultipleCards($addressBookId, array $uris)
    {
        return array_values($this->cards((int) $addressBookId, array_map('strval', $uris)));
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        return $this->write((int) $addressBookId, (string) $cardUri, (string) $cardData);
    }

    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        return $this->write((int) $addressBookId, (string) $cardUri, (string) $cardData);
    }

    private function write(int $book, string $uri, string $data): ?string
    {
        if ($book !== self::MINE) {
            throw new Exception\Forbidden(t('dav.directory_readonly'));
        }
        if (!preg_match('/^[\w.@+\-]{1,180}$/', $uri) || strlen($data) > 1048576) {
            throw new Exception\BadRequest();
        }
        try {
            Contacts::saveCard($this->uid(), $uri, $data);
        } catch (\Throwable $e) {
            throw new Exception\BadRequest($e->getMessage());
        }
        return null;
    }

    public function deleteCard($addressBookId, $cardUri)
    {
        if ((int) $addressBookId !== self::MINE) {
            throw new Exception\Forbidden(t('dav.directory_readonly'));
        }
        DB::run('DELETE FROM contacts WHERE user_id = :u AND uri = :uri', ['u' => $this->uid(), 'uri' => (string) $cardUri]);
        return true;
    }
}
