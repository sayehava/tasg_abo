<?php
/**
 * WooCommerce > Subscription plans: define periods and their reminder lead time.
 *
 * Products then offer one or more of these plans. Plans marked as test plans
 * are only visible to shop managers, so short periods can be tried safely.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Admin;

use TasgAbo\Plans\Period;
use TasgAbo\Plans\Plan;
use TasgAbo\Plans\PlanRepository;

defined( 'ABSPATH' ) || exit;

final class PlansPage {

	private const SLUG = 'tasg-abo-plans';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_tasg_abo_save_plan', array( $this, 'handle_save' ) );
		add_action( 'admin_post_tasg_abo_delete_plan', array( $this, 'handle_delete' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Subscription plans', 'tasg-abo' ),
			__( 'Subscription plans', 'tasg-abo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function back( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	public function handle_save(): void {
		check_admin_referer( 'tasg_abo_save_plan' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		$unit  = isset( $_POST['unit'] ) ? sanitize_key( wp_unslash( $_POST['unit'] ) ) : 'week';
		$value = isset( $_POST['value'] ) ? absint( $_POST['value'] ) : 0;

		$reminder_unit  = isset( $_POST['reminder_unit'] ) ? sanitize_key( wp_unslash( $_POST['reminder_unit'] ) ) : 'hour';
		$reminder_value = isset( $_POST['reminder_value'] ) ? absint( $_POST['reminder_value'] ) : 0;
		$multiplier     = array(
			'minute' => 1,
			'hour'   => 60,
			'day'    => 1440,
		);

		if ( $value < 1 || ! in_array( $unit, Period::UNITS, true ) || $reminder_value < 1 || ! isset( $multiplier[ $reminder_unit ] ) ) {
			$this->back( array( 'error' => 'fields' ) );
		}

		$reminder_minutes = $reminder_value * $multiplier[ $reminder_unit ];

		// The reminder must fit inside the period, or it would go out before the previous order.
		if ( $reminder_minutes * MINUTE_IN_SECONDS >= Period::seconds( $value, $unit ) ) {
			$this->back( array( 'error' => 'reminder' ) );
		}

		$plan                   = new Plan();
		$plan->id               = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$plan->name             = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$plan->value            = $value;
		$plan->unit             = $unit;
		$plan->reminder_minutes = $reminder_minutes;
		$plan->test             = ! empty( $_POST['test'] );
		$plan->active           = ! empty( $_POST['active'] );

		( new PlanRepository() )->save( $plan );

		$this->back( array( 'saved' => '1' ) );
	}

	public function handle_delete(): void {
		check_admin_referer( 'tasg_abo_delete_plan' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tasg-abo' ) );
		}

		( new PlanRepository() )->delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );

		$this->back( array( 'deleted' => '1' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$repository = new PlanRepository();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$notice  = isset( $_GET['saved'] ) ? __( 'Plan saved.', 'tasg-abo' ) : ( isset( $_GET['deleted'] ) ? __( 'Plan deleted. Existing subscriptions keep running with their own copy of the plan.', 'tasg-abo' ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$editing = $edit_id ? $repository->find( $edit_id ) : null;
		$plan    = $editing ? $editing : new Plan();

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscription plans', 'tasg-abo' ) . '</h1>';
		echo '<p>' . esc_html__( 'A plan is a period with its own reminder time, for example "Weekly" or "Every 4 months". Assign plans to products on the product page. A product can offer several plans.', 'tasg-abo' ) . '</p>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( 'reminder' === $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The reminder time must be shorter than the period.', 'tasg-abo' ) . '</p></div>';
		} elseif ( 'fields' === $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please enter a period and a reminder time of at least 1.', 'tasg-abo' ) . '</p></div>';
		}

		$this->render_list( $repository->all() );
		$this->render_form( $plan );

		echo '</div>';
	}

	/**
	 * @param Plan[] $plans All plans.
	 */
	private function render_list( array $plans ): void {
		echo '<table class="widefat striped" style="margin:1em 0"><thead><tr>';
		foreach ( array( __( 'Name', 'tasg-abo' ), __( 'Period', 'tasg-abo' ), __( 'Reminder before charge', 'tasg-abo' ), __( 'Visible to', 'tasg-abo' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $plans ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No plans yet. Add your first plan below.', 'tasg-abo' ) . '</td></tr>';
		}

		foreach ( $plans as $plan ) {
			$visible = $plan->active ? ( $plan->test ? __( 'Shop managers only (test plan)', 'tasg-abo' ) : __( 'Everyone', 'tasg-abo' ) ) : __( 'Nobody (switched off)', 'tasg-abo' );
			$warning = ( ! $plan->test && $plan->reminder_minutes < 2880 ) ? ' ' . __( '(less than 48 hours)', 'tasg-abo' ) : '';

			echo '<tr><td>' . esc_html( $plan->title() ) . '</td>';
			echo '<td>' . esc_html( $plan->period_label() ) . '</td>';
			echo '<td>' . esc_html( Period::minutes_label( $plan->reminder_minutes ) . $warning ) . '</td>';
			echo '<td>' . esc_html( $visible ) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url( add_query_arg( 'edit', $plan->id, admin_url( 'admin.php?page=' . self::SLUG ) ) ) . '">' . esc_html__( 'Edit', 'tasg-abo' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'tasg_abo_delete_plan' );
			echo '<input type="hidden" name="action" value="tasg_abo_delete_plan"><input type="hidden" name="id" value="' . esc_attr( (string) $plan->id ) . '">';
			echo '<button class="button button-small">' . esc_html__( 'Delete', 'tasg-abo' ) . '</button></form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function reminder_parts( int $minutes ): array {
		if ( $minutes >= 1440 && 0 === $minutes % 1440 ) {
			return array( intdiv( $minutes, 1440 ), 'day' );
		}

		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			return array( intdiv( $minutes, 60 ), 'hour' );
		}

		return array( $minutes, 'minute' );
	}

	private function render_form( Plan $plan ): void {
		list( $reminder_value, $reminder_unit ) = $this->reminder_parts( $plan->reminder_minutes );

		echo '<h2>' . esc_html( $plan->id ? __( 'Edit plan', 'tasg-abo' ) : __( 'Add plan', 'tasg-abo' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tasg_abo_save_plan' );
		echo '<input type="hidden" name="action" value="tasg_abo_save_plan"><input type="hidden" name="id" value="' . esc_attr( (string) $plan->id ) . '">';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="tasg_name">' . esc_html__( 'Name', 'tasg-abo' ) . '</label></th><td><input id="tasg_name" name="name" class="regular-text" value="' . esc_attr( $plan->name ) . '">';
		echo '<p class="description">' . esc_html__( 'Shown to customers, for example "Weekly". Leave empty to show the period.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Period', 'tasg-abo' ) . '</th><td>';
		echo '<input type="number" min="1" name="value" style="width:5em" value="' . esc_attr( (string) $plan->value ) . '"> ';
		$this->unit_select( 'unit', $plan->unit, Period::unit_names() );
		echo '<p class="description">' . esc_html__( 'How often a new order is made. For tests you can use minutes, hours or days.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Reminder email before the charge', 'tasg-abo' ) . '</th><td>';
		echo '<input type="number" min="1" name="reminder_value" style="width:5em" value="' . esc_attr( (string) $reminder_value ) . '"> ';
		$this->unit_select(
			'reminder_unit',
			$reminder_unit,
			array(
				'minute' => __( 'Minutes', 'tasg-abo' ),
				'hour'   => __( 'Hours', 'tasg-abo' ),
				'day'    => __( 'Days', 'tasg-abo' ),
			)
		);
		echo '<p class="description">' . esc_html__( 'The customer gets the reminder at least this long before being charged. We recommend 48 hours or more for plans customers use. Must be shorter than the period.', 'tasg-abo' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Test plan', 'tasg-abo' ) . '</th><td><label><input type="checkbox" name="test" value="1"' . checked( $plan->test, true, false ) . '> ' . esc_html__( 'Only shop managers can see this plan', 'tasg-abo' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Active', 'tasg-abo' ) . '</th><td><label><input type="checkbox" name="active" value="1"' . checked( $plan->active || ! $plan->id, true, false ) . '> ' . esc_html__( 'Offer this plan', 'tasg-abo' ) . '</label></td></tr>';

		echo '</table>';
		submit_button( $plan->id ? __( 'Save plan', 'tasg-abo' ) : __( 'Add plan', 'tasg-abo' ) );
		echo '</form>';
	}

	private function unit_select( string $name, string $selected, array $units ): void {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $units as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}
