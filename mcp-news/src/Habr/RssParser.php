<?php
declare(strict_types=1);

namespace App\Habr;

use App\Support\UrlNormalizer;
use App\Support\DateNormalizer;

/**
 * Парсер RSS-лент Хабра в унифицированный формат элементов дайджеста.
 */
final class RssParser
{
    public function __construct(private UrlNormalizer $urls) {}

    /**
     * @param string $xml Тело RSS-ответа
     * @return list<array{title:string,url:string,published_at:?string,source:string,snippet:string,image:?string,tags:list<string>}>
     */
    public function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($sx === false || !isset($sx->channel)) {
            return [];
        }

        $items = [];
        foreach ($sx->channel->item as $item) {
            $guid = (string) $item->guid;
            $link = (string) $item->link;
            $url = $this->urls->canonical($guid !== '' ? $guid : $link);
            if ($url === '') {
                continue;
            }

            $desc = (string) $item->description;
            $image = null;
            if (preg_match('/<img[^>]+src="([^"]+)"/i', $desc, $m) === 1) {
                $image = $m[1];
            }
            $snippet = trim(mb_substr(html_entity_decode(strip_tags($desc)), 0, 400));

            $publishedAt = DateNormalizer::toAtomUtc(trim((string) $item->pubDate));

            $tags = [];
            foreach ($item->category as $cat) {
                $tags[] = trim((string) $cat);
            }

            $items[] = [
                'title' => trim(html_entity_decode((string) $item->title)),
                'url' => $url,
                'published_at' => $publishedAt,
                'source' => 'habr',
                'snippet' => $snippet,
                'image' => $image,
                'tags' => $tags,
            ];
        }

        return $items;
    }
}