<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Нормализует URL статей Хабра к каноническому виду.
 */
final class UrlNormalizer
{
    /**
     * Извлекает числовой идентификатор статьи из URL.
     *
     * @param string $url
     * @return string|null
     */
    public function id(string $url): ?string
    {
        if (preg_match('#/articles/(\d+)#', $url, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Возвращает канонический URL статьи, убирая UTM-метки и нестандартные пути.
     *
     * @param string $url
     * @return string
     */
    public function canonical(string $url): string
    {
        $id = $this->id($url);
        if ($id !== null) {
            return "https://habr.com/ru/articles/{$id}/";
        }
        // strip query string as a fallback
        return explode('?', $url, 2)[0];
    }
}