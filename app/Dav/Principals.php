<?php
declare(strict_types=1);

namespace M4W\Dav;

use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

/** Principals: only the signed-in person is listed (no directory of every account). */
final class Principals extends AbstractBackend
{
    public function __construct(private readonly array $user)
    {
    }

    public static function uri(array $user): string
    {
        return 'principals/' . mb_strtolower($user['email']);
    }

    private function me(): array
    {
        return [
            'uri' => self::uri($this->user),
            '{DAV:}displayname' => $this->user['name'] ?? $this->user['email'],
            '{http://sabredav.org/ns}email-address' => mb_strtolower($this->user['email']),
        ];
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        return $prefixPath === 'principals' ? [$this->me()] : [];
    }

    public function getPrincipalByPath($path)
    {
        return $path === self::uri($this->user) ? $this->me() : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        foreach ($searchProperties as $prop => $value) {
            $mine = (string) ($this->me()[$prop] ?? '');
            if ($mine === '' || stripos($mine, (string) $value) === false) {
                return [];
            }
        }
        return [self::uri($this->user)];
    }

    public function findByUri($uri, $principalPrefix)
    {
        return strtolower((string) $uri) === 'mailto:' . mb_strtolower($this->user['email']) ? self::uri($this->user) : null;
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new \Sabre\DAV\Exception\Forbidden();
    }
}
