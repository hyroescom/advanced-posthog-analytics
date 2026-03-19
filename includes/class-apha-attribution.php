<?php
/**
 * InsightTrail for PostHog Attribution Engine.
 *
 * Captures UTM parameters and ad platform click IDs from incoming URLs,
 * stores them in server-side first-party cookies (bypassing Safari ITP
 * restrictions on JS cookies), and persists attribution data to order meta
 * at checkout for server-side event enrichment.
 *
 * @package InsightTrailForPostHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Attribution
 *
 * Implements first-touch / last-touch attribution with server-side cookies
 * and ad click ID capture for full-funnel marketing attribution.
 */
class APHA_Attribution {

	/**
	 * Cookie name for first-touch attribution data.
	 *
	 * @var string
	 */
	const COOKIE_FIRST_TOUCH = 'apha_ft';

	/**
	 * Cookie name for last-touch attribution data.
	 *
	 * @var string
	 */
	const COOKIE_LAST_TOUCH = 'apha_lt';

	/**
	 * Cookie name for ad platform click IDs.
	 *
	 * @var string
	 */
	const COOKIE_CLICK_IDS = 'apha_cid';

	/**
	 * UTM parameters to capture.
	 *
	 * @var array
	 */
	const UTM_PARAMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_content',
		'utm_term',
	);

	/**
	 * Ad platform click ID parameters and their cookie expiry in days.
	 *
	 * @var array
	 */
	const CLICK_ID_PARAMS = array(
		'gclid'     => 90,
		'gbraid'    => 90,
		'wbraid'    => 90,
		'fbclid'    => 90,
		'ttclid'    => 90,
		'msclkid'   => 90,
		'li_fat_id' => 90,
	);

	/**
	 * Identity manager instance (optional).
	 *
	 * @var APHA_Identity|null
	 */
	private $identity = null;

	/**
	 * Set the identity manager instance.
	 *
	 * @param APHA_Identity $identity Identity manager.
	 * @return void
	 */
	public function set_identity( $identity ) {
		$this->identity = $identity;
	}

	/**
	 * Constructor.
	 *
	 * Hooks into WordPress init for cookie capture and WooCommerce checkout
	 * for persisting attribution to order meta.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'capture_attribution' ), 5 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'persist_to_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'persist_to_order' ), 10, 1 );
	}

	/**
	 * Capture UTM parameters and click IDs from the current request.
	 *
	 * Sets server-side first-party cookies. First-touch is set once and
	 * never overwritten. Last-touch is overwritten on each attributed visit.
	 *
	 * @return void
	 */
	public function capture_attribution() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$utms      = $this->extract_utm_params();
		$click_ids = $this->extract_click_ids();
		$referrer  = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$has_attribution = ! empty( $utms ) || ! empty( $click_ids ) || ! empty( $referrer );

		if ( ! $has_attribution ) {
			return;
		}

		$landing_page = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$timestamp    = current_time( 'c' );

		// Build touch data.
		$touch_data = array_merge(
			$utms,
			array(
				'landing_page' => $landing_page,
				'referrer'     => $referrer,
				'timestamp'    => $timestamp,
			)
		);

		// First-touch: set once, never overwrite.
		if ( ! isset( $_COOKIE[ self::COOKIE_FIRST_TOUCH ] ) ) {
			$this->set_cookie( self::COOKIE_FIRST_TOUCH, $touch_data, 365 );
		}

		// Last-touch: overwrite on each attributed visit.
		if ( ! empty( $utms ) || ! empty( $click_ids ) ) {
			$this->set_cookie( self::COOKIE_LAST_TOUCH, $touch_data, 30 );
		}

		// Click IDs: store latest click IDs.
		if ( ! empty( $click_ids ) ) {
			$click_ids['timestamp'] = $timestamp;
			$this->set_cookie( self::COOKIE_CLICK_IDS, $click_ids, 90 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Persist attribution cookies to order meta at checkout.
	 *
	 * Reads InsightTrail for PostHog cookies first, falls back to WooCommerce 8.5+ native
	 * attribution data when our cookies are missing.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function persist_to_order( $order ) {
		$first_touch = $this->get_cookie_data( self::COOKIE_FIRST_TOUCH );
		$last_touch  = $this->get_cookie_data( self::COOKIE_LAST_TOUCH );
		$click_ids   = $this->get_cookie_data( self::COOKIE_CLICK_IDS );

		// First-touch attribution.
		if ( ! empty( $first_touch ) ) {
			$this->save_touch_meta( $order, 'ft', $first_touch );
		}

		// Last-touch attribution.
		if ( ! empty( $last_touch ) ) {
			$this->save_touch_meta( $order, 'lt', $last_touch );
		}

		// Click IDs.
		if ( ! empty( $click_ids ) ) {
			foreach ( self::CLICK_ID_PARAMS as $param => $expiry ) {
				if ( ! empty( $click_ids[ $param ] ) ) {
					$order->update_meta_data( '_apha_' . $param, sanitize_text_field( $click_ids[ $param ] ) );
				}
			}
		}

		// Session tracking for days-to-conversion.
		if ( ! empty( $first_touch['timestamp'] ) ) {
			$first_visit  = strtotime( $first_touch['timestamp'] );
			$now          = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$days         = $first_visit ? max( 0, floor( ( $now - $first_visit ) / DAY_IN_SECONDS ) ) : 0;
			$order->update_meta_data( '_apha_days_to_conversion', $days );
		}

		// Session count from cookie visits (increment on each last-touch update).
		$session_count = isset( $_COOKIE['apha_sc'] ) ? absint( $_COOKIE['apha_sc'] ) : 1;
		$order->update_meta_data( '_apha_session_count', $session_count );

		// Fallback: WooCommerce 8.5+ native attribution if InsightTrail for PostHog cookies are empty.
		if ( empty( $first_touch ) && empty( $last_touch ) ) {
			$this->fallback_wc_attribution( $order );
		}

		// Checkout path from JS cookie (set on checkout page load).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checkout_path = isset( $_COOKIE['apha_checkout_path'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['apha_checkout_path'] ) )
			: '';

		if ( ! empty( $checkout_path ) ) {
			$order->update_meta_data( '_apha_checkout_path', $checkout_path );

			/**
			 * Filter the checkout type label derived from the checkout path.
			 *
			 * @param string   $checkout_type Default checkout type.
			 * @param string   $checkout_path The checkout page URL path.
			 * @param WC_Order $order         The WooCommerce order.
			 */
			$checkout_type = apply_filters( 'apha_checkout_type', 'standard', $checkout_path, $order );
			$order->update_meta_data( '_apha_checkout_type', sanitize_text_field( $checkout_type ) );
		}

		// PostHog session ID: prefer server-side PostHog cookie parse, fall back to JS cookie.
		$session_id = null;
		if ( $this->identity ) {
			$session_id = $this->identity->get_posthog_browser_session_id();
		}
		if ( empty( $session_id ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$session_id = isset( $_COOKIE['apha_session_id'] )
				? sanitize_text_field( wp_unslash( $_COOKIE['apha_session_id'] ) )
				: '';
		}
		if ( ! empty( $session_id ) ) {
			$order->update_meta_data( '_apha_session_id', $session_id );
		}

		// Order group ID: links main order with upsells from the same checkout session.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_group_id = isset( $_COOKIE['apha_order_group_id'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['apha_order_group_id'] ) )
			: '';
		if ( ! empty( $order_group_id ) ) {
			$order->update_meta_data( '_apha_order_group_id', $order_group_id );
			$order->update_meta_data( '_apha_order_type', 'main' );
		}

		$order->save();
	}

	/**
	 * Get attribution data for an order's PostHog event properties.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array Attribution properties for PostHog events.
	 */
	public function get_order_attribution( $order ) {
		$attribution = array();

		// First-touch.
		$ft_fields = array( 'source', 'medium', 'campaign', 'content', 'term', 'landing_page', 'referrer' );
		foreach ( $ft_fields as $field ) {
			$value = $order->get_meta( '_apha_ft_' . $field );
			if ( ! empty( $value ) ) {
				$attribution[ 'first_touch_' . $field ] = $value;
			}
		}

		// Last-touch.
		$lt_fields = array( 'source', 'medium', 'campaign', 'content', 'term', 'landing_page', 'referrer' );
		foreach ( $lt_fields as $field ) {
			$value = $order->get_meta( '_apha_lt_' . $field );
			if ( ! empty( $value ) ) {
				$attribution[ 'last_touch_' . $field ] = $value;
			}
		}

		// Click IDs.
		foreach ( array_keys( self::CLICK_ID_PARAMS ) as $param ) {
			$value = $order->get_meta( '_apha_' . $param );
			if ( ! empty( $value ) ) {
				$attribution[ $param ] = $value;
			}
		}

		// Conversion metrics.
		$days = $order->get_meta( '_apha_days_to_conversion' );
		if ( '' !== $days ) {
			$attribution['days_to_conversion'] = (int) $days;
		}

		$sessions = $order->get_meta( '_apha_session_count' );
		if ( ! empty( $sessions ) ) {
			$attribution['session_count'] = (int) $sessions;
		}

		// Checkout attribution.
		$checkout_path = $order->get_meta( '_apha_checkout_path' );
		if ( ! empty( $checkout_path ) ) {
			$attribution['checkout_path'] = $checkout_path;
		}

		$checkout_type = $order->get_meta( '_apha_checkout_type' );
		if ( ! empty( $checkout_type ) ) {
			$attribution['checkout_type'] = $checkout_type;
		}

		// PostHog session ID — uses $ prefix so PostHog links the event to the session.
		$session_id = $order->get_meta( '_apha_session_id' );
		if ( ! empty( $session_id ) ) {
			$attribution['$session_id'] = $session_id;
		}

		// Order grouping for upsell attribution.
		$order_group_id = $order->get_meta( '_apha_order_group_id' );
		if ( ! empty( $order_group_id ) ) {
			$attribution['order_group_id'] = $order_group_id;
		}

		$order_type = $order->get_meta( '_apha_order_type' );
		if ( ! empty( $order_type ) ) {
			$attribution['order_type'] = $order_type;
		}

		return $attribution;
	}

	/**
	 * Get first-touch attribution data for person $set_once properties.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array Person properties suitable for $set_once.
	 */
	public function get_acquisition_properties( $order ) {
		$properties = array();

		$source   = $order->get_meta( '_apha_ft_source' );
		$medium   = $order->get_meta( '_apha_ft_medium' );
		$campaign = $order->get_meta( '_apha_ft_campaign' );

		if ( ! empty( $source ) ) {
			$properties['acquisition_source']   = $source;
		}
		if ( ! empty( $medium ) ) {
			$properties['acquisition_medium']   = $medium;
		}
		if ( ! empty( $campaign ) ) {
			$properties['acquisition_campaign'] = $campaign;
		}

		return $properties;
	}

	/**
	 * Extract UTM parameters from the current request.
	 *
	 * @return array Associative array of UTM key => value.
	 */
	private function extract_utm_params() {
		$utms = array();

		foreach ( self::UTM_PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$utms[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}

		// Map utm_source/medium/campaign to shorter keys for cookie storage.
		$mapped = array();
		foreach ( $utms as $key => $value ) {
			$short_key = str_replace( 'utm_', '', $key );
			$mapped[ $short_key ] = $value;
		}

		return $mapped;
	}

	/**
	 * Extract ad platform click IDs from the current request.
	 *
	 * @return array Associative array of click ID param => value.
	 */
	private function extract_click_ids() {
		$click_ids = array();

		foreach ( array_keys( self::CLICK_ID_PARAMS ) as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$click_ids[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}

		return $click_ids;
	}

	/**
	 * Set a server-side first-party cookie with JSON data.
	 *
	 * @param string $name    Cookie name.
	 * @param array  $data    Data to JSON-encode and store.
	 * @param int    $days    Cookie expiry in days.
	 * @return void
	 */
	private function set_cookie( $name, $data, $days ) {
		$expiry = time() + ( $days * DAY_IN_SECONDS );
		$value  = wp_json_encode( $data );

		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expiry,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// Make available in current request.
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Read and decode a JSON cookie.
	 *
	 * @param string $name Cookie name.
	 * @return array Decoded data or empty array.
	 */
	private function get_cookie_data( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) || empty( $_COOKIE[ $name ] ) ) {
			return array();
		}

		$data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save touch attribution data as individual order meta fields.
	 *
	 * @param WC_Order $order  WooCommerce order object.
	 * @param string   $prefix 'ft' for first-touch, 'lt' for last-touch.
	 * @param array    $data   Touch data array.
	 * @return void
	 */
	private function save_touch_meta( $order, $prefix, $data ) {
		$fields = array( 'source', 'medium', 'campaign', 'content', 'term', 'landing_page', 'referrer', 'timestamp' );

		foreach ( $fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$order->update_meta_data( '_apha_' . $prefix . '_' . $field, sanitize_text_field( $data[ $field ] ) );
			}
		}
	}

	/**
	 * Read WooCommerce 8.5+ native attribution as fallback.
	 *
	 * When InsightTrail for PostHog's own cookies are missing (blocked, expired), use WC's
	 * built-in attribution data stored in _wc_order_attribution_* meta.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	private function fallback_wc_attribution( $order ) {
		$wc_map = array(
			'utm_source'   => 'source',
			'utm_medium'   => 'medium',
			'utm_campaign' => 'campaign',
			'utm_content'  => 'content',
			'utm_term'     => 'term',
		);

		foreach ( $wc_map as $wc_suffix => $apha_field ) {
			$value = $order->get_meta( '_wc_order_attribution_' . $wc_suffix );

			if ( ! empty( $value ) ) {
				// Store as both first-touch and last-touch since WC only has one.
				if ( empty( $order->get_meta( '_apha_ft_' . $apha_field ) ) ) {
					$order->update_meta_data( '_apha_ft_' . $apha_field, sanitize_text_field( $value ) );
				}
				if ( empty( $order->get_meta( '_apha_lt_' . $apha_field ) ) ) {
					$order->update_meta_data( '_apha_lt_' . $apha_field, sanitize_text_field( $value ) );
				}
			}
		}

		// WC referrer.
		$referrer = $order->get_meta( '_wc_order_attribution_referrer' );
		if ( ! empty( $referrer ) ) {
			if ( empty( $order->get_meta( '_apha_ft_referrer' ) ) ) {
				$order->update_meta_data( '_apha_ft_referrer', esc_url_raw( $referrer ) );
			}
			if ( empty( $order->get_meta( '_apha_lt_referrer' ) ) ) {
				$order->update_meta_data( '_apha_lt_referrer', esc_url_raw( $referrer ) );
			}
		}
	}

	/**
	 * Get all InsightTrail for PostHog meta keys used by the attribution engine.
	 *
	 * Used by uninstall.php for cleanup.
	 *
	 * @return array List of meta key strings.
	 */
	public static function get_meta_keys() {
		$keys = array();

		foreach ( array( 'ft', 'lt' ) as $prefix ) {
			foreach ( array( 'source', 'medium', 'campaign', 'content', 'term', 'landing_page', 'referrer', 'timestamp' ) as $field ) {
				$keys[] = '_apha_' . $prefix . '_' . $field;
			}
		}

		foreach ( array_keys( self::CLICK_ID_PARAMS ) as $param ) {
			$keys[] = '_apha_' . $param;
		}

		$keys[] = '_apha_days_to_conversion';
		$keys[] = '_apha_session_count';
		$keys[] = '_apha_checkout_path';
		$keys[] = '_apha_checkout_type';
		$keys[] = '_apha_session_id';
		$keys[] = '_apha_order_group_id';
		$keys[] = '_apha_order_type';

		return $keys;
	}
}
