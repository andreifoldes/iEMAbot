<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Converts the rich text authored in the study editor (TipTap HTML) into the small,
 * channel-neutral markup OutboundMessage carries: <b>, <i>, <u>, <s>, <a href="…">
 * and newlines, with all other text HTML-escaped. Channel adapters map this subset
 * to their own formatting (Telegram accepts it as-is with parse_mode=HTML).
 */
class HtmlToChatText {
	const TAG_MAP = [
		'b' => 'b', 'strong' => 'b', 'h1' => 'b', 'h2' => 'b', 'h3' => 'b', 'h4' => 'b',
		'i' => 'i', 'em' => 'i', 'u' => 'u', 's' => 's', 'strike' => 's', 'del' => 's',
	];

	public static function convert(string $html): string {
		$html = str_replace(["\r\n", "\r"], "\n", $html);
		$parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$out = '';
		$open = []; // stack of mapped tags currently open
		foreach($parts as $part) {
			if($part[0] !== '<' || !preg_match('/^<\s*(\/?)\s*([a-zA-Z0-9]+)([^>]*)>$/', $part, $m)) {
				$out .= self::escape(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				continue;
			}
			$closing = $m[1] === '/';
			$tag = strtolower($m[2]);
			$attrs = $m[3];

			if($tag === 'br') {
				$out .= "\n";
			}
			else if(in_array($tag, ['p', 'div', 'ul', 'ol', 'blockquote'], true)) {
				$out .= "\n";
			}
			else if($tag === 'li') {
				$out .= $closing ? "\n" : '• ';
			}
			else if(isset(self::TAG_MAP[$tag])) {
				$mapped = self::TAG_MAP[$tag];
				if($closing) {
					$out .= self::close($open, $mapped);
					if($tag[0] === 'h')
						$out .= "\n";
				}
				else {
					$out .= "<$mapped>";
					$open[] = $mapped;
				}
			}
			else if($tag === 'a') {
				if($closing)
					$out .= self::close($open, 'a');
				else if(preg_match('/href\s*=\s*"([^"]*)"|href\s*=\s*\'([^\']*)\'/i', $attrs, $h)) {
					$href = html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					if(preg_match('#^(https?://|mailto:|tg://)#i', $href)) {
						$out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
						$open[] = 'a';
					}
				}
			}
			// every other tag (img, span, …) is dropped, its text content kept
		}
		foreach(array_reverse($open) as $tag)
			$out .= "</$tag>";

		$out = preg_replace("/[ \t]+\n/", "\n", $out);
		$out = preg_replace("/\n{3,}/", "\n\n", $out);
		return trim($out);
	}

	/**
	 * Close the innermost open $tag. Misnested input (<b><i>x</b></i>) is repaired: tags
	 * opened inside it are closed first and reopened after it, since Telegram rejects
	 * overlapping tags. A closing tag that was never opened is dropped.
	 * @param string[] $open stack of open tags, mutated
	 */
	private static function close(array &$open, string $tag): string {
		$pos = array_search($tag, array_reverse($open, true), true);
		if($pos === false)
			return '';
		$inner = array_slice($open, $pos + 1);
		$out = '';
		foreach(array_reverse($inner) as $t)
			$out .= "</$t>";
		$out .= "</$tag>";
		$open = array_slice($open, 0, $pos);
		foreach($inner as $t) {
			if($t === 'a')
				continue; // a link cannot be reopened without its href
			$out .= "<$t>";
			$open[] = $t;
		}
		return $out;
	}

	/** Plain text (button labels, previews). */
	public static function plain(string $html): string {
		$text = preg_replace('/<br\s*\/?>|<\/(p|div|li)>/i', ' ', $html);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim(preg_replace('/\s+/u', ' ', $text));
	}

	public static function escape(string $text): string {
		return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
	}

	/** Shorten a plain label to $max characters (multibyte safe). */
	public static function truncate(string $text, int $max): string {
		if($max <= 1 || mb_strlen($text) <= $max)
			return $text;
		return rtrim(mb_substr($text, 0, $max - 1)) . '…';
	}

	/**
	 * Split converted text into chunks of at most $max characters at paragraph or line
	 * boundaries (tags in this subset never span paragraphs in practice).
	 * @return string[]
	 */
	public static function split(string $text, int $max): array {
		if(mb_strlen($text) <= $max)
			return [$text];
		$chunks = [];
		$current = '';
		foreach(preg_split("/(\n)/", $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
			if(mb_strlen($current . $piece) > $max && $current !== '') {
				$chunks[] = trim($current);
				$current = '';
			}
			while(mb_strlen($piece) > $max) {
				$chunks[] = mb_substr($piece, 0, $max);
				$piece = mb_substr($piece, $max);
			}
			$current .= $piece;
		}
		if(trim($current) !== '')
			$chunks[] = trim($current);
		return $chunks;
	}
}
