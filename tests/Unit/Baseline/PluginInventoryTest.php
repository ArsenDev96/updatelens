<?php
/**
 * Tests for the baseline plugin inventory.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use UpdateLens\Baseline\PluginInventory;

/**
 * Inventory records built from get_plugins() data.
 */
final class PluginInventoryTest extends TestCase {

	const SELF = 'updatelens/updatelens.php';

	const FAKE_SECRET = 'sk_test_UPDATE_LENS_BASELINE_SECRET';

	/**
	 * Header data shaped like get_plugins() returns it.
	 *
	 * @param string $name    Name.
	 * @param string $version Version.
	 * @return array<string, string>
	 */
	private static function headers( $name, $version ) {
		return array(
			'Name'        => $name,
			'PluginURI'   => 'https://example.test/plugin?key=' . self::FAKE_SECRET,
			'Version'     => $version,
			'Description' => 'Licensed to ' . self::FAKE_SECRET,
			'Author'      => 'Someone',
			'AuthorURI'   => 'https://example.test/author',
			'UpdateURI'   => 'https://updates.example.test/' . self::FAKE_SECRET,
			'TextDomain'  => 'x',
			'Network'     => false,
		);
	}

	/**
	 * Name, version and active state per plugin; nothing else.
	 */
	public function test_keeps_only_file_name_version_and_active_state() {
		$records = PluginInventory::build(
			array(
				'woocommerce/woocommerce.php'       => self::headers( 'WooCommerce', '11.1.2' ),
				'classic-editor/classic-editor.php' => self::headers( 'Classic Editor', '1.7.0' ),
			),
			array( 'woocommerce/woocommerce.php' ),
			self::SELF
		);

		$this->assertSame(
			array(
				array(
					'file'    => 'classic-editor/classic-editor.php',
					'name'    => 'Classic Editor',
					'version' => '1.7.0',
					'active'  => false,
				),
				array(
					'file'    => 'woocommerce/woocommerce.php',
					'name'    => 'WooCommerce',
					'version' => '11.1.2',
					'active'  => true,
				),
			),
			$records
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test output.
		$json = json_encode( $records );
		$this->assertStringNotContainsString( self::FAKE_SECRET, $json );
		$this->assertStringNotContainsString( 'example.test', $json );
	}

	/**
	 * UpdateLens itself is not part of the inventory.
	 */
	public function test_excludes_updatelens_itself() {
		$records = PluginInventory::build(
			array(
				self::SELF           => self::headers( 'UpdateLens', '0.2.0' ),
				'hello.php'          => self::headers( 'Hello Dolly', '1.7.2' ),
				'updatelens-x/x.php' => self::headers( 'UpdateLens', '9.9.9' ),
			),
			array( self::SELF, 'hello.php' ),
			self::SELF
		);

		$this->assertSame( array( 'hello.php', 'updatelens-x/x.php' ), array_column( $records, 'file' ) );
	}

	/**
	 * Sorted by name (natural, case-insensitive), then file; not by active state.
	 */
	public function test_sorts_by_name_then_file() {
		$records = PluginInventory::build(
			array(
				'z/z.php' => self::headers( 'zeta', '1' ),
				'b/b.php' => self::headers( 'Plugin 10', '1' ),
				'a/a.php' => self::headers( 'Plugin 9', '1' ),
				'c/c.php' => self::headers( 'alpha', '1' ),
				'd/d.php' => self::headers( 'Alpha', '1' ),
				'e/e.php' => self::headers( 'Beta', '1' ),
			),
			array( 'e/e.php', 'z/z.php' ),
			self::SELF
		);

		$this->assertSame( array( 'c/c.php', 'd/d.php', 'e/e.php', 'a/a.php', 'b/b.php', 'z/z.php' ), array_column( $records, 'file' ) );
	}

	/**
	 * Header text is cleaned; a missing name falls back to the file.
	 */
	public function test_cleans_header_text() {
		$records = PluginInventory::build(
			array(
				'tags/tags.php'   => self::headers( '<b>Bold</b> Plugin', "1.0\n" ),
				'empty/empty.php' => self::headers( '  ', '' ),
				'long/long.php'   => self::headers( str_repeat( 'é', 300 ), str_repeat( '1', 100 ) ),
				'bad/bad.php'     => self::headers( "\xff\xfe", "\xff" ),
				'none/none.php'   => array(),
			),
			array(),
			self::SELF
		);

		$by_file = array_column( $records, null, 'file' );
		$this->assertSame( 'Bold Plugin', $by_file['tags/tags.php']['name'] );
		$this->assertSame( '1.0', $by_file['tags/tags.php']['version'] );
		$this->assertSame( 'empty/empty.php', $by_file['empty/empty.php']['name'] );
		$this->assertSame( '', $by_file['empty/empty.php']['version'] );
		$this->assertSame( str_repeat( 'é', 255 ), $by_file['long/long.php']['name'] );
		$this->assertSame( str_repeat( '1', 64 ), $by_file['long/long.php']['version'] );
		$this->assertSame( 'bad/bad.php', $by_file['bad/bad.php']['name'] );
		$this->assertSame( '', $by_file['bad/bad.php']['version'] );
		$this->assertSame( 'none/none.php', $by_file['none/none.php']['name'] );
	}

	/**
	 * Entries that are not plugin files are skipped.
	 */
	public function test_skips_invalid_plugin_files() {
		$records = PluginInventory::build(
			array(
				'../evil.php'       => self::headers( 'Evil', '1' ),
				'a/b/c.php'         => self::headers( 'Deep', '1' ),
				'readme.txt'        => self::headers( 'Text', '1' ),
				'ok.php'            => self::headers( 'OK', '1' ),
				'not-headers/x.php' => 'string',
			),
			array(),
			self::SELF
		);

		$this->assertSame( array( 'ok.php' ), array_column( $records, 'file' ) );
	}

	/**
	 * No plugins, no records.
	 */
	public function test_empty_inventory() {
		$this->assertSame( array(), PluginInventory::build( array(), array(), self::SELF ) );
	}
}
