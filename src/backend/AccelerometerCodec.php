<?php
declare(strict_types=1);

namespace backend;

/**
 * Compact storage of raw accelerometer samples (PhoneSensingStore keeps one compressed chunk per upload batch
 * in the sensor_chunks table instead of one database row per sample: about 4 bytes per sample instead of ~155).
 *
 * Encoding "d1" (all integers unsigned LEB128 varints, signed ones zigzag-encoded):
 *   count                       number of samples
 *   count x time delta (signed) ms; the first one is the absolute epoch ms
 *   count x x-delta, then count x y-delta, then count x z-delta (signed), in units of 0.001 m/s²,
 *                               each relative to the previous sample's value (the first one to 0)
 * The whole payload is raw-deflate compressed (PHP gzdeflate, zlib's deflate without header).
 * Columns are stored one after the other because similar numbers next to each other compress better.
 * 0.001 m/s² is finer than phone accelerometers resolve (about 0.002 m/s²), so nothing measurable is lost.
 */
class AccelerometerCodec {
	const ENCODING = 'd1';
	const SCALE = 1000;

	/**
	 * @param array<int, array{0: int|string, 1: float|string, 2: float|string, 3: float|string}> $samples [time, x, y, z],
	 *        sorted by time here
	 */
	public static function encode(array $samples): string {
		usort($samples, fn($a, $b) => (int) $a[0] <=> (int) $b[0]);
		$columns = [self::varint(count($samples)), '', '', '', ''];
		$previous = [0, 0, 0, 0];
		foreach($samples as $sample) {
			$values = [
				(int) $sample[0],
				(int) round(((float) $sample[1]) * self::SCALE),
				(int) round(((float) $sample[2]) * self::SCALE),
				(int) round(((float) $sample[3]) * self::SCALE),
			];
			for($c = 0; $c < 4; ++$c) {
				$columns[$c + 1] .= self::varint(self::zigzag($values[$c] - $previous[$c]));
				$previous[$c] = $values[$c];
			}
		}
		return gzdeflate(implode('', $columns), 9);
	}

	/**
	 * @return array<int, array{0: int, 1: float, 2: float, 3: float}> [time, x, y, z]
	 * @throws \UnexpectedValueException on data that is not a valid chunk
	 */
	public static function decode(string $data): array {
		$raw = @gzinflate($data);
		if($raw === false)
			throw new \UnexpectedValueException('Not a valid accelerometer chunk');
		$offset = 0;
		$count = self::readVarint($raw, $offset);
		$columns = [];
		for($c = 0; $c < 4; ++$c) {
			$value = 0;
			$column = [];
			for($i = 0; $i < $count; ++$i) {
				$value += self::unzigzag(self::readVarint($raw, $offset));
				$column[] = $value;
			}
			$columns[] = $column;
		}
		$samples = [];
		for($i = 0; $i < $count; ++$i) {
			$samples[] = [$columns[0][$i], $columns[1][$i] / (float) self::SCALE, $columns[2][$i] / (float) self::SCALE, $columns[3][$i] / (float) self::SCALE];
		}
		return $samples;
	}

	private static function zigzag(int $v): int {
		return $v >= 0 ? $v << 1 : ((-$v) << 1) - 1;
	}

	private static function unzigzag(int $v): int {
		return ($v & 1) ? -(($v + 1) >> 1) : $v >> 1;
	}

	private static function varint(int $v): string {
		$out = '';
		while($v >= 0x80) {
			$out .= chr(($v & 0x7f) | 0x80);
			$v >>= 7;
		}
		return $out . chr($v);
	}

	private static function readVarint(string $raw, int &$offset): int {
		$value = 0;
		$shift = 0;
		$length = strlen($raw);
		while(true) {
			if($offset >= $length || $shift > 63)
				throw new \UnexpectedValueException('Truncated accelerometer chunk');
			$byte = ord($raw[$offset++]);
			$value |= ($byte & 0x7f) << $shift;
			if($byte < 0x80)
				return $value;
			$shift += 7;
		}
	}
}
