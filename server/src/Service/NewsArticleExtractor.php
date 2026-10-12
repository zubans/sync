<?php

namespace App\Service;

/**
 * Полный текст статьи со страницы издания — без фото, подписей, видео, меню и рекламы.
 * Разметка у изданий разная, поэтому правило общее: текст статьи — блок, где абзацного текста (`<p>`)
 * больше всего; абзац засчитывается родителю целиком и с убывающим весом — блокам выше. Подзаголовки и цитаты внутри
 * этого блока сохраняются. Служебные абзацы («Читайте также», подписки, реклама) выбрасываются.
 */
final class NewsArticleExtractor
{
    /** Меньше — это не статья, а анонс или страница-заглушка (у РГ бывают заметки на три абзаца). */
    public const MIN_LENGTH = 200;

    private const DROP_TAGS = ['script', 'style', 'noscript', 'iframe', 'svg', 'figure', 'figcaption', 'picture', 'img',
        'video', 'audio', 'aside', 'nav', 'footer', 'header', 'form', 'button', 'template'];

    /** Служебные абзацы изданий. */
    private const BOILERPLATE = '/^(читайте также|подписывайтесь|подпишитесь|смотрите также|материалы по теме|реклама|фото:|видео:|'
        .'advertisement|related:|read more|sign up|subscribe|this story has been|the post .+ appeared first on)/iu';

    /**
     * @return list<string> абзацы статьи; пусто — текст не найден
     */
    public static function extract(string $html): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // Без явной кодировки libxml читает страницу как Latin-1.
            $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return [];
        }
        $xpath = new \DOMXPath($doc);
        foreach (self::DROP_TAGS as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Блок статьи — где больше всего абзацного текста. Абзац засчитывается родителю целиком и с убывающим
        // весом — блокам выше: у BBC каждый абзац в четырёх своих обёртках, и общий у них только <article>.
        // Когда абзацы лежат вместе (РГ, CNN), их родитель всё равно набирает больше всех.
        $scores = [];
        $parents = [];
        foreach ($doc->getElementsByTagName('p') as $p) {
            $length = mb_strlen(self::text($p));
            $parent = $p->parentNode;
            foreach ([1.0, 0.5, 0.3, 0.3, 0.3] as $weight) {
                if (!$parent instanceof \DOMElement) {
                    break;
                }
                $id = spl_object_id($parent);
                $parents[$id] = $parent;
                $scores[$id] = ($scores[$id] ?? 0) + $length * $weight;
                $parent = $parent->parentNode;
            }
        }
        if ($scores === []) {
            return [];
        }
        arsort($scores);
        $block = $parents[array_key_first($scores)];

        $paragraphs = [];
        foreach ($xpath->query('.//p | .//h2 | .//h3 | .//blockquote | .//li', $block) ?: [] as $node) {
            // Абзац внутри цитаты или пункт внутри абзаца — уже вошёл вместе с родителем.
            if ($node->parentNode !== $block && \in_array($node->parentNode?->nodeName, ['p', 'blockquote', 'li'], true)) {
                continue;
            }
            $text = self::text($node);
            if ($text === '' || preg_match(self::BOILERPLATE, $text) || isset($paragraphs[$text])) {
                continue;
            }
            $paragraphs[$text] = true;
        }
        $paragraphs = array_keys($paragraphs);

        return mb_strlen(implode('', $paragraphs)) >= self::MIN_LENGTH ? array_map('strval', $paragraphs) : [];
    }

    private static function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }
}
