<?php

namespace App\Services;

use App\Exceptions\FileRejectedException;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * SVG es XML que el navegador ejecuta como si fuera HTML: no basta con
 * validar mime/extensión. Se parsea como XML real (sin red ni entidades) y:
 *
 * - RECHAZA todo lo que ejecuta o arrastra contenido activo: <script>,
 *   <foreignObject>, <iframe>/<embed>/<object>, atributos `on*`, URLs
 *   `javascript:`/`data:text`, animaciones que reescriben un href, estilos
 *   con `expression()`/`@import`/URLs externas y declaraciones ENTITY/DTD
 *   internas (XXE, billion laughs). Es la misma política que el resto de
 *   formatos: un archivo con código embebido no se acepta.
 * - NORMALIZA lo inerte: elimina comentarios, instrucciones de proceso,
 *   metadatos de editores (Inkscape/Illustrator) y referencias externas
 *   `http(s)://` (rastreo), y re-serializa de forma canónica.
 */
class SvgSanitizer
{
    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'applet', 'audio', 'video', 'handler', 'listener'];
    private const ACTIVE_URL = '/^(javascript|vbscript|data:(?!image\/))/i';
    private const EXTERNAL_URL = '/^\s*(https?:)?\/\//i';
    private const DANGEROUS_STYLE = '/expression\s*\(|javascript\s*:|@import|behaviou?r\s*:|-moz-binding|url\s*\(\s*+[\'"]?+\s*+(?!#|data:image\/)/i';

    /**
     * @throws FileRejectedException si el SVG no es XML válido o trae contenido activo
     */
    public function sanitize(string $svg): string
    {
        $svg = ltrim($this->stripBom($svg));

        if (preg_match('/<!ENTITY|<!DOCTYPE[^>\[]*\[/i', $svg) === 1) {
            throw new FileRejectedException('El SVG contiene declaraciones no permitidas.');
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;
        if (!$loaded || $root === null || strtolower($root->localName) !== 'svg') {
            throw new FileRejectedException('El SVG está dañado o no es válido.');
        }

        $this->cleanAttributes($root);
        $this->cleanNode($root);

        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child !== $root) {
                $dom->removeChild($child); // DOCTYPE, comentarios o instrucciones fuera de la raíz
            }
        }

        if (!$root->hasAttribute('xmlns')) {
            $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }

        return (string) $dom->saveXML($root);
    }

    private function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            } elseif ($child instanceof DOMElement) {
                $this->cleanElement($child, $node);
            }
        }
    }

    private function cleanElement(DOMElement $element, DOMNode $parent): void
    {
        $name = strtolower($element->localName);

        if (in_array($name, self::FORBIDDEN_ELEMENTS, true)) {
            throw new FileRejectedException('El SVG contiene scripts o contenido activo y no está permitido.');
        }

        if ($name === 'metadata' || in_array($element->prefix, ['sodipodi', 'inkscape', 'i', 'x'], true)) {
            $parent->removeChild($element);

            return;
        }

        if (in_array($name, ['animate', 'set', 'animatetransform', 'animatemotion'], true)
            && preg_match('/href/i', (string) $element->getAttribute('attributeName')) === 1) {
            throw new FileRejectedException('El SVG contiene animaciones que modifican enlaces y no está permitido.');
        }

        if ($name === 'style' && preg_match(self::DANGEROUS_STYLE, (string) $element->textContent) === 1) {
            throw new FileRejectedException('El SVG contiene estilos no permitidos.');
        }

        $this->cleanAttributes($element);
        $this->cleanNode($element);
    }

    private function cleanAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->value;
            $plain = preg_replace('/[\x00-\x20]+/', '', $value) ?? $value;

            if (str_starts_with($name, 'on')) {
                throw new FileRejectedException('El SVG contiene scripts o contenido activo y no está permitido.');
            }

            if (preg_match(self::ACTIVE_URL, $plain) === 1) {
                throw new FileRejectedException('El SVG contiene enlaces activos y no está permitido.');
            }

            if ($name === 'style' && preg_match(self::DANGEROUS_STYLE, $value) === 1) {
                throw new FileRejectedException('El SVG contiene estilos no permitidos.');
            }

            if (($name === 'href' || str_ends_with($name, ':href') || $name === 'src') && preg_match(self::EXTERNAL_URL, $value) === 1) {
                $element->removeAttributeNode($attribute); // referencia externa: rastreo / carga remota
            }
        }
    }

    private function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
