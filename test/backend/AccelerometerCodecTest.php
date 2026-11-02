<?php

namespace backend;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../autoload.php';

class AccelerometerCodecTest extends TestCase {
	function test_round_trip_keeps_time_and_millimetre_per_second_squared() {
		$samples = [[1761400000050, '-0.1234', '9.8066', '0.0004'], [1761400000000, 0.5, -9.81, 78.4532], [1761400003000, 0, 0, -0.0016]];
		$decoded = AccelerometerCodec::decode(AccelerometerCodec::encode($samples));
		$this->assertSame([
			[1761400000000, 0.5, -9.81, 78.453],
			[1761400000050, -0.123, 9.807, 0.0],
			[1761400003000, 0.0, 0.0, -0.002],
		], $decoded);
	}

	function test_empty_and_broken_chunks() {
		$this->assertSame([], AccelerometerCodec::decode(AccelerometerCodec::encode([])));
		$this->expectException(\UnexpectedValueException::class);
		AccelerometerCodec::decode('not deflate data');
	}

	function test_a_realistic_window_takes_a_few_bytes_per_sample() {
		mt_srand(7);
		$samples = [];
		$time = 1761400000000;
		for($i = 0; $i < 24000; ++$i) { // 20 minutes at 20 Hz, walking in the middle third
			$time += 50;
			$walking = $i > 8000 && $i < 16000 ? sin($i * 0.565) : 0;
			$noise = fn() => (mt_rand() / mt_getrandmax() - 0.5) * 0.1;
			$samples[] = [$time, 2.5 * $walking + $noise(), 9.81 + 3 * $walking + $noise(), $noise()];
		}
		$bytes = strlen(AccelerometerCodec::encode($samples));
		$this->assertLessThan(6 * 24000, $bytes);
	}
}
