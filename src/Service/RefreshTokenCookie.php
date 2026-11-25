<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie du refresh token : HttpOnly (illisible par le JS), SameSite=Lax et limité au chemin /api/token,
 * donc joint uniquement aux appels de refresh et de logout. Secure en production (REFRESH_COOKIE_SECURE).
 */
final class RefreshTokenCookie
{
    public const NAME = 'refresh_token';
    public const PATH = '/api/token';

    public function __construct(private readonly bool $secure)
    {
    }

    public function create(string $value, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create(self::NAME, $value, $expiresAt, self::PATH, null, $this->secure, true, false, Cookie::SAMESITE_LAX);
    }

    /** Cookie d'expiration immédiate, pour effacer celui du navigateur */
    public function clear(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, self::PATH, null, $this->secure, true, false, Cookie::SAMESITE_LAX);
    }
}
