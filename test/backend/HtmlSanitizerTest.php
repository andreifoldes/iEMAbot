<?php
declare(strict_types=1);

namespace backend;

use testConfigs\BaseTestSetup;

require_once __DIR__ . '/../autoload.php';

class HtmlSanitizerTest extends BaseTestSetup {
	function test_empty_and_non_strings() {
		$this->assertSame('', HtmlSanitizer::clean(''));
		$this->assertSame('', HtmlSanitizer::clean(null));
		$this->assertSame('', HtmlSanitizer::clean(5));
		$this->assertSame('', HtmlSanitizer::clean(['<b>x</b>']));
	}
	
	function test_editor_output_is_kept() {
		foreach([
			'plain text only',
			'a &amp; b',
			'Lead text <b>bold</b> tail',
			'<p>Hello <b>w</b></p>',
			'<h2>t</h2>',
			'<ul><li>a</li></ul>',
			'<p style="text-align: center">x</p>',
			'<div><br>a<br>b</div><hr>',
			'<p>Психічне здоров\'я 日本語 äöü &amp; 5 &lt; 6</p>',
			'<img src="data:image/png;base64,iVBORw0KGgo=" alt="a" width="20">',
		] as $html)
			$this->assertSame($html, HtmlSanitizer::clean($html), $html);
	}
	
	/**
	 * libxml throws away everything after a "<" that does not start a tag: plain-text study content must not lose text.
	 */
	function test_stray_angle_brackets_are_kept_as_text() {
		$this->assertSame('Never (&lt;1h)', HtmlSanitizer::clean('Never (<1h)'));
		$this->assertSame('&lt;18 years', HtmlSanitizer::clean('<18 years'));
		$this->assertSame('5 &lt; 6 and 7 &gt; 3', HtmlSanitizer::clean('5 < 6 and 7 > 3'));
		$this->assertSame('a <b>bold</b> &amp; <i>x</i>', HtmlSanitizer::clean('a <b>bold</b> & <i>x</i>'));
	}
	
	function test_a_charset_declaration_in_the_content_cannot_garble_the_text() {
		$this->assertSame('日本', HtmlSanitizer::clean('<meta charset="iso-8859-1">日本'));
		$this->assertSame('<p>Психічне 日本語</p>', HtmlSanitizer::clean('<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"><p>Психічне 日本語</p>'));
	}
	
	function test_scripts_and_handlers_are_removed() {
		$this->assertSame('xy', HtmlSanitizer::clean('x<script>alert(1)</script>y'));
		$this->assertSame('<img src="x">', HtmlSanitizer::clean('<img src=x onerror=alert(1)>'));
		$this->assertSame('<a>l</a>', HtmlSanitizer::clean('<a href="javascript:alert(1)">l</a>'));
		$this->assertSame('<a>l</a>', HtmlSanitizer::clean("<a href=\"java\tscript:alert(1)\">l</a>"));
		$this->assertSame('<a>l</a>', HtmlSanitizer::clean('<a href="data:text/html,<script>alert(1)</script>">l</a>'));
		$this->assertSame('', HtmlSanitizer::clean('<iframe src="//evil"></iframe><style>*{display:none}</style><svg onload="x()"></svg>'));
		$this->assertSame('go', HtmlSanitizer::clean('<form action="//evil"><input name="a"><button formaction="javascript:x()">go</button></form>go'));
		$this->assertSame('<img src="x">after', HtmlSanitizer::clean('</x-root><img src=x onerror=1>after'));
	}
	
	function test_unknown_elements_are_unwrapped_and_styles_reduced_to_text_align() {
		$this->assertSame('keep', HtmlSanitizer::clean('<custom>keep</custom>'));
		$this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p style="text-align:center;color:red">x</p>'));
		$this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p style="position:fixed;inset:0">x</p>'));
		$this->assertSame('<p style="text-align: center">x</p>', HtmlSanitizer::clean('<p style="text-align:center">x</p>'));
	}
	
	function test_links_get_rel_and_only_blank_targets() {
		$output = HtmlSanitizer::clean('<a href="https://e.org/a?b=1&c=2" target="_blank">l</a>');
		$this->assertStringContainsString('href="https://e.org/a?b=1&amp;c=2"', $output);
		$this->assertStringContainsString('rel="noopener noreferrer"', $output);
		$this->assertStringContainsString('target="_blank"', $output);
		$this->assertStringNotContainsString('target', HtmlSanitizer::clean('<a href="https://e.org" target="_top">l</a>'));
	}
	
	function test_safeUrl() {
		$this->assertSame('https://x.org', HtmlSanitizer::safeUrl('https://x.org'));
		$this->assertSame('/rel/path', HtmlSanitizer::safeUrl('/rel/path'));
		$this->assertSame('url123', HtmlSanitizer::safeUrl('url123'));
		$this->assertSame('mailto:a@example.org', HtmlSanitizer::safeUrl('mailto:a@example.org'));
		$this->assertSame('', HtmlSanitizer::safeUrl('mailto:a@example.org', ['http', 'https']));
		$this->assertSame('', HtmlSanitizer::safeUrl("java\tscript:alert(1)"));
		$this->assertSame('', HtmlSanitizer::safeUrl('JaVaScRiPt:alert(1)'));
		$this->assertSame('', HtmlSanitizer::safeUrl('data:text/html,x'));
		$this->assertSame('', HtmlSanitizer::safeUrl(null));
		$this->assertSame('data:image/png;base64,AAAA', HtmlSanitizer::safeUrl('data:image/png;base64,AAAA', ['http', 'https'], true));
		$this->assertSame('', HtmlSanitizer::safeUrl('data:image/svg+xml;base64,AAAA', ['http', 'https'], true));
		$this->assertSame('', HtmlSanitizer::safeUrl('data:image/png;base64,AAAA', ['http', 'https'], false));
	}
	
	function test_escape() {
		$this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&amp;&lt;/a&gt;', HtmlSanitizer::escape('<a href="x">\'&</a>'));
		$this->assertSame('5', HtmlSanitizer::escape(5));
	}
}
