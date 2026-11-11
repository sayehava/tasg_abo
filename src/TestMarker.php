<?php
/**
 * Marks orders that come from a subscription test: a subscription made during the
 * test phase, or from a test plan. The orders are real (real invoice, real
 * automation), the marker tells you and your automation that it is a test.
 *
 * On every marked order:
 *   - order meta  _tasg_abo_test = yes   (for invoice and automation tools)
 *   - a private order note
 *   - optionally a line in the customer note (for invoices that print the note)
 *
 * @package TasgAbo
 */

namespace TasgAbo;

defined( 'ABSPATH' ) || exit;

final class TestMarker {

	public const META = '_tasg_abo_test';

	public static function apply( \WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( self::META ) ) {
			return;
		}

		$order->update_meta_data( self::META, 'yes' );

		$text = Settings::test_marker_text();

		if ( Settings::test_marker_in_note() && '' !== $text && false === strpos( (string) $order->get_customer_note(), $text ) ) {
			$order->set_customer_note( trim( $order->get_customer_note() . "\n" . $text ) );
		}

		$order->add_order_note( __( 'Test order (subscription test): the order is real, but made to test the subscription and the automation.', 'tasg-abo' ) );
		$order->save();
	}
}
