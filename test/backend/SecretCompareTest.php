<?php

namespace backend;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../autoload.php';

class SecretCompareTest extends TestCase {
	function testEqualsMatchesIdenticalStrings() {
		$this->assertTrue(SecretCompare::equals('abc123', 'abc123'));
		$this->assertTrue(SecretCompare::equals('', ''));
	}
	
	function testEqualsRejectsDifferentStrings() {
		$this->assertFalse(SecretCompare::equals('abc123', 'abc124'));
		$this->assertFalse(SecretCompare::equals('abc123', 'abc12'));
		$this->assertFalse(SecretCompare::equals('abc123', ''));
	}
	
	function testEqualsDoesNotTreatNumericStringsAsEqual() {
		$this->assertFalse(SecretCompare::equals('0e12345', '0e67890'));
		$this->assertFalse(SecretCompare::equals('1e3', '1000'));
	}
	
	function testEqualsCastsScalarsAndRejectsOtherTypes() {
		$this->assertTrue(SecretCompare::equals(12345, '12345'));
		$this->assertFalse(SecretCompare::equals(null, ''));
		$this->assertFalse(SecretCompare::equals(['a'], 'a'));
		$this->assertFalse(SecretCompare::equals('a', ['a']));
	}
	
	function testInListFindsEntry() {
		$this->assertTrue(SecretCompare::inList(['alpha', 'beta', 'gamma'], 'beta'));
		$this->assertTrue(SecretCompare::inList(['alpha', 'beta', 'gamma'], 'gamma'));
	}
	
	function testInListRejectsMissingAndEmptyList() {
		$this->assertFalse(SecretCompare::inList(['alpha', 'beta'], 'delta'));
		$this->assertFalse(SecretCompare::inList([], 'alpha'));
	}
	
	function testInListIsStrictAboutNumericLookalikes() {
		$this->assertFalse(SecretCompare::inList(['0e111'], '0e222'));
		$this->assertFalse(SecretCompare::inList(['1000'], '1e3'));
	}
}
