<?php
/**
 * One subscription plan: a period with its own reminder lead time.
 * Example: "Monthly", every 1 month, reminder 3 days before.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Plans;

defined( 'ABSPATH' ) || exit;

final class Plan {

	public $id               = 0;
	public $name             = '';
	public $value            = 1;
	public $unit             = 'week';
	public $reminder_minutes = 2880;
	public $test             = false;
	public $active           = true;
	public $default          = false;

	public static function from_array( array $data ): Plan {
		$plan                   = new self();
		$plan->id               = (int) ( $data['id'] ?? 0 );
		$plan->name             = (string) ( $data['name'] ?? '' );
		$plan->value            = max( 1, (int) ( $data['value'] ?? 1 ) );
		$plan->unit             = in_array( $data['unit'] ?? '', Period::UNITS, true ) ? $data['unit'] : 'week';
		$plan->reminder_minutes = max( 1, (int) ( $data['reminder_minutes'] ?? 2880 ) );
		$plan->test             = ! empty( $data['test'] );
		$plan->active           = ! isset( $data['active'] ) || ! empty( $data['active'] );
		$plan->default          = ! empty( $data['default'] );

		return $plan;
	}

	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'name'             => $this->name,
			'value'            => $this->value,
			'unit'             => $this->unit,
			'reminder_minutes' => $this->reminder_minutes,
			'test'             => $this->test,
			'active'           => $this->active,
			'default'          => $this->default,
		);
	}

	/**
	 * "every 2 weeks" style text of the period.
	 */
	public function period_label(): string {
		return Period::label( $this->value, $this->unit );
	}

	/**
	 * The name in the current language. With WPML String Translation the plan name
	 * can be translated (context "Tasg Abo"), otherwise the name as typed is used.
	 */
	public function display_name(): string {
		if ( '' === $this->name || $this->id < 1 ) {
			return $this->name;
		}

		return (string) apply_filters( 'wpml_translate_single_string', $this->name, PlanRepository::WPML_CONTEXT, 'plan-' . $this->id . '-name' );
	}

	/**
	 * Name for customers: the given name, or the period if there is none.
	 */
	public function title(): string {
		$name = $this->display_name();

		return '' !== $name ? $name : $this->period_label();
	}

	/**
	 * What is stored with an order item and a subscription, so that later edits
	 * or deletion of the plan never change a running subscription.
	 */
	public function snapshot(): array {
		return array(
			'id'               => $this->id,
			'name'             => $this->title(),
			'value'            => $this->value,
			'unit'             => $this->unit,
			'reminder_minutes' => $this->reminder_minutes,
			'test'             => $this->test,
		);
	}

	/**
	 * Only people allowed to manage the shop see test plans.
	 */
	public function visible_to_current_user(): bool {
		return $this->active && ( ! $this->test || current_user_can( 'manage_woocommerce' ) );
	}
}
