<?php
declare(strict_types=1);

namespace backend;

use RuntimeException;
use SimpleXMLElement;
use testConfigs\BaseTestSetup;
use ZipArchive;

require_once __DIR__ . '/../autoload.php';

class XlsxWriterTest extends BaseTestSetup {
	private $file;
	
	protected function setUp(): void {
		$this->file = tempnam(sys_get_temp_dir(), 'xlsxtest');
	}
	
	protected function tearDown(): void {
		@unlink($this->file);
	}
	
	/** @return array<string, array<string, array{0: string, 1: string}>> sheet name => cell ref => [type, value] */
	private function read(): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($this->file) === true, 'the file is a zip');
		$workbook = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
		$out = [];
		foreach($workbook->sheets->sheet as $i => $sheet) {
			$n = count($out) + 1;
			$xml = new SimpleXMLElement($zip->getFromName("xl/worksheets/sheet$n.xml"));
			$cells = [];
			foreach($xml->sheetData->row as $row) {
				foreach($row->c as $c) {
					$type = (string) $c['t'];
					$cells[(string) $c['r']] = $type === 'inlineStr' ? ['s', (string) $c->is->t] : [$type === 'b' ? 'b' : 'n', (string) $c->v];
				}
			}
			$out[(string) $sheet['name']] = $cells;
		}
		$zip->close();
		return $out;
	}
	
	function test_cells_have_the_right_types() {
		$x = new XlsxWriter();
		$x->addSheet('Data', [
			['id', 'age', 'score', 'note', 'flag', 'empty'],
			['007', '42', 3.5, 'hello', true, null],
			['-3.5', 10, '', "two\nlines", false, 'x'],
		]);
		$x->save($this->file);
		$data = $this->read()['Data'];
		$this->assertSame(['s', 'id'], $data['A1']);
		$this->assertSame(['s', '007'], $data['A2'], 'leading zeros stay text');
		$this->assertSame(['n', '42'], $data['B2'], 'a numeric string is a number');
		$this->assertSame(['n', '3.5'], $data['C2']);
		$this->assertSame(['b', '1'], $data['E2']);
		$this->assertArrayNotHasKey('F2', $data, 'null leaves the cell empty');
		$this->assertSame(['n', '-3.5'], $data['A3']);
		$this->assertArrayNotHasKey('C3', $data, 'an empty string leaves the cell empty');
		$this->assertSame(['s', "two\nlines"], $data['D3']);
		$this->assertSame(['b', '0'], $data['E3']);
	}
	
	function test_a_formula_is_only_ever_text() {
		$x = new XlsxWriter();
		$x->addSheet('Data', [['=HYPERLINK("http://evil","click")', '+1+1', '-2+3', '@SUM(A1)', "\t=1+1", '=1+1']]);
		$x->save($this->file);
		$zip = new ZipArchive();
		$zip->open($this->file);
		$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
		$this->assertStringNotContainsString('<f>', $sheet, 'no formula cell');
		$this->assertStringNotContainsString('<f ', $sheet);
		foreach($this->read()['Data'] as $cell)
			$this->assertSame('s', $cell[0]);
		$this->assertSame('=HYPERLINK("http://evil","click")', $this->read()['Data']['A1'][1], 'the text is kept as it was');
	}
	
	function test_numbers_that_would_not_survive_stay_text() {
		foreach(['007', '1e5', '+49 151', '1234567890123456', '-0', '0x10', '1,5', ' 12', '12 ', '1.', '.5', 'NaN'] as $s)
			$this->assertFalse(XlsxWriter::isPlainNumber($s), $s);
		foreach(['0', '42', '-7', '3.14', '0.5', '123456789012345', '-1.25'] as $s)
			$this->assertTrue(XlsxWriter::isPlainNumber($s), $s);
	}
	
	function test_markup_unicode_and_forbidden_characters() {
		$x = new XlsxWriter();
		$x->addSheet('Data', [["<b>&\"'", "Психічне здоров'я 日本語 🙂", "a\x00b\x01c\x0Bd", "bad\xB1utf8"]]);
		$x->save($this->file);
		$row = $this->read()['Data'];
		$this->assertSame("<b>&\"'", $row['A1'][1]);
		$this->assertSame("Психічне здоров'я 日本語 🙂", $row['B1'][1]);
		$this->assertSame('abcd', $row['C1'][1], 'characters XML 1.0 forbids are dropped');
		$this->assertStringContainsString('bad', $row['D1'][1], 'invalid UTF-8 is replaced, not fatal');
	}
	
	function test_long_text_is_cut_at_excels_limit_and_counted() {
		$x = new XlsxWriter();
		$x->addSheet('Data', [[str_repeat('a', 40000), str_repeat('b', 32767)]]);
		$x->save($this->file);
		$this->assertSame(1, $x->truncatedCells());
		$row = $this->read()['Data'];
		$this->assertSame(32767, mb_strlen($row['A1'][1]));
		$this->assertSame(32767, mb_strlen($row['B1'][1]));
	}
	
	function test_columns_are_named_like_excel() {
		$this->assertSame(['A', 'Z', 'AA', 'AB', 'AZ', 'BA', 'ZZ', 'AAA', 'XFD'], array_map([XlsxWriter::class, 'columnName'], [0, 25, 26, 27, 51, 52, 701, 702, 16383]));
	}
	
	function test_too_many_columns_is_an_error() {
		$x = new XlsxWriter();
		$this->expectException(RuntimeException::class);
		$x->addSheet('Data', [array_fill(0, 16385, 'x')]);
	}
	
	function test_sheet_names_are_cleaned_and_unique() {
		$x = new XlsxWriter();
		$x->addSheet('Responses: 2025/01 [a]?', [['x']]);
		$x->addSheet('responses: 2025/01 [a]?', [['x']]);
		$x->addSheet('', [['x']]);
		$x->addSheet(str_repeat('n', 60), [['x']]);
		$x->save($this->file);
		$names = array_keys($this->read());
		$this->assertCount(4, $names);
		$this->assertCount(4, array_unique(array_map('mb_strtolower', $names)), 'unique, ignoring case');
		foreach($names as $name) {
			$this->assertLessThanOrEqual(31, mb_strlen($name));
			$this->assertDoesNotMatchRegularExpression('/[\[\]:*?\/\\\\]/', $name);
			$this->assertNotSame('', $name);
		}
	}
	
	function test_a_full_sheet_continues_on_the_next_one_with_the_header() {
		$x = new XlsxWriter(5);
		$rows = [['id', 'v']];
		for($i = 1; $i <= 12; ++$i)
			$rows[] = [(string) $i, 'row' . $i];
		$x->addSheet('Data', $rows);
		$x->save($this->file);
		$sheets = $this->read();
		$this->assertSame(['Data', 'Data (2)', 'Data (3)'], array_keys($sheets));
		foreach($sheets as $cells)
			$this->assertSame(['s', 'id'], $cells['A1'], 'every sheet starts with the header');
		$all = [];
		foreach($sheets as $cells) {
			foreach($cells as $ref => $cell) {
				if($ref[0] === 'A' && $ref !== 'A1')
					$all[] = $cell[1];
			}
		}
		$this->assertSame(array_map('strval', range(1, 12)), $all, 'no row lost, none repeated, in order');
	}
	
	function test_accepts_a_generator_and_an_empty_workbook() {
		$x = new XlsxWriter();
		$x->addSheet('Gen', (function() { yield ['a', 'b']; yield [1, 2]; })());
		$x->save($this->file);
		$this->assertSame(['n', '2'], $this->read()['Gen']['B2']);
		
		$empty = new XlsxWriter();
		$empty->save($this->file);
		$this->assertSame(['Sheet1'], array_keys($this->read()));
	}
	
	function test_temporary_files_are_removed() {
		$before = glob(sys_get_temp_dir() . '/xlsx*') ?: [];
		$x = new XlsxWriter();
		$x->addSheet('Data', [['a']]);
		$x->save($this->file);
		unset($x);
		$after = array_diff(glob(sys_get_temp_dir() . '/xlsx*') ?: [], $before, [$this->file]);
		$this->assertSame([], array_values($after));
	}
}
