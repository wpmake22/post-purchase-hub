<?php
/**
 * WC_Settings_API stub for the unit suite.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub must carry the WooCommerce name it replaces.

if ( ! class_exists( 'WC_Settings_API' ) ) {
	/**
	 * Bare-bones stand-in for WooCommerce's settings-field persistence base
	 * class. Real enough to exercise `Emails\AbstractEmail` and its
	 * subclasses' own logic — id, subject/heading defaults, placeholder
	 * substitution, the enabled gate, recipient resolution — without pulling
	 * in the real settings screen, admin HTML rendering or the block email
	 * editor, none of which this plugin's own code decides.
	 */
	class WC_Settings_API {

		/**
		 * Method id, matching WC_Email's own `id` property.
		 *
		 * @var string
		 */
		public $id = '';

		/**
		 * Field definitions. Populated by init_form_fields() in the real class
		 * and here.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		public $form_fields = array();

		/**
		 * Resolved settings, keyed the same as form_fields.
		 *
		 * @var array<string, mixed>
		 */
		public $settings = array();

		/**
		 * No-op in the stub; subclasses assign their own fields directly.
		 *
		 * @return void
		 */
		public function init_form_fields() {}

		/**
		 * Reads stored settings (a single serialised option per email id,
		 * matching the real class) and falls back to each field's default.
		 *
		 * @return void
		 */
		public function init_settings() {
			$stored = get_option( 'woocommerce_' . $this->id . '_settings', array() );
			$stored = is_array( $stored ) ? $stored : array();

			foreach ( $this->form_fields as $key => $field ) {
				$this->settings[ $key ] = $stored[ $key ] ?? ( $field['default'] ?? '' );
			}
		}

		/**
		 * Reads one resolved setting.
		 *
		 * @param string $key         Field key.
		 * @param mixed  $empty_value Value to use when the setting is empty.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			if ( ! isset( $this->settings[ $key ] ) || '' === $this->settings[ $key ] ) {
				return null === $empty_value ? ( $this->settings[ $key ] ?? '' ) : $empty_value;
			}

			return $this->settings[ $key ];
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
