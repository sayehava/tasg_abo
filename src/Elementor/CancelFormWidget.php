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

	private function section( string $id, string $label ): void {
		$this->start_controls_section(
			$id,
			array(
				'label' => $label,
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => esc_html__( 'Form', 'tasg-abo' ) ) );
		$this->add_control(
			'submit_label',
			array(
				'label'       => esc_html__( 'Button text', 'tasg-abo' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'Cancel contract now', 'tasg-abo' ),
				'description' => esc_html__( 'Leave empty for the standard text. German law asks for "jetzt kündigen" or an equally clear wording.', 'tasg-abo' ),
			)
		);
		$this->add_control(
			'note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The form shows the questions chosen in WooCommerce > Settings > Subscriptions > Settings. Select this page there as the cancel page, so every cancel link leads here. The preview below shows sample data.', 'tasg-abo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$this->end_controls_section();

		$this->section( 'style_text', esc_html__( 'Text', 'tasg-abo' ) );
		$this->typography( 'text_typography', '.tasg-abo-cancel-wrap p, {{WRAPPER}} .tasg-abo-cancel-wrap li' );
		$this->colour( 'text_color', esc_html__( 'Text colour', 'tasg-abo' ), '.tasg-abo-cancel-wrap', 'color' );
		$this->end_controls_section();

		$this->section( 'style_labels', esc_html__( 'Labels', 'tasg-abo' ) );
		$this->typography( 'label_typography', '.tasg-abo-label' );
		$this->colour( 'label_color', esc_html__( 'Colour', 'tasg-abo' ), '.tasg-abo-label', 'color' );
		$this->end_controls_section();

		$this->section( 'style_fields', esc_html__( 'Fields', 'tasg-abo' ) );
		$this->typography( 'field_typography', '.tasg-abo-input' );
		$this->colour( 'field_color', esc_html__( 'Text colour', 'tasg-abo' ), '.tasg-abo-input', 'color' );
		$this->colour( 'field_background', esc_html__( 'Background', 'tasg-abo' ), '.tasg-abo-input', 'background-color' );
		$this->box( 'field', '.tasg-abo-input' );
		$this->end_controls_section();

		$this->section( 'style_contract', esc_html__( 'Contract box', 'tasg-abo' ) );
		$this->colour( 'contract_background', esc_html__( 'Background', 'tasg-abo' ), '.tasg-abo-contract', 'background-color' );
		$this->box( 'contract', '.tasg-abo-contract' );
		$this->end_controls_section();

		$this->section( 'style_button', esc_html__( 'Button', 'tasg-abo' ) );
		$this->typography( 'button_typography', '.tasg-abo-submit' );
		$this->colour( 'button_color', esc_html__( 'Text colour', 'tasg-abo' ), '.tasg-abo-submit', 'color' );
		$this->colour( 'button_background', esc_html__( 'Background', 'tasg-abo' ), '.tasg-abo-submit', 'background-color' );
		$this->colour( 'button_color_hover', esc_html__( 'Text colour on hover', 'tasg-abo' ), '.tasg-abo-submit:hover', 'color' );
		$this->colour( 'button_background_hover', esc_html__( 'Background on hover', 'tasg-abo' ), '.tasg-abo-submit:hover', 'background-color' );
		$this->box( 'button', '.tasg-abo-submit' );
		$this->end_controls_section();

		$this->section( 'style_messages', esc_html__( 'Messages', 'tasg-abo' ) );
		$this->colour( 'error_color', esc_html__( 'Error text colour', 'tasg-abo' ), '.tasg-abo-notice--error', 'color' );
		$this->colour( 'error_background', esc_html__( 'Error background', 'tasg-abo' ), '.tasg-abo-notice--error', 'background-color' );
		$this->colour( 'info_color', esc_html__( 'Notice text colour', 'tasg-abo' ), '.tasg-abo-notice--info', 'color' );
		$this->colour( 'info_background', esc_html__( 'Notice background', 'tasg-abo' ), '.tasg-abo-notice--info', 'background-color' );
		$this->end_controls_section();
	}

	/**
	 * The form shows login and order dependent content and takes input, so Elementor
	 * must never cache its output.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$form     = ( new CancelForm( new Repository() ) )->with_submit_label( isset( $settings['submit_label'] ) ? trim( (string) $settings['submit_label'] ) : '' );

		// In the editor the form shows sample data, so every part can be styled.
		$editing = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode();

		echo $editing ? $form->preview() : $form->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
