<?php

namespace backend;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Study content (question text, descriptions, labels, ...) is HTML written in the rich-text editor by researchers and is
 * shown to participants and to other accounts. Pages that are rendered on the server (the no-JS questionnaire, the study
 * overview and install pages) therefore pass it through this allowlist.
 *
 * The output is rebuilt: the input is parsed, and a new tree is created from allowed elements and attributes only; text is
 * copied as text and re-escaped on output. Nothing from the input is passed through as markup, so differences between
 * this parser and a browser's cannot be used to smuggle anything in.
 *
 * Mirrors the allowlist of the admin UI and the participant app (sanitizeHtml.ts).
 */
// TODO: LIVE CHECK V3, rest (needs a browser): study text with links, alignment and an image renders correctly in the admin editor, the
// study overview and the participant app. Tracker Part 1. Done 2026-11-06: bold, lists and emoji in the editor and the participant app.
class HtmlSanitizer {
	const TAGS = [
		'p', 'br', 'div', 'span', 'hr',
		'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'mark', 'small', 'sub', 'sup', 'code', 'pre', 'blockquote',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'ul', 'ol', 'li',
		'a', 'img'
	];
	/** Elements whose content is not text the reader should see: dropped together with everything inside. */
	const DROP_WITH_CONTENT = [
		'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noembed', 'noframes', 'template',
		'head', 'title', 'meta', 'link', 'base', 'textarea', 'select', 'option', 'button', 'svg', 'math', 'canvas', 'audio', 'video',
		'xmp', 'plaintext', 'form', 'input'
	];
	const MAX_DEPTH = 40;
	
	/**
	 * @param mixed $html
	 * @return string HTML that is safe to put into a page
	 */
	static function clean($html): string {
		if(!is_string($html) || $html === '')
			return '';
		
		//libxml drops everything after a "<" that does not start a tag ("Never (<1h)" would become "Never ("): make those literal.
		$html = preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $html) ?? '';
		//Non-ASCII as numeric entities: a <meta charset> inside the content can then not make libxml re-read the text in another encoding.
		if(function_exists('mb_encode_numericentity'))
			$html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
		
		$previousErrors = libxml_use_internal_errors(true);
		try {
			$source = new DOMDocument();
			//The unknown wrapper element keeps libxml from wrapping loose text in <p>; it is not allowlisted, so it is dropped again.
			$source->loadHTML('<?xml encoding="utf-8"?><x-root>' . $html . '</x-root>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
			
			$target = new DOMDocument('1.0', 'UTF-8');
			$container = $target->createElement('div');
			$target->appendChild($container);
			self::copyChildren($source, $container, $target, 0);
			
			$output = '';
			foreach($container->childNodes as $child)
				$output .= $target->saveHTML($child);
			return $output;
		}
		finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
		}
	}
	
	/**
	 * Escapes plain text for use in HTML text and attribute values.
	 */
	static function escape($text): string {
		return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	
	/**
	 * For URLs from study content that end up in src/href: returns '' unless the scheme is one of $schemes (or the URL is
	 * relative). With $allowImageData also "data:image/png|jpeg|gif|webp;base64,...".
	 */
	static function safeUrl($url, array $schemes = ['http', 'https', 'mailto'], bool $allowImageData = false): string {
		if(!is_string($url))
			return '';
		$url = trim($url);
		if($url === '')
			return '';
		//browsers ignore tabs, newlines and other control characters inside the scheme ("java\tscript:"): check without them
		$normalized = preg_replace('/[\x00-\x20\x7f]/', '', $url);
		if(!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $match))
			return $url;
		if($allowImageData && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=]+$#i', $normalized))
			return $normalized;
		return in_array(strtolower($match[1]), $schemes, true) ? $url : '';
	}
	
	private static function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $doc, int $depth): void {
		if($depth > self::MAX_DEPTH)
			return;
		
		foreach($from->childNodes as $child) {
			switch($child->nodeType) {
				case XML_TEXT_NODE:
				case XML_CDATA_SECTION_NODE:
					$to->appendChild($doc->createTextNode($child->nodeValue));
					break;
				case XML_ELEMENT_NODE:
					$tag = strtolower($child->nodeName);
					if(in_array($tag, self::DROP_WITH_CONTENT, true))
						break;
					if(in_array($tag, self::TAGS, true)) {
						$element = $doc->createElement($tag);
						self::copyAttributes($child, $element);
						$to->appendChild($element);
						self::copyChildren($child, $element, $doc, $depth + 1);
					}
					else //unknown element: keep what is inside it, not the element
						self::copyChildren($child, $to, $doc, $depth + 1);
					break;
				default: //comments, processing instructions, doctype, ...
					break;
			}
		}
	}
	
	private static function copyAttributes(DOMElement $from, DOMElement $to): void {
		$tag = $to->nodeName;
		
		$style = $from->getAttribute('style');
		if($style !== '' && preg_match('/^\s*text-align\s*:\s*(left|right|center|justify)\s*;?\s*$/i', $style, $match))
			$to->setAttribute('style', 'text-align: ' . strtolower($match[1]));
		
		if($tag === 'a') {
			$href = self::safeUrl($from->getAttribute('href'));
			if($href !== '') {
				$to->setAttribute('href', $href);
				$to->setAttribute('rel', 'noopener noreferrer');
				if($from->getAttribute('target') === '_blank')
					$to->setAttribute('target', '_blank');
			}
			if($from->hasAttribute('title'))
				$to->setAttribute('title', $from->getAttribute('title'));
		}
		else if($tag === 'img') {
			$src = self::safeUrl($from->getAttribute('src'), ['http', 'https'], true);
			if($src !== '')
				$to->setAttribute('src', $src);
			foreach(['alt', 'title'] as $attribute) {
				if($from->hasAttribute($attribute))
					$to->setAttribute($attribute, $from->getAttribute($attribute));
			}
			foreach(['width', 'height'] as $attribute) {
				if(preg_match('/^\d{1,4}$/', $from->getAttribute($attribute)))
					$to->setAttribute($attribute, $from->getAttribute($attribute));
			}
		}
	}
}
