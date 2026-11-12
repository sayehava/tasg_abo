<?php
/**
 * Elementor widgets, so the cancel page and the cancel button can be built in the
 * site's own design. The hooks only fire when Elementor is active.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Elementor;

use TasgAbo\Log;

defined( 'ABSPATH' ) || exit;

final class Integration {

	public const CATEGORY = 'tasg-abo';

	public function register(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'add_widgets' ) );
	}

	/**
	 * @param object $manager Elementor's elements manager.
	 */
	public function add_category( $manager ): void {
		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Tasg Abo', 'tasg-abo' ),
				'icon'  => 'eicon-sync',
			)
		);
	}

	/**
	 * @param object $widgets Elementor's widgets manager.
	 */
	public function add_widgets( $widgets ): void {
		try {
			$widgets->register( new CancelFormWidget() );
			$widgets->register( new CancelButtonWidget() );
		} catch ( \Throwable $e ) {
			Log::error( 'Could not register the Elementor widgets: ' . $e->getMessage() );
		}
	}
}
