<?php
declare(strict_types=1);

namespace App\Support;

final class UrlNormalizer
{
    public function id(string $url): ?string
    {
        if (preg_match('#/articles/(\d+)#', $url, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function canonical(string $url): string
    {
        $id = $this->id($url);
        if ($id !== null) {
            return "https://habr.com/ru/articles/{$id}/";
        }
        // strip query string as a fallback
        $base = strtok($url, '?');
        return $base === false ? $url : $base;
    }
}