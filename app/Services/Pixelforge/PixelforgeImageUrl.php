<?php

namespace App\Services\Pixelforge;

/**
 * Generated images are only ever fetched from Pixelforge's own host, over
 * https, so a bad URL in the database can't make this server request
 * arbitrary addresses (cloud metadata endpoints, internal services).
 */
final class PixelforgeImageUrl
{
    public static function isTrusted(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $allowed = parse_url((string) config('services.pixelforge.base_url'), PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($allowed)
            && parse_url($url, PHP_URL_HOST) === $allowed;
    }
}
