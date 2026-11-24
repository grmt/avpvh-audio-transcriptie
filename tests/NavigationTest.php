<?php
/**
 * Placing and pruning transcript page links in block navigation.
 *
 * @package AVPVH_Audio_Transcriptie
 */

declare(strict_types=1);

namespace AVPVH_AT\Tests;

final class NavigationTest extends TestCase {

	/** @return array<string, mixed> */
	private static function link( int $page_id, string $label = '', bool $fresh = false ): array {
		$attrs = array(
			'id'    => $page_id,
			'label' => '' === $label ? 'Pagina ' . $page_id : $label,
		);
		if ( $fresh ) {
			$attrs['avpvhAtFresh'] = true;
		}
		return array(
			'blockName'    => 'core/navigation-link',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function submenu( string $label, array $children ): array {
		return array(
			'blockName'    => 'core/navigation-submenu',
			'attrs'        => array( 'label' => $label ),
			'innerBlocks'  => $children,
			'innerHTML'    => '<li><ul></ul></li>',
			'innerContent' => array_merge( array( '<li><ul>' ), array_fill( 0, count( $children ), null ), array( '</ul></li>' ) ),
		);
	}

	/**
	 * Places a link and prunes, as publish_page() does.
	 *
	 * @param array<int, mixed> $blocks
	 * @return array{bool, array<int, mixed>}
	 */
	private static function publish( array $blocks, string $path, int $page_id, string $label ): array {
		$placed = self::call_with( 'place_navigation_link', array( &$blocks, $path, self::link( $page_id, $label, true ), $page_id ) );
		return array( $placed, self::call( 'prune_navigation_links', $blocks, $page_id ) );
	}

	/**
	 * @param array<int, mixed> $blocks
	 * @return array<int, string>
	 */
	private static function labels( array $blocks ): array {
		return array_map( static fn ( array $block ): string => (string) $block['attrs']['label'], $blocks );
	}

	public function test_is_page_link_matches_only_links_to_the_page(): void {
		$this->assertTrue( self::call( 'is_page_link', self::link( 7 ), 7 ) );
		$this->assertTrue(
			self::call(
				'is_page_link',
				array(
					'blockName' => 'core/navigation-link',
					'attrs'     => array( 'id' => '7' ),
				),
				7
			)
		);
		$this->assertFalse( self::call( 'is_page_link', self::link( 8 ), 7 ) );
		$this->assertFalse( self::call( 'is_page_link', self::submenu( 'Sub', array() ), 0 ) );
		$this->assertFalse( self::call( 'is_page_link', array( 'blockName' => 'core/navigation-link' ), 7 ) );
		$this->assertFalse( self::call( 'is_page_link', null, 7 ) );
	}

	public function test_adds_a_link_at_the_root(): void {
		[ $placed, $blocks ] = self::publish( array( self::link( 3 ) ), '', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertSame( array( 'Pagina 3', 'Nieuw' ), self::labels( $blocks ) );
		$this->assertArrayNotHasKey( 'avpvhAtFresh', $blocks[1]['attrs'] );
	}

	public function test_updates_an_existing_root_link_instead_of_duplicating_it(): void {
		[ $placed, $blocks ] = self::publish( array( self::link( 7, 'Oud' ), self::link( 3 ) ), '', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertSame( array( 'Nieuw', 'Pagina 3' ), self::labels( $blocks ) );
	}

	public function test_moves_a_link_from_the_root_into_a_submenu(): void {
		$blocks = array( self::link( 7, 'Oud' ), self::submenu( 'Sub', array( self::link( 3 ) ) ) );

		[ $placed, $blocks ] = self::publish( $blocks, '1', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertCount( 1, $blocks );
		$this->assertSame( array( 'Pagina 3', 'Nieuw' ), self::labels( $blocks[0]['innerBlocks'] ) );
		$this->assertSame( array( '<li><ul>', null, null, '</ul></li>' ), $blocks[0]['innerContent'] );
	}

	public function test_moves_a_link_out_of_a_submenu_to_the_root(): void {
		$blocks = array( self::submenu( 'Sub', array( self::link( 3 ), self::link( 7, 'Oud' ) ) ) );

		[ $placed, $blocks ] = self::publish( $blocks, '', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertSame( array( 'Sub', 'Nieuw' ), self::labels( $blocks ) );
		$this->assertSame( array( 'Pagina 3' ), self::labels( $blocks[0]['innerBlocks'] ) );
		$this->assertSame( array( '<li><ul>', null, '</ul></li>' ), $blocks[0]['innerContent'] );
	}

	public function test_updates_a_link_in_place_inside_a_nested_submenu(): void {
		$inner  = self::submenu( 'Binnen', array( self::link( 7, 'Oud' ), self::link( 4 ) ) );
		$blocks = array( self::link( 1 ), self::submenu( 'Buiten', array( self::link( 2 ), $inner ) ) );

		[ $placed, $blocks ] = self::publish( $blocks, '1.1', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertSame( array( 'Nieuw', 'Pagina 4' ), self::labels( $blocks[1]['innerBlocks'][1]['innerBlocks'] ) );
		$this->assertSame( array( '<li><ul>', null, null, '</ul></li>' ), $blocks[1]['innerBlocks'][1]['innerContent'] );
	}

	public function test_appends_inside_an_empty_submenu_before_its_closing_markup(): void {
		$blocks = array( self::submenu( 'Leeg', array() ) );

		[ $placed, $blocks ] = self::publish( $blocks, '0', 7, 'Nieuw' );

		$this->assertTrue( $placed );
		$this->assertSame( array( 'Nieuw' ), self::labels( $blocks[0]['innerBlocks'] ) );
		$this->assertSame( array( '<li><ul>', '</ul></li>', null ), $blocks[0]['innerContent'] );
	}

	/** @return array<string, array{string}> */
	public static function invalid_paths(): array {
		return array(
			'not numeric'        => array( 'abc' ),
			'trailing dot'       => array( '0.' ),
			'missing index'      => array( '5' ),
			'not a submenu'      => array( '0' ),
			'through a leaf'     => array( '0.0' ),
			'missing grandchild' => array( '1.9' ),
		);
	}

	/**
	 * @dataProvider invalid_paths
	 */
	public function test_rejects_paths_that_do_not_lead_to_a_submenu( string $path ): void {
		$blocks   = array( self::link( 1 ), self::submenu( 'Sub', array( self::link( 2 ) ) ) );
		$original = $blocks;

		$placed = self::call_with( 'place_navigation_link', array( &$blocks, $path, self::link( 7, 'Nieuw', true ), 7 ) );

		$this->assertFalse( $placed );
		$this->assertSame( $original, $blocks );
	}

	public function test_prune_without_a_fresh_link_removes_every_link_to_the_page(): void {
		$blocks = array(
			self::link( 7 ),
			self::submenu( 'Sub', array( self::link( 7 ), self::link( 3 ) ) ),
		);

		$pruned = self::call( 'prune_navigation_links', $blocks, 7 );

		$this->assertSame( array( 'Sub' ), self::labels( $pruned ) );
		$this->assertSame( array( 'Pagina 3' ), self::labels( $pruned[0]['innerBlocks'] ) );
		$this->assertSame( array( '<li><ul>', null, '</ul></li>' ), $pruned[0]['innerContent'] );
	}

	public function test_prune_drops_placeholders_without_a_matching_block(): void {
		$sub                 = self::submenu( 'Sub', array( self::link( 3 ) ) );
		$sub['innerContent'] = array( '<li><ul>', null, null, '</ul></li>' );

		$pruned = self::call( 'prune_navigation_links', array( $sub ), 7 );

		$this->assertSame( array( '<li><ul>', null, '</ul></li>' ), $pruned[0]['innerContent'] );
	}

	public function test_submenu_options_list_nested_submenus_with_paths(): void {
		$blocks = array(
			self::link( 1 ),
			self::submenu( '<em>Buiten</em>', array( self::link( 2 ), self::submenu( 'Binnen', array() ) ) ),
			array(
				'blockName' => 'core/navigation-submenu',
				'attrs'     => array(),
			),
		);

		$this->assertSame(
			array(
				array(
					'id'     => '1',
					'value'  => 'block:1',
					'title'  => 'Buiten',
					'parent' => 0,
				),
				array(
					'id'     => '1.1',
					'value'  => 'block:1.1',
					'title'  => '— Binnen',
					'parent' => 0,
				),
				array(
					'id'     => '2',
					'value'  => 'block:2',
					'title'  => 'Naamloos submenu',
					'parent' => 0,
				),
			),
			self::call( 'navigation_submenu_options', $blocks )
		);
	}
}
