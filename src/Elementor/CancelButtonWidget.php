<?php
/**
 * Elementor widget: a button that opens the cancel page. Text, colours, border and
 * spacing are set in the Elementor editor.
 *
 * @package TasgAbo
 */

namespace TasgAbo\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use TasgAbo\Cancellation\CancelPage;
use TasgAbo\Settings;

defined( 'ABSPATH' ) || exit;

final class CancelButtonWidget extends Widget_Base {

	public function get_name() {
		return 'tasg_abo_cancel_button';
	}

	public function get_title() {
		return esc_html__( 'Cancel contracts button', 'tasg-abo' );
	}

	public function get_icon() {
		return 'eicon-button';
	}

	public function get_categories() {
		return array( Integration::CATEGORY );
	}

	public function get_keywords() {
		return array( 'cancel', 'subscription', 'abo', 'kuendigen', 'kündigen' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => esc_html__( 'Button', 'tasg-abo' ) ) );

		$this->add_control(
			'text',
			array(
				'label'       => esc_html__( 'Text', 'tasg-abo' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => Settings::cancel_button_text(),
				'description' => esc_html__( 'Leave empty to use the text from WooCommerce > Settings > Subscriptions.', 'tasg-abo' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => esc_html__( 'Alignment', 'tasg-abo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'tasg-abo' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'tasg-abo' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'tasg-abo' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array( '{{WRAPPER}} .tasg-abo-cancel-button-wrap' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style',
			array(
				'label' => esc_html__( 'Button', 'tasg-abo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .tasg-abo-cancel-button',
			)
		);

		$this->start_controls_tabs( 'colors' );

		foreach ( array(
			'normal' => array( esc_html__( 'Normal', 'tasg-abo' ), '' ),
			'hover'  => array( esc_html__( 'Hover', 'tasg-abo' ), ':hover' ),
		) as $state => $info ) {
			$this->start_controls_tab( 'tab_' . $state, array( 'label' => $info[0] ) );
			$this->add_control(
				'color_' . $state,
				array(
					'label'     => esc_html__( 'Text colour', 'tasg-abo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .tasg-abo-cancel-button' . $info[1] => 'color: {{VALUE}};' ),
				)
			);
			$this->add_control(
				'background_' . $state,
				array(
					'label'     => esc_html__( 'Background', 'tasg-abo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .tasg-abo-cancel-button' . $info[1] => 'background-color: {{VALUE}};' ),
				)
			);
			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'border',
				'selector' => '{{WRAPPER}} .tasg-abo-cancel-button',
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => esc_html__( 'Corner radius', 'tasg-abo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} .tasg-abo-cancel-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => esc_html__( 'Padding', 'tasg-abo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} .tasg-abo-cancel-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The page may change per visitor (login, language), so Elementor must not cache the output.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$url      = CancelPage::url();
		$url      = '' !== $url ? $url : CancelPage::short_url();
		$text     = isset( $settings['text'] ) && '' !== trim( (string) $settings['text'] ) ? (string) $settings['text'] : Settings::cancel_button_text();

		// Basic look at zero weight, so every style set in Elementor or the theme wins.
		echo '<style>:where(.tasg-abo-cancel-button){display:inline-block;padding:.7em 1.4em;background:#222;color:#fff;text-decoration:none;border-radius:3px;line-height:1.3}</style>';
		echo '<div class="tasg-abo-cancel-button-wrap"><a class="tasg-abo-cancel-button" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></div>';
	}
}
