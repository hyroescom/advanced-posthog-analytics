<?php
/**
 * Advanced PostHog Analytics Identity Management.
 *
 * Manages user identity resolution, cookie-based distinct IDs,
 * and PostHog identify calls for anonymous-to-known user linking.
 *
 * @package AdvancedPostHogAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Identity
 *
 * Handles distinct ID generation, cookie management, and identity
 * merging between anonymous visitors and logged-in WordPress users.
 */
class APHA_Identity {

	/**
	 * Cookie name for storing the PostHog distinct ID.
	 *
	 * @var string
	 */
	const APHA_DISTINCT_ID_COOKIE = 'apha_distinct_id';

	/**
	 * PostHog API instance.
	 *
	 * @var APHA_PostHog_API
	 */
	private $api;

	/**
	 * Attribution engine instance (optional).
	 *
	 * @var APHA_Attribution|null
	 */
	private $attribution = null;

	/**
	 * Constructor.
	 *
	 * @param APHA_PostHog_API $api PostHog API instance.
	 */
	public function __construct( APHA_PostHog_API $api ) {
		$this->api = $api;

		add_action( 'init', array( $this, 'ensure_distinct_id' ) );
		add_action( 'wp_login', array( $this, 'identify_user' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'persist_distinct_id_to_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'persist_distinct_id_to_order' ), 10, 1 );
	}

	/**
	 * Set the attribution engine instance.
	 *
	 * @param APHA_Attribution $attribution Attribution engine.
	 * @return void
	 */
	public function set_attribution( APHA_Attribution $attribution ) {
		$this->attribution = $attribution;
	}

	/**
	 * Ensure a distinct ID cookie is set for the current visitor.
	 *
	 * @return void
	 */
	public function ensure_distinct_id() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( isset( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) && ! empty( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$distinct_id = 'wp_' . get_current_user_id();
		} else {
			$distinct_id = wp_generate_uuid4();
		}

		$this->set_cookie( $distinct_id );
	}

	/**
	 * Get the distinct ID for the current request.
	 *
	 * For logged-in users, returns the stable `wp_XX` ID (the browser
	 * also calls posthog.identify('wp_XX') so both sides agree).
	 *
	 * For anonymous visitors, prefers the PostHog JS SDK's own distinct_id
	 * read from its cookie so server events match the browser session.
	 * Falls back to the Advanced PostHog Analytics cookie if PostHog's cookie is unavailable.
	 *
	 * @return string|null Distinct ID or null if unavailable.
	 */
	public function get_distinct_id() {
		if ( is_user_logged_in() ) {
			return 'wp_' . get_current_user_id();
		}

		// Prefer PostHog's browser distinct_id so server events match
		// the browser session and funnels connect end-to-end.
		$posthog_id = $this->get_posthog_browser_distinct_id();

		if ( ! empty( $posthog_id ) ) {
			return $posthog_id;
		}

		if ( isset( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) && ! empty( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) );
		}

		return null;
	}

	/**
	 * Read the PostHog JS SDK's distinct_id from its browser cookie.
	 *
	 * The JS SDK stores session state in a cookie named `ph_{api_key}_posthog`.
	 * The value is JSON (possibly URL-encoded) containing `distinct_id`.
	 * By reading this server-side, we can use the exact same identity for
	 * server events, ensuring funnels and person profiles connect.
	 *
	 * @return string|null The browser distinct_id or null if unavailable.
	 */
	private function get_posthog_browser_distinct_id() {
		$api_key = APHA_Settings::get_api_key();

		if ( empty( $api_key ) ) {
			return null;
		}

		$cookie_name = 'ph_' . $api_key . '_posthog';

		if ( ! isset( $_COOKIE[ $cookie_name ] ) || empty( $_COOKIE[ $cookie_name ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ $cookie_name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// The cookie value may be URL-encoded JSON.
		if ( strpos( $raw, '%7B' ) === 0 || strpos( $raw, '%22' ) !== false ) {
			$raw = urldecode( $raw );
		}

		$data = json_decode( $raw, true );

		if ( is_array( $data ) && ! empty( $data['distinct_id'] ) ) {
			return sanitize_text_field( $data['distinct_id'] );
		}

		return null;
	}

	/**
	 * Get the distinct ID associated with an order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return string Distinct ID for the order.
	 */
	public function get_distinct_id_for_order( $order ) {
		$meta_distinct_id = $order->get_meta( '_apha_distinct_id' );

		if ( ! empty( $meta_distinct_id ) ) {
			return $meta_distinct_id;
		}

		$customer_id = $order->get_customer_id();

		if ( $customer_id ) {
			return 'wp_' . $customer_id;
		}

		// Last resort: generate a new UUID so the event still has an identity.
		$fallback_id = wp_generate_uuid4();
		$order->update_meta_data( '_apha_distinct_id', $fallback_id );
		$order->save();

		return $fallback_id;
	}

	/**
	 * Identify a user upon login and merge anonymous identity.
	 *
	 * Uses the canonical $identify event with $anon_distinct_id instead
	 * of the deprecated $create_alias approach. Single API call.
	 *
	 * @param string  $user_login The username.
	 * @param WP_User $user       The authenticated user object.
	 * @return void
	 */
	public function identify_user( $user_login, $user ) {
		$new_distinct_id = 'wp_' . $user->ID;

		$person_set = array(
			'email' => $user->user_email,
			'name'  => $user->display_name,
		);

		// Prefer PostHog's browser distinct_id for merging — this is the ID
		// that the JS SDK used for all browser events ($pageview, Checkout Started, etc.).
		// Merging this with wp_XX is what makes the funnel connect end-to-end.
		$anon_id = $this->get_posthog_browser_distinct_id();

		if ( empty( $anon_id ) ) {
			$anon_id = isset( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] )
				? sanitize_text_field( wp_unslash( $_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] ) )
				: null;
		}

		// Merge anonymous identity with authenticated identity in one API call.
		if ( ! empty( $anon_id ) && $anon_id !== $new_distinct_id ) {
			$this->api->merge_identities( $new_distinct_id, $anon_id, $person_set );
		} else {
			$this->api->identify( $new_distinct_id, $person_set );
		}

		// Update the cookie to the authenticated distinct ID.
		$this->set_cookie( $new_distinct_id );
	}

	/**
	 * Persist the current distinct ID to an order as meta data.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function persist_distinct_id_to_order( $order ) {
		$distinct_id = $this->get_distinct_id();

		if ( empty( $distinct_id ) ) {
			return;
		}

		$order->update_meta_data( '_apha_distinct_id', $distinct_id );
		$order->save();
	}

	/**
	 * Identify a user from order data with LTV enrichment.
	 *
	 * Sets person properties from billing data, computes LTV metrics,
	 * and uses $set_once for acquisition/first-order properties.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function identify_from_order( $order ) {
		$distinct_id = $this->get_distinct_id_for_order( $order );

		// Compute LTV metrics.
		$customer_id   = $order->get_customer_id();
		$total_orders  = 1;
		$lifetime_value = (float) $order->get_total();

		if ( $customer_id ) {
			$total_orders   = $this->count_customer_orders( $customer_id );
			$lifetime_value = $this->sum_customer_order_totals( $customer_id );
		}

		$avg_order_value = $total_orders > 0 ? round( $lifetime_value / $total_orders, 2 ) : 0;

		$person_set = array(
			'email'            => $order->get_billing_email(),
			'name'             => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'            => $order->get_billing_phone(),
			'city'             => $order->get_billing_city(),
			'state'            => $order->get_billing_state(),
			'country'          => $order->get_billing_country(),
			'total_orders'     => $total_orders,
			'lifetime_value'   => $lifetime_value,
			'avg_order_value'  => $avg_order_value,
			'last_order_date'  => current_time( 'c' ),
		);

		$person_set_once = array(
			'first_order_date' => current_time( 'c' ),
			'created_at'       => current_time( 'c' ),
		);

		// Add acquisition attribution from first-touch data.
		if ( $this->attribution ) {
			$acquisition = $this->attribution->get_acquisition_properties( $order );
			$person_set_once = array_merge( $person_set_once, $acquisition );
		}

		$email = $order->get_billing_email();

		if ( ! empty( $email ) && ! str_starts_with( $distinct_id, 'wp_' ) && $distinct_id !== $email ) {
			// Anonymous UUID — merge with email so PostHog links all sessions.
			$this->api->merge_identities( $email, $distinct_id, $person_set, $person_set_once );
		} else {
			$this->api->identify( $distinct_id, $person_set, $person_set_once );
		}
	}

	/**
	 * Count the total number of completed/processing orders for a customer.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @return int Order count.
	 */
	private function count_customer_orders( $customer_id ) {
		return (int) wc_get_customer_order_count( $customer_id );
	}

	/**
	 * Sum the total value of all completed/processing orders for a customer.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @return float Total spent.
	 */
	private function sum_customer_order_totals( $customer_id ) {
		return (float) wc_get_customer_total_spent( $customer_id );
	}

	/**
	 * Set the distinct ID cookie.
	 *
	 * @param string $distinct_id The distinct ID value.
	 * @return void
	 */
	private function set_cookie( $distinct_id ) {
		$expiry = time() + YEAR_IN_SECONDS;

		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::APHA_DISTINCT_ID_COOKIE, $distinct_id, $expiry, false );
		} else {
			setcookie(
				self::APHA_DISTINCT_ID_COOKIE,
				$distinct_id,
				array(
					'expires'  => $expiry,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}

		// Make the cookie available in the current request.
		$_COOKIE[ self::APHA_DISTINCT_ID_COOKIE ] = $distinct_id;
	}
}
