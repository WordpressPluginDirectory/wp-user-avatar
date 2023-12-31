<?php

declare (strict_types=1);
namespace ProfilePressVendor\Pelago\Emogrifier\HtmlProcessor;

/**
 * Normalizes HTML:
 * - add a document type (HTML5) if missing
 * - disentangle incorrectly nested tags
 * - add HEAD and BODY elements (if they are missing)
 * - reformat the HTML
 * @internal
 */
class HtmlNormalizer extends AbstractHtmlProcessor
{
}
