<?php
declare(strict_types=1);

namespace App\Habr;

interface HabrClientInterface
{
    /** Загружает тело RSS-ленты. $path начинается с "/", напр. "/ru/rss/hub/programming/all/?fl=ru". */
    public function getRss(string $path): string;

    /** Загружает JSON внутреннего поиска kek для запроса (сначала новые). */
    public function getSearch(string $query, int $page = 1): string;

    /** Загружает HTML-страницу статьи по абсолютному URL. */
    public function getArticle(string $url): string;
}