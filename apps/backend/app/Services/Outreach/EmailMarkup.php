<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use DOMDocument;
use DOMNode;

class EmailMarkup
{
    public static function fromText(string $text): string
    {
        return '<p>'.str_replace("\n", '<br>', e(str_replace(["\r\n", "\r"], "\n", $text))).'</p>';
    }

    public static function clean(string $html): string
    {
        if (! str_contains($html, '<')) {
            return self::fromText($html);
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $render = function (DOMNode $node) use (&$render): string {
                if ($node->nodeType === XML_TEXT_NODE) {
                    return e($node->nodeValue);
                }
                $tag = strtolower($node->nodeName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'math', 'img', 'input', 'button'], true)) {
                    return '';
                }
                $content = '';
                foreach ($node->childNodes as $child) {
                    $content .= $render($child);
                }
                if ($tag === 'br') {
                    return '<br>';
                }
                if (in_array($tag, ['p', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote'], true)) {
                    return '<'.$tag.'>'.$content.'</'.$tag.'>';
                }
                if ($tag === 'b' || $tag === 'i') {
                    $tag = $tag === 'b' ? 'strong' : 'em';

                    return '<'.$tag.'>'.$content.'</'.$tag.'>';
                }

                return $content;
            };

            return $render($document->getElementsByTagName('body')->item(0));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function text(string $html): string
    {
        $html = preg_replace('/<br\s*\/?\s*>/i', "\n", self::clean($html));
        $html = preg_replace('/<\/(?:p|li|blockquote)>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function words(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
