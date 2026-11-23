<?php
/**
 * Merging, sorting, sanitizing and decoding transcript segments.
 *
 * @package AVPVH_Audio_Transcriptie
 */

declare(strict_types=1);

namespace AVPVH_AT\Tests;

final class SegmentsTest extends TestCase {

	/** @param array<int, array<string, mixed>> $segments */
	private static function ids( array $segments ): array {
		return array_column( $segments, 'id' );
	}

	public function test_merge_keeps_manual_edits_over_remote_text(): void {
		$local  = array(
			array( 'id' => 'a', 'start' => 0, 'text' => 'Gecorrigeerd', 'speaker' => 'Voorzitter', 'party' => 'Bestuur', 'manual' => true ),
		);
		$remote = array(
			array( 'id' => 'a', 'start' => 0, 'end' => 2, 'text' => 'Ruw', 'speaker' => 'S1', 'party' => '' ),
		);

		$merged = self::call( 'merge_segments', $local, $remote );

		$this->assertCount( 1, $merged );
		$this->assertSame( 'Gecorrigeerd', $merged[0]['text'] );
		$this->assertSame( 'Voorzitter', $merged[0]['speaker'] );
		$this->assertSame( 'Bestuur', $merged[0]['party'] );
		$this->assertTrue( $merged[0]['manual'] );
		$this->assertSame( 2.0, $merged[0]['end'], 'Timing still comes from the service.' );
	}

	public function test_merge_takes_remote_text_for_unedited_segments(): void {
		$local  = array( array( 'id' => 'a', 'start' => 0, 'text' => 'Oud', 'manual' => false ) );
		$remote = array( array( 'id' => 'a', 'start' => 0, 'text' => 'Nieuw' ) );

		$this->assertSame( 'Nieuw', self::call( 'merge_segments', $local, $remote )[0]['text'] );
	}

	public function test_merge_drops_deleted_segments_even_when_the_service_returns_them(): void {
		$local  = array( array( 'id' => 'b', 'start' => 1, 'text' => 'B', 'manual' => true ) );
		$remote = array(
			array( 'id' => 'a', 'start' => 0, 'text' => 'A' ),
			array( 'id' => 'b', 'start' => 1, 'text' => 'B' ),
			array( 'id' => 7, 'start' => 2, 'text' => 'Zeven' ),
		);

		$merged = self::call( 'merge_segments', $local, $remote, array( 'b', 7 ) );

		$this->assertSame( array( 'a' ), self::ids( $merged ) );
	}

	public function test_merge_keeps_inserted_manual_segments_in_time_order(): void {
		$local  = array(
			array( 'id' => 'x', 'start' => 1.5, 'text' => 'Ingevoegd', 'manual' => true, 'inserted' => true ),
			array( 'id' => 'gone', 'start' => 3, 'text' => 'Niet meer bij de dienst', 'manual' => true ),
			array( 'id' => 'auto', 'start' => 4, 'text' => 'Ongewijzigd' ),
		);
		$remote = array(
			array( 'id' => 'a', 'start' => 0, 'text' => 'A' ),
			array( 'id' => 'b', 'start' => 2, 'text' => 'B' ),
			'not a segment',
		);

		$merged = self::call( 'merge_segments', $local, $remote );

		$this->assertSame( array( 'a', 'x', 'b' ), self::ids( $merged ) );
		$this->assertTrue( $merged[1]['inserted'] );
	}

	public function test_merge_drops_deleted_inserted_segments(): void {
		$local = array( array( 'id' => 'x', 'start' => 1, 'manual' => true, 'inserted' => true ) );

		$this->assertSame( array(), self::call( 'merge_segments', $local, array(), array( 'x' ) ) );
	}

	public function test_sort_is_stable_for_equal_start_times(): void {
		$segments = array(
			array( 'id' => '1', 'start' => 5 ),
			array( 'id' => '2', 'start' => 1 ),
			array( 'id' => '3', 'start' => '5.0' ),
			array( 'id' => '4', 'start' => 1 ),
		);
		self::call_with( 'sort_segments', array( &$segments ) );

		$this->assertSame( array( '2', '4', '1', '3' ), self::ids( $segments ) );
		$this->assertSame( array( 0, 1, 2, 3 ), array_keys( $segments ) );
	}

	public function test_sort_accepts_an_empty_list(): void {
		$segments = array();
		self::call_with( 'sort_segments', array( &$segments ) );

		$this->assertSame( array(), $segments );
	}

	public function test_sanitize_remote_segment_normalizes_untrusted_fields(): void {
		$segment = self::call(
			'sanitize_remote_segment',
			array(
				'id'       => " a1\n",
				'start'    => -3,
				'end'      => '4.5',
				'text'     => "<b>Hallo</b>\nwereld",
				'speaker'  => '<i>S1</i>',
				'manual'   => 1,
				'inserted' => 0,
				'extra'    => 'weg',
			)
		);

		$this->assertSame(
			array(
				'id'       => 'a1',
				'start'    => 0,
				'end'      => 4.5,
				'text'     => "Hallo\nwereld",
				'speaker'  => 'S1',
				'party'    => '',
				'manual'   => true,
				'inserted' => false,
			),
			$segment
		);
	}

	public function test_decode_segments_falls_back_to_an_empty_list(): void {
		$this->assertSame( array( array( 'id' => 'a' ) ), self::call( 'decode_segments', '[{"id":"a"}]' ) );
		$this->assertSame( array(), self::call( 'decode_segments', null ) );
		$this->assertSame( array(), self::call( 'decode_segments', '' ) );
		$this->assertSame( array(), self::call( 'decode_segments', '{broken' ) );
		$this->assertSame( array(), self::call( 'decode_segments', '"text"' ) );
	}
}
