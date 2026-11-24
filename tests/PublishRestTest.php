<?php
/**
 * Publishing a transcript as a page, in classic menus and block navigation.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

final class PublishRestTest extends RestTestCase {

	private int $id;

	public function set_up(): void {
		parent::set_up();
		$this->id = $this->job( array( 'title' => 'Raad 12 mei' ) );
	}

	/** @param array<string, mixed> $params */
	private function publish( array $params = array() ): \WP_REST_Response {
		return $this->rest( 'POST', '/jobs/' . $this->id . '/publish', $params );
	}

	private function navigation( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Hoofdmenu',
				'post_content' => $content,
			)
		);
	}

	/** @return array<int, int> IDs of the pages linked from a navigation, in order and depth-first. */
	private static function linked_pages( int $navigation_id ): array {
		$ids  = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$ids ): void {
			foreach ( $blocks as $block ) {
				if ( 'core/navigation-link' === $block['blockName'] ) {
					$ids[] = (int) $block['attrs']['id'];
					if ( isset( $block['attrs']['avpvhAtFresh'] ) ) {
						$ids[] = -1;
					}
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( get_post( $navigation_id )->post_content ) );
		return $ids;
	}

	public function test_publishes_a_draft_page_with_the_shortcode(): void {
		$result = $this->assertRestOk( $this->publish() );

		$page = get_post( $result['page_id'] );
		$this->assertSame( 'draft', $page->post_status );
		$this->assertSame( 'Raad 12 mei', $page->post_title );
		$this->assertSame( '[avpvh_audio_transcriptie id="' . $this->id . '"]', $page->post_content );
		$this->assertSame( 0, $result['menu_item_id'] );
	}

	public function test_republishing_updates_the_same_page(): void {
		$first  = $this->assertRestOk( $this->publish() );
		$second = $this->assertRestOk(
			$this->publish(
				array(
					'status' => 'publish',
					'title'  => 'Nieuwe titel',
				)
			)
		);

		$this->assertSame( $first['page_id'], $second['page_id'] );
		$this->assertSame( 'publish', $second['status'] );
		$this->assertSame( 'Nieuwe titel', get_the_title( $second['page_id'] ) );
	}

	public function test_only_page_publishers_may_publish(): void {
		$this->as( 'author' );

		$this->assertSame( 403, $this->publish()->get_status() );
	}

	public function test_editors_may_not_change_menus(): void {
		$menu = wp_create_nav_menu( 'Hoofdmenu' );
		$this->as( 'editor' );

		$this->assertRestError(
			403,
			'avpvh_at_menu_forbidden',
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'classic:' . $menu,
				)
			)
		);
		$this->assertRestOk( $this->publish() );
	}

	public function test_menus_need_a_published_page(): void {
		$menu = wp_create_nav_menu( 'Hoofdmenu' );

		$this->assertRestError( 400, 'avpvh_at_menu_requires_publish', $this->publish( array( 'menu_ref' => 'classic:' . $menu ) ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'any',
				)
			),
			'No page is created.'
		);
	}

	public function test_adds_the_page_to_a_classic_menu_under_a_parent(): void {
		$menu   = wp_create_nav_menu( 'Hoofdmenu' );
		$parent = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Raad',
				'menu-item-url'    => 'https://example.org/raad',
				'menu-item-status' => 'publish',
			)
		);

		$result = $this->assertRestOk(
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'classic:' . $menu,
					'parent_ref' => 'classic:' . $parent,
				)
			)
		);

		$items = wp_get_nav_menu_items( $menu );
		$this->assertCount( 2, $items );
		$item = get_post( $result['menu_item_id'] );
		$this->assertSame( (string) $parent, get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ) );
		$this->assertSame( (string) $result['page_id'], get_post_meta( $item->ID, '_menu_item_object_id', true ) );

		// Republishing updates the same menu item.
		$again = $this->assertRestOk(
			$this->publish(
				array(
					'status'  => 'publish',
					'menu_id' => $menu,
				)
			)
		);
		$this->assertSame( $result['menu_item_id'], $again['menu_item_id'] );
		$this->assertCount( 2, wp_get_nav_menu_items( $menu ) );
	}

	public function test_rejects_a_parent_from_another_menu(): void {
		$menu  = wp_create_nav_menu( 'Hoofdmenu' );
		$other = wp_create_nav_menu( 'Voettekst' );
		$item  = wp_update_nav_menu_item(
			$other,
			0,
			array(
				'menu-item-title'  => 'Elders',
				'menu-item-url'    => 'https://example.org/',
				'menu-item-status' => 'publish',
			)
		);

		$this->assertRestError(
			400,
			'avpvh_at_parent_missing',
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'classic:' . $menu,
					'parent_ref' => 'classic:' . $item,
				)
			)
		);
	}

	public function test_rejects_a_deleted_menu(): void {
		$this->assertRestError(
			404,
			'avpvh_at_menu_missing',
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'classic:999999',
				)
			)
		);
	}

	public function test_adds_the_page_to_a_block_navigation_submenu(): void {
		$navigation = $this->navigation( '<!-- wp:navigation-link {"label":"Thuis","id":1} /--><!-- wp:navigation-submenu {"label":"Raad"} --><!-- wp:navigation-link {"label":"Agenda","id":2} /--><!-- /wp:navigation-submenu -->' );

		$result = $this->assertRestOk(
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'block:' . $navigation,
					'parent_ref' => 'block:1',
				)
			)
		);

		$this->assertSame( $navigation, $result['navigation_id'] );
		$this->assertSame( array( 1, 2, $result['page_id'] ), self::linked_pages( $navigation ), 'Linked once, without the temporary marker.' );
		$this->assertStringContainsString( '"label":"Raad 12 mei"', get_post( $navigation )->post_content );

		// Moving it to the top level removes the submenu link.
		$this->assertRestOk(
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'block:' . $navigation,
					'parent_ref' => '',
				)
			)
		);
		$this->assertSame( array( 1, 2, $result['page_id'] ), self::linked_pages( $navigation ) );
		$blocks = parse_blocks( get_post( $navigation )->post_content );
		$this->assertSame( 'core/navigation-link', $blocks[2]['blockName'] );
		$this->assertCount( 1, $blocks[1]['innerBlocks'] );
	}

	public function test_rejects_a_navigation_path_that_no_longer_exists(): void {
		$navigation = $this->navigation( '<!-- wp:navigation-link {"label":"Thuis","id":1} /-->' );

		$this->assertRestError(
			400,
			'avpvh_at_navigation_parent_missing',
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'block:' . $navigation,
					'parent_ref' => 'block:4',
				)
			)
		);
	}

	public function test_rejects_a_deleted_navigation(): void {
		$page = self::factory()->post->create();

		$this->assertRestError(
			404,
			'avpvh_at_navigation_missing',
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'block:' . $page,
				)
			)
		);
	}

	public function test_moving_between_classic_menu_and_block_navigations_removes_the_old_link(): void {
		$menu  = wp_create_nav_menu( 'Hoofdmenu' );
		$first = $this->navigation( '<!-- wp:navigation-link {"label":"Thuis","id":1} /-->' );
		$other = $this->navigation( '' );

		$classic = $this->assertRestOk(
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'classic:' . $menu,
				)
			)
		);
		$page    = $classic['page_id'];

		// Classic menu → block navigation: the menu item goes.
		$this->assertRestOk(
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'block:' . $first,
				)
			)
		);
		$this->assertNull( get_post( $classic['menu_item_id'] ) );
		$this->assertSame( array( 1, $page ), self::linked_pages( $first ) );

		// One block navigation → another: the old link goes.
		$this->assertRestOk(
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'block:' . $other,
				)
			)
		);
		$this->assertSame( array( 1 ), self::linked_pages( $first ) );
		$this->assertSame( array( $page ), self::linked_pages( $other ) );

		// Block navigation → classic menu: the block link goes.
		$back = $this->assertRestOk(
			$this->publish(
				array(
					'status'   => 'publish',
					'menu_ref' => 'classic:' . $menu,
				)
			)
		);
		$this->assertSame( array(), self::linked_pages( $other ) );
		$this->assertSame( 0, $back['navigation_id'] );
		$this->assertSame( '', get_post_meta( $page, '_avpvh_at_navigation_id', true ) );
	}

	public function test_publishing_options_list_menus_and_the_existing_page(): void {
		$menu       = wp_create_nav_menu( 'Hoofdmenu' );
		$parent     = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => '<b>Raad</b>',
				'menu-item-url'    => 'https://example.org/raad',
				'menu-item-status' => 'publish',
			)
		);
		$navigation = $this->navigation( '<!-- wp:navigation-submenu {"label":"Raad"} /-->' );
		$published  = $this->assertRestOk(
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'classic:' . $menu,
					'parent_ref' => 'classic:' . $parent,
				)
			)
		);

		$options = $this->assertRestOk( $this->rest( 'GET', '/publishing-options', array( 'job_id' => $this->id ) ) );

		$this->assertTrue( $options['can_manage_menus'] );
		$this->assertSame( 'classic:' . $menu, $options['menus'][0]['value'] );
		$this->assertSame( 'Raad', $options['menus'][0]['items'][0]['title'] );
		$this->assertSame( 'classic:' . $parent, $options['menus'][0]['items'][0]['value'] );
		$this->assertSame( 'block:' . $navigation, $options['block_menus'][0]['value'] );
		$this->assertSame( 'block:0', $options['block_menus'][0]['items'][0]['value'] );

		$existing = $options['existing_page'];
		$this->assertSame( $published['page_id'], $existing['id'] );
		$this->assertSame( 'publish', $existing['status'] );
		$this->assertSame( $menu, $existing['menu_id'] );
		$this->assertSame( 'classic:' . $menu, $existing['menu_ref'] );
		$this->assertSame( 'classic:' . $parent, $existing['parent_ref'] );
		$this->assertSame( 'classic:' . $published['menu_item_id'], $existing['menu_item_ref'] );
	}

	public function test_publishing_options_for_a_page_in_block_navigation(): void {
		$navigation = $this->navigation( '<!-- wp:navigation-submenu {"label":"Raad"} /-->' );
		$this->assertRestOk(
			$this->publish(
				array(
					'status'     => 'publish',
					'menu_ref'   => 'block:' . $navigation,
					'parent_ref' => 'block:0',
				)
			)
		);

		$existing = $this->assertRestOk( $this->rest( 'GET', '/publishing-options', array( 'job_id' => $this->id ) ) )['existing_page'];

		$this->assertSame( 'block:' . $navigation, $existing['menu_ref'] );
		$this->assertSame( 'block:0', $existing['parent_ref'] );
		$this->assertSame( 0, $existing['menu_item'] );
	}

	public function test_publishing_options_without_menu_rights_or_page(): void {
		wp_create_nav_menu( 'Hoofdmenu' );
		$this->as( 'editor' );

		$options = $this->assertRestOk( $this->rest( 'GET', '/publishing-options', array( 'job_id' => $this->id ) ) );

		$this->assertFalse( $options['can_manage_menus'] );
		$this->assertSame( array(), $options['menus'] );
		$this->assertNull( $options['existing_page'] );
	}
}
