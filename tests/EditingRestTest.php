<?php
/**
 * Saving corrections, revision history and restoring revisions.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

final class EditingRestTest extends RestTestCase {

	/** @param array<string, mixed> $params */
	private function save( int $id, array $params ): \WP_REST_Response {
		return $this->rest( 'POST', '/jobs/' . $id . '/segments', $params );
	}

	public function test_save_stores_corrections_and_marks_edited_segments(): void {
		$id = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'A' ), self::segment( 'b', 1, 'B' ) ) ) );

		$result = $this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision'  => 0,
					'dirty_ids' => array( 'a' ),
					'segments'  => array(
						self::segment(
							'a',
							0,
							"<b>Nieuw</b>\nregel",
							array(
								'speaker' => 'Voorzitter',
								'party'   => 'VVD',
							)
						),
						self::segment( 'b', 1, 'B' ),
						self::segment( 'a', 5, 'Dubbel' ),
						'geen segment',
					),
				)
			)
		);

		$this->assertSame(
			array(
				'ok'       => true,
				'revision' => 1,
			),
			$result
		);
		$segments = $this->segments( $id );
		$this->assertSame( array( 'a', 'b' ), array_column( $segments, 'id' ) );
		$this->assertSame( "Nieuw\nregel", $segments[0]['text'] );
		$this->assertSame( 'Voorzitter', $segments[0]['speaker'] );
		$this->assertSame( array( true, false ), array_column( $segments, 'manual' ) );
		$this->assertSame( '1', $this->row( $id )->revision );
	}

	public function test_save_keeps_earlier_manual_flags(): void {
		$id = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'A', array( 'manual' => true ) ) ) ) );

		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision' => 0,
					'segments' => array( self::segment( 'a', 0, 'A' ) ),
				)
			)
		);

		$this->assertTrue( $this->segments( $id )[0]['manual'] );
	}

	public function test_save_records_deletions_and_keeps_segments_the_browser_has_not_seen(): void {
		$id = $this->job(
			array(
				'segments'    => array(
					self::segment( 'a', 0, 'A' ),
					self::segment( 'b', 1, 'B' ),
					self::segment( 'late', 0.5, 'Net binnen' ),
				),
				'deleted_ids' => array( 'old' ),
			)
		);

		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision'    => 0,
					'segments'    => array( self::segment( 'a', 0, 'A' ), self::segment( 'b', 1, 'B' ) ),
					'deleted_ids' => array( 'b', '' ),
				)
			)
		);

		$this->assertSame( array( 'a', 'late' ), array_column( $this->segments( $id ), 'id' ) );
		$this->assertSame( array( 'old', 'b' ), json_decode( $this->row( $id )->deleted_ids, true ) );
	}

	public function test_save_never_restores_a_deleted_segment_from_the_browser(): void {
		$id = $this->job( array( 'deleted_ids' => array( 'b' ) ) );

		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision' => 0,
					'segments' => array( self::segment( 'b', 1, 'B' ) ),
				)
			)
		);

		$this->assertSame( array(), $this->segments( $id ) );
	}

	public function test_stale_revision_is_a_conflict_with_the_current_job(): void {
		$id = $this->job(
			array(
				'revision' => 3,
				'segments' => array( self::segment( 'a', 0, 'Huidig' ) ),
			)
		);

		$response = $this->save(
			$id,
			array(
				'revision' => 2,
				'segments' => array(),
			)
		);

		$this->assertRestError( 409, 'avpvh_at_revision_conflict', $response );
		$current = $response->get_data()['data']['current'];
		$this->assertSame( 3, $current['revision'] );
		$this->assertSame( 'Huidig', $current['segments'][0]['text'] );
	}

	public function test_save_rejects_non_list_segments(): void {
		$id = $this->job();

		$this->assertRestError(
			400,
			'avpvh_at_bad_segments',
			$this->save(
				$id,
				array(
					'revision' => 0,
					'segments' => 'tekst',
				)
			)
		);
	}

	public function test_readers_cannot_save(): void {
		$id = $this->job();
		$this->as( 'subscriber' );

		$this->assertSame(
			403,
			$this->save(
				$id,
				array(
					'revision' => 0,
					'segments' => array(),
				)
			)->get_status()
		);
	}

	public function test_history_lists_saved_revisions_newest_first(): void {
		$id = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'Eerste' ) ) ) );
		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision' => 0,
					'segments' => array( self::segment( 'a', 0, 'Tweede' ) ),
				)
			)
		);
		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision' => 1,
					'segments' => array( self::segment( 'a', 0, 'Derde' ) ),
				)
			)
		);
		$this->as( 'subscriber' );

		$history = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id . '/history' ) );

		$this->assertSame( array( '1', '0' ), array_column( $history, 'revision' ) );
		$this->assertSame( (string) self::$users['administrator'], $history[0]['user_id'] );
	}

	public function test_old_revisions_are_trimmed(): void {
		global $wpdb;
		$id = $this->job();
		for ( $revision = 0; $revision < 55; $revision++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'avpvh_at_revisions',
				array(
					'job_id'     => $id,
					'revision'   => $revision,
					'user_id'    => 1,
					'segments'   => '[]',
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		$wpdb->update( $wpdb->prefix . 'avpvh_at_jobs', array( 'revision' => 55 ), array( 'id' => $id ) );

		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision' => 55,
					'segments' => array(),
				)
			)
		);

		$kept = $wpdb->get_col( $wpdb->prepare( "SELECT revision FROM {$wpdb->prefix}avpvh_at_revisions WHERE job_id = %d ORDER BY id", $id ) );
		$this->assertCount( 50, $kept );
		$this->assertSame( '55', end( $kept ) );
	}

	public function test_restore_brings_back_a_revision_and_its_deleted_segments(): void {
		$id = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'A' ), self::segment( 'b', 1, 'B' ) ) ) );
		$this->assertRestOk(
			$this->save(
				$id,
				array(
					'revision'    => 0,
					'segments'    => array( self::segment( 'a', 0, 'Fout' ) ),
					'deleted_ids' => array( 'b' ),
				)
			)
		);

		$job = $this->assertRestOk( $this->rest( 'POST', '/jobs/' . $id . '/restore/0' ) );

		$this->assertSame( array( 'A', 'B' ), array_column( $job['segments'], 'text' ) );
		$this->assertSame( 2, $job['revision'] );
		$this->assertSame( array(), json_decode( $this->row( $id )->deleted_ids, true ) );

		// The replaced version is kept, so the restore can be undone.
		$history = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id . '/history' ) );
		$this->assertSame( array( '1', '0' ), array_column( $history, 'revision' ) );
	}

	public function test_restore_of_an_unknown_revision(): void {
		$id = $this->job();

		$this->assertRestError( 404, 'avpvh_at_revision_missing', $this->rest( 'POST', '/jobs/' . $id . '/restore/7' ) );
	}
}
