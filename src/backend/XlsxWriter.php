<?php
declare(strict_types=1);

namespace backend;

use RuntimeException;
use ZipArchive;

/**
 * Writes an .xlsx workbook (the spreadsheet format of Excel, LibreOffice and Numbers) without any library: an .xlsx file is a
 * zip of a few XML parts.
 *
 * Meant for researchers' data downloads:
 *  - Text is stored as text ("inline strings"). A cell that starts with "=", "+", "-" or "@" is just text, so unlike CSV
 *    there is no formula injection.
 *  - A value that is a plain number (also a numeric string such as "42" or "3.5") is stored as a number. Anything that would not
 *    survive that (leading zeros such as a participant id "007", more than 15 digits, "+49...", "1e5") stays text.
 *  - Rows are streamed to a temporary file, so a big sheet does not have to fit in memory.
 *  - A sheet that outgrows Excel's row limit continues in "<name> (2)", with the first row (the header) repeated.
 *  - Text cells are cut at Excel's limit of 32,767 characters (see truncatedCells()).
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $xlsx->addSheet('Responses', $rows);   // $rows: iterable of arrays of scalars / null; the first row is the header
 *   $xlsx->save('/tmp/data.xlsx');
 */
class XlsxWriter {
	const MAX_ROWS = 1048576;
	const MAX_COLUMNS = 16384;
	const MAX_CELL_CHARS = 32767;
	const MAX_SHEET_NAME = 31;
	
	/** @var array[] [name, temp file with the <sheetData> rows, column count] */
	private $sheets = [];
	private $names = [];
	private $truncated = 0;
	private $maxRows;
	private $freezeHeader;
	
	/**
	 * @param int $maxRows rows per sheet before it continues in the next one (tests lower it)
	 * @param bool $freezeHeader keep the first row visible while scrolling
	 */
	public function __construct(int $maxRows = self::MAX_ROWS, bool $freezeHeader = true) {
		$this->maxRows = max(2, min($maxRows, self::MAX_ROWS));
		$this->freezeHeader = $freezeHeader;
	}
	
	public function __destruct() {
		$this->cleanUp();
	}
	
	/** How many text cells were cut because Excel cannot hold more than 32,767 characters in one. */
	public function truncatedCells(): int {
		return $this->truncated;
	}
	
	/**
	 * @param iterable $rows each row is an array of string|int|float|bool|null; null and "" leave the cell empty
	 * @throws RuntimeException when a row has more columns than a sheet can hold
	 */
	public function addSheet(string $name, iterable $rows): void {
		$name = $this->uniqueName($this->cleanSheetName($name));
		$part = 1;
		$header = null;
		$writer = $this->openSheet();
		$rowNumber = 0;
		$columns = 0;
		foreach($rows as $row) {
			if($header === null)
				$header = $row;
			if($rowNumber >= $this->maxRows) { // the sheet is full: continue in the next one, with the header again
				$this->closeSheet($writer, $part === 1 ? $name : $this->uniqueName($this->cleanSheetName($name, " ($part)")), $columns, $rowNumber);
				++$part;
				$writer = $this->openSheet();
				$rowNumber = 0;
				$columns = 0;
				$this->writeRow($writer, ++$rowNumber, array_values($header), $columns);
			}
			$this->writeRow($writer, ++$rowNumber, array_values($row), $columns);
		}
		$this->closeSheet($writer, $part === 1 ? $name : $this->uniqueName($this->cleanSheetName($name, " ($part)")), $columns, $rowNumber);
	}
	
	/**
	 * @throws RuntimeException when the file cannot be written
	 */
	public function save(string $path): void {
		if(!$this->sheets)
			$this->addSheet('Sheet1', []);
		$zip = new ZipArchive();
		if($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
			throw new RuntimeException('The spreadsheet file could not be created.');
		
		$sheetTypes = '';
		$workbookSheets = '';
		$workbookRels = '';
		foreach($this->sheets as $i => $sheet) {
			$n = $i + 1;
			$sheetTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$workbookSheets .= '<sheet name="' . $this->xml($sheet[0]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$workbookRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		}
		$styleId = count($this->sheets) + 1;
		$workbookRels .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		
		$decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$zip->addFromString('[Content_Types].xml', $decl
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $sheetTypes . '</Types>');
		$zip->addFromString('_rels/.rels', $decl
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>');
		$zip->addFromString('xl/workbook.xml', $decl
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $workbookSheets . '</sheets></workbook>');
		$zip->addFromString('xl/_rels/workbook.xml.rels', $decl
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $workbookRels . '</Relationships>');
		$zip->addFromString('xl/styles.xml', $decl
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>');
		foreach($this->sheets as $i => $sheet)
			$zip->addFile($sheet[1], 'xl/worksheets/sheet' . ($i + 1) . '.xml');
		
		if(!$zip->close())
			throw new RuntimeException('The spreadsheet file could not be written.');
		$this->cleanUp(); // the parts are inside the zip now
	}
	
	// --- sheet parts ---------------------------------------------------------------------------------------------
	
	/** @return resource temp file holding the sheet's XML, up to and including <sheetData> */
	private function openSheet() {
		$file = tmpfile();
		if($file === false)
			throw new RuntimeException('No temporary file could be created for the spreadsheet.');
		return $file;
	}
	
	/** @param resource $rowsFile */
	private function writeRow($rowsFile, int $rowNumber, array $values, int &$columns): void {
		if(count($values) > self::MAX_COLUMNS)
			throw new RuntimeException('A spreadsheet cannot have more than ' . self::MAX_COLUMNS . ' columns.');
		$columns = max($columns, count($values));
		$cells = '';
		foreach($values as $i => $value) {
			$cell = $this->cell(self::columnName($i) . $rowNumber, $value);
			if($cell !== '')
				$cells .= $cell;
		}
		fwrite($rowsFile, '<row r="' . $rowNumber . '">' . $cells . '</row>');
	}
	
	/** Turns the rows written so far into a complete worksheet file. @param resource $rowsFile */
	private function closeSheet($rowsFile, string $name, int $columns, int $rows): void {
		$path = tempnam(sys_get_temp_dir(), 'xlsx');
		if($path === false)
			throw new RuntimeException('No temporary file could be created for the spreadsheet.');
		$out = fopen($path, 'wb');
		$view = $this->freezeHeader
			? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			: '';
		$dimension = '<dimension ref="A1' . ($columns > 0 && $rows > 0 ? ':' . self::columnName($columns - 1) . $rows : '') . '"/>';
		fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $dimension . $view . '<sheetData>');
		rewind($rowsFile);
		stream_copy_to_stream($rowsFile, $out);
		fwrite($out, '</sheetData></worksheet>');
		fclose($out);
		fclose($rowsFile);
		$this->sheets[] = [$name, $path, $columns];
	}
	
	/** @param mixed $value */
	private function cell(string $ref, $value): string {
		if($value === null || $value === '')
			return '';
		if(is_bool($value))
			return '<c r="' . $ref . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
		if(is_int($value) || (is_float($value) && is_finite($value)))
			return '<c r="' . $ref . '"><v>' . $this->number($value) . '</v></c>';
		$text = (string) $value;
		if(self::isPlainNumber($text))
			return '<c r="' . $ref . '"><v>' . $text . '</v></c>';
		if(mb_strlen($text, 'UTF-8') > self::MAX_CELL_CHARS) {
			$text = mb_substr($text, 0, self::MAX_CELL_CHARS, 'UTF-8');
			++$this->truncated;
		}
		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml($text) . '</t></is></c>';
	}
	
	/** @param int|float $n */
	private function number($n): string {
		return is_int($n) ? (string) $n : rtrim(rtrim(sprintf('%.15G', $n), '0'), '.');
	}
	
	/**
	 * "42", "-3.5", "0.25": a number Excel can hold exactly (at most 15 digits) and that reads back the same. "007", "+49 1",
	 * "1e5", "-0" and "1234567890123456" stay text.
	 */
	public static function isPlainNumber(string $s): bool {
		return preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $s) === 1
			&& $s !== '-0'
			&& strlen(preg_replace('/\D/', '', $s)) <= 15;
	}
	
	/** 0 -> A, 25 -> Z, 26 -> AA, 16383 -> XFD */
	public static function columnName(int $index): string {
		$name = '';
		for($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26))
			$name = chr(65 + ($i - 1) % 26) . $name;
		return $name;
	}
	
	/** Valid XML text: invalid UTF-8 replaced, characters XML 1.0 forbids removed, markup escaped. */
	private function xml(string $text): string {
		$text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text) ?? '';
	}
	
	private function cleanSheetName(string $name, string $suffix = ''): string {
		$name = trim(str_replace(["\0"], '', preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]/u', ' ', $name) ?? ''), " '");
		if($name === '')
			$name = 'Sheet';
		return mb_substr($name, 0, self::MAX_SHEET_NAME - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
	}
	
	/** Sheet names must be unique, ignoring case. */
	private function uniqueName(string $name): string {
		$candidate = $name;
		for($i = 2; isset($this->names[mb_strtolower($candidate, 'UTF-8')]); ++$i)
			$candidate = mb_substr($name, 0, self::MAX_SHEET_NAME - strlen(" ($i)"), 'UTF-8') . " ($i)";
		$this->names[mb_strtolower($candidate, 'UTF-8')] = true;
		return $candidate;
	}
	
	private function cleanUp(): void {
		foreach($this->sheets as $sheet)
			@unlink($sheet[1]);
		$this->sheets = [];
	}
}
