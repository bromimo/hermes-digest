<?php
declare(strict_types=1);

namespace App\Habr;

/**
 * Парсер ответа внутреннего JSON-API Хабра (/kek/v2/articles/) в унифицированный формат.
 */
final class SearchApiParser
{
    /**
     * @param string $json Тело JSON-ответа от /kek/v2/articles/
     * @return list<array{title:string,url:string,published_at:?string,source:string,snippet:string,image:?string,tags:list<string>}>
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['publicationIds'], $data['publicationRefs'])) {
            return [];
        }

        $out = [];
        foreach ($data['publicationIds'] as $id) {
            $id = (string) $id;
            $ref = $data['publicationRefs'][$id] ?? null;
            if (!is_array($ref)) {
                continue;
            }

            $published = null;
            if (!empty($ref['timePublished'])) {
                try {
                    $published = (new \DateTimeImmutable((string) $ref['timePublished']))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format(DATE_ATOM);
                } catch (\Exception) {
                    $published = null;
                }
            }

            $lead = (string) ($ref['leadData']['textHtml'] ?? '');
            $image = $ref['leadData']['imageUrl'] ?? null;

            $tags = [];
            foreach (($ref['tags'] ?? []) as $t) {
                $tags[] = (string) $t;
            }

            $out[] = [
                'title' => trim(html_entity_decode(strip_tags((string) ($ref['titleHtml'] ?? '')))),
                'url' => "https://habr.com/ru/articles/{$id}/",
                'published_at' => $published,
                'source' => 'habr',
                'snippet' => trim(mb_substr(html_entity_decode(strip_tags($lead)), 0, 400)),
                'image' => is_string($image) ? $image : null,
                'tags' => $tags,
            ];
        }

        return $out;
    }
}