<?php
/**
 * Stores the plans in one option. There are only a handful of them.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Plans;

defined( 'ABSPATH' ) || exit;

final class PlanRepository {

	private const OPTION  = 'tasg_abo_plans';
	private const NEXT_ID = 'tasg_abo_plan_next_id';

	/**
	 * @return Plan[] keyed by id, oldest first
	 */
	public function all(): array {
		$plans = array();

		foreach ( (array) get_option( self::OPTION, array() ) as $data ) {
			if ( is_array( $data ) && ! empty( $data['id'] ) ) {
				$plan                = Plan::from_array( $data );
				$plans[ $plan->id ] = $plan;
			}
		}

		ksort( $plans );

		return $plans;
	}

	public function find( int $id ): ?Plan {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Insert (id 0) or update a plan. Returns its id.
	 */
	public function save( Plan $plan ): int {
		$stored = (array) get_option( self::OPTION, array() );

		if ( $plan->id < 1 ) {
			$plan->id = max( 1, (int) get_option( self::NEXT_ID, 1 ) );
			update_option( self::NEXT_ID, $plan->id + 1, false );
		}

		$stored[ $plan->id ] = $plan->to_array();
		update_option( self::OPTION, $stored, false );

		return $plan->id;
	}

	public function delete( int $id ): void {
		$stored = (array) get_option( self::OPTION, array() );
		unset( $stored[ $id ] );
		update_option( self::OPTION, $stored, false );
	}
}
