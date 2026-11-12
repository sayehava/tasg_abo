<?php
/**
 * "Subscribe and save" controls on the product page: a checkbox and a plan
 * choice on one line, with a short text below that follows the chosen plan.
 *
 * The text can be changed in the settings (or per product) and may use these
 * placeholders, which are calculated from the actual price:
 *   {price} {regular_price} {saving} {saving_percent} {plan} {period}
 * The same values exist as shortcodes: [tasg_abo_price] [tasg_abo_regular_price]
 * [tasg_abo_saving] [tasg_abo_saving_percent] [tasg_abo_plan] [tasg_abo_period]
 *
 * @package TasgAbo
 */

namespace TasgAbo\Frontend;

use TasgAbo\Plans\Plan;
use TasgAbo\Plans\PlanRepository;
use TasgAbo\Pricing;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class ProductToggle {

	private const KEYS = array( 'price', 'regular_price', 'saving', 'saving_percent', 'plan', 'period' );

	/**
	 * Values of the plan being rendered, for the shortcodes.
	 *
	 * @var array<string,string>|null
	 */
	private static $context = null;

	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ) );

		foreach ( self::KEYS as $key ) {
			add_shortcode(
				'tasg_abo_' . $key,
				static function () use ( $key ) {
					return self::$context[ $key ] ?? '';
				}
			);
		}
	}

	/**
	 * Prices and savings for one plan of a product, as plain text.
	 *
	 * @return array<string,mixed>
	 */
	private function values( \WC_Product $product, Plan $plan ): array {
		$regular    = (float) $product->get_price();
		$subscriber = Pricing::subscriber_price( $product, $plan->id );
		$saving     = max( 0.0, $regular - $subscriber );
		$percent    = $regular > 0 ? (int) round( $saving / $regular * 100 ) : 0;
		$money      = static function ( float $value ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' );
		};

		return array(
			'price'          => $money( $subscriber ),
			'regular_price'  => $money( $regular ),
			'saving'         => $money( $saving ),
			'saving_percent' => number_format_i18n( $percent ) . ' %',
			'plan'           => $plan->title(),
			'period'         => $plan->period_label(),
			'_saving'        => $saving,
		);
	}

	/**
	 * The text under the checkbox for one plan: the product's own text, else the text
	 * from the settings (translatable with WPML String Translation), else the default.
	 */
	private function text( \WC_Product $product, Plan $plan ): string {
		$values   = $this->values( $product, $plan );
		$template = trim( (string) $product->get_meta( '_tasg_abo_box_text' ) );

		if ( '' === $template ) {
			$template = Settings::box_text_raw();

			if ( '' !== $template ) {
				$template = (string) apply_filters( 'wpml_translate_single_string', $template, PlanRepository::WPML_CONTEXT, 'subscribe-box-text' );
			}
		}

		if ( '' === $template ) {
			$template = $values['_saving'] > 0
				? __( 'You save {saving} ({saving_percent}) per order. Only this product stays in your cart.', 'tasg-abo' )
				: __( 'Only this product stays in your cart.', 'tasg-abo' );
		}

		foreach ( self::KEYS as $key ) {
			$template = str_replace( '{' . $key . '}', (string) $values[ $key ], $template );
		}

		self::$context = $values;
		$output        = do_shortcode( $template );
		self::$context = null;

		return wp_kses_post( $output );
	}

	/**
	 * Always shown for a plan with a minimum term, it is not part of the editable text.
	 */
	private function term_note( Plan $plan ): string {
		if ( '' === $plan->term_label() ) {
			return '';
		}

		/* translators: %s: length of the minimum term, for example "3 months" */
		return '<br><strong>' . esc_html( sprintf( __( 'Minimum term: %s.', 'tasg-abo' ), $plan->term_label() ) ) . '</strong>';
	}

	public function render(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! Pricing::is_enabled( $product ) ) {
			return;
		}

		$plans      = Pricing::plans( $product );
		$default_id = Pricing::default_plan_id( $plans, $product );

		echo '<div class="tasg-abo-toggle" style="margin:6px 0 8px;font-size:14px;line-height:1.35;text-align:left">';
		echo '<div style="display:flex;flex-wrap:nowrap;align-items:center;gap:8px">';
		echo '<label style="margin:0;display:inline-flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;flex:0 0 auto"><input type="checkbox" name="tasg_abo_subscribe" value="1" style="margin:0"> <strong>' . esc_html__( 'Subscribe and save', 'tasg-abo' ) . '</strong></label>';

		echo '<select name="tasg_abo_plan" aria-label="' . esc_attr__( 'Plan', 'tasg-abo' ) . '" style="width:auto!important;flex:0 1 auto;max-width:60%;min-height:0!important;height:auto!important;padding:2px 22px 2px 6px!important;margin:0!important;font-size:13px!important;line-height:1.3!important">';
		foreach ( $plans as $id => $plan ) {
			$price = html_entity_decode( wp_strip_all_tags( wc_price( Pricing::subscriber_price( $product, (int) $id ) ) ), ENT_QUOTES, 'UTF-8' );
			$label = $plan->display_name();
			$name  = '' !== $label ? $label . ' (' . $plan->period_label() . ')' : $plan->period_label();

			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( (int) $id === $default_id, true, false ) . '>' . esc_html( $name . ': ' . $price ) . '</option>';
		}
		echo '</select>';

		if ( Settings::admin_only() ) {
			echo '</div><small style="color:#b32d2e;display:block;margin-top:2px">' . esc_html__( 'Test phase: only shop managers can see this option.', 'tasg-abo' ) . '</small>';
		} else {
			echo '</div>';
		}

		foreach ( $plans as $id => $plan ) {
			echo '<div class="tasg-abo-text" data-plan="' . esc_attr( (string) $id ) . '"' . ( (int) $id === $default_id ? '' : ' hidden' ) . ' style="margin-top:3px;color:#555;font-size:13px">' . $this->text( $product, $plan ) . $this->term_note( $plan ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		// Shows the text of the chosen plan. Small, plain and only printed once.
		static $script = false;
		if ( ! $script ) {
			$script = true;
			echo '<script>document.addEventListener("change",function(e){var s=e.target;if(!s||s.name!=="tasg_abo_plan"){return;}var b=s.closest(".tasg-abo-toggle");if(!b){return;}b.querySelectorAll(".tasg-abo-text").forEach(function(t){t.hidden=t.getAttribute("data-plan")!==s.value;});});</script>';
		}
	}
}
