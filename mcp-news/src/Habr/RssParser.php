<?php
declare(strict_types=1);

namespace App\Habr;

use App\Support\UrlNormalizer;

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
        libxml_use_internal_errors($prev);

        if ($sx === false || !isset($sx->channel)) {
            return [];
        }

        $items = [];
        foreach ($sx->channel->item as $item) {
            $guid = (string) $item->guid;
            $link = (string) $item->link;
            $url = $this->urls->canonical($guid !== '' ? $guid : $link);

            $desc = (string) $item->description;
            $image = null;
            if (preg_match('/<img[^>]+src="([^"]+)"/i', $desc, $m) === 1) {
                $image = $m[1];
            }
            $snippet = trim(mb_substr(html_entity_decode(strip_tags($desc)), 0, 400));

            $pub = trim((string) $item->pubDate);
            $publishedAt = null;
            if ($pub !== '') {
                try {
                    $publishedAt = (new \DateTimeImmutable($pub))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format(DATE_ATOM);
                } catch (\Exception) {
                    $publishedAt = null;
                }
            }

            $tags = [];
            foreach ($item->category as $cat) {
                $tags[] = trim((string) $cat);
            }

            $items[] = [
                'title' => trim((string) $item->title),
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