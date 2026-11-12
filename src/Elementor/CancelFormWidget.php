<?php
/**
 * Elementor widget: the cancellation form. Put it on the cancel page to design the page
 * in the site's own look. Texts of labels and messages come from the plugin (translated),
 * the look is set with the style controls.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use TasgAbo\Cancellation\CancelForm;
use TasgAbo\Subscriptions\Repository;

defined( 'ABSPATH' ) || exit;

final class CancelFormWidget extends Widget_Base {

	public function get_name() {
		return 'tasg_abo_cancel_form';
	}

	public function get_title() {
		return esc_html__( 'Cancellation form', 'tasg-abo' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( Integration::CATEGORY );
	}

	public function get_keywords() {
		return array( 'cancel', 'subscription', 'abo', 'form', 'kuendigen', 'kündigen' );
	}

	/**
	 * Typography control for one part of the form.
	 */
	private function typography( string $name, string $selector ): void {
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $name,
				'selector' => '{{WRAPPER}} ' . $selector,
			)
		);
	}

	/**
	 * Colour control that sets one CSS property on one part of the form.
	 */
	private function colour( string $name, string $label, string $selector, string $property ): void {
		$this->add_control(
			$name,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} ' . $selector => $property . ': {{VALUE}};' ),
			)
		);
	}

	/**
	 * Border, corner radius and padding of one part of the form.
	 */
	private function box( string $name, string $selector ): void {
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => $name . '_border',
				'selector' => '{{WRAPPER}} ' . $selector,
			)
		);

		foreach ( array(
			'radius'  => array( esc_html__( 'Corner radius', 'tasg-abo' ), 'border-radius' ),
			'padding' => array( esc_html__( 'Padding', 'tasg-abo' ), 'padding' ),
		) as $key => $info ) {
			$this->add_control(
				$name . '_' . $key,
				array(
					'label'      => $info[0],
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'em', '%' ),
					'selectors'  => array( '{{WRAPPER}} ' . $selector => $info[1] . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);
		}
	}
}
