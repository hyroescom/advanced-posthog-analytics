<?php
/**
 * InsightTrail for PostHog Frontend Events.
 *
 * Enqueues the PostHog JS SDK snippet and the InsightTrail for PostHog tracker script
 * that captures client-side e-commerce events.
 *
 * @package InsightTrailForPostHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Frontend_Events
 *
 * Enqueues the PostHog JavaScript SDK via wp_add_inline_script() and the
 * InsightTrail for PostHog tracker script in the footer to capture browsing,
 * cart, and checkout events on the frontend.
 */
class APHA_Frontend_Events {

	/**
	 * Constructor.
	 *
	 * Registers hooks for script enqueuing.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the PostHog SDK and InsightTrail tracker script.
	 *
	 * jQuery is not required — the tracker guards all jQuery usage with
	 * `if (window.jQuery)` checks. This prevents forcing jQuery on
	 * block-based themes that don't load it.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( is_admin() ) {
			return;
		}

		$api_key = APHA_Settings::get_api_key();

		if ( ! empty( $api_key ) ) {
			// Register a virtual script handle for the PostHog SDK (no file, loads in <head>).
			wp_register_script( 'apha-posthog-sdk', false, array(), APHA_VERSION, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter,WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Virtual handle for inline script.
			wp_enqueue_script( 'apha-posthog-sdk' );
			wp_add_inline_script( 'apha-posthog-sdk', $this->build_posthog_snippet() );
		}

		wp_enqueue_script(
			'apha-tracker',
			APHA_PLUGIN_URL . 'assets/js/apha-tracker.js',
			! empty( $api_key ) ? array( 'apha-posthog-sdk' ) : array(),
			APHA_VERSION,
			true
		);

		wp_localize_script(
			'apha-tracker',
			'aphaConfig',
			array(
				'cartUrl'           => wc_get_cart_url(),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'personProfiles'    => APHA_Settings::get_person_profiles(),
				'formIdentify'      => APHA_Settings::is_form_identify_enabled() ? '1' : '0',
				'elementVisibility' => APHA_Settings::is_element_visibility_enabled() ? '1' : '0',
			)
		);
	}

	/**
	 * Build the PostHog JavaScript SDK initialization snippet.
	 *
	 * @return string JavaScript code for PostHog SDK bootstrap, init, identify, and consent helpers.
	 */
	private function build_posthog_snippet() {
		$api_key         = APHA_Settings::get_api_key();
		$api_host        = APHA_Settings::get_posthog_host();
		$ui_host         = APHA_Settings::get_posthog_ui_host();
		$person_profiles = APHA_Settings::get_person_profiles();
		$consent_mode    = APHA_Settings::is_consent_mode_enabled();

		// Build the init config object properties.
		$config_parts = array(
			'api_host: ' . wp_json_encode( $api_host ),
		);

		// Only include ui_host when a proxy is in use (api_host differs from ui_host).
		if ( $api_host !== $ui_host ) {
			$config_parts[] = 'ui_host: ' . wp_json_encode( $ui_host );
		}

		$config_parts[] = 'person_profiles: ' . wp_json_encode( $person_profiles );

		// Enable native pageview capture for web analytics, session replay URL
		// timeline, and automatic UTM extraction.
		$config_parts[] = 'capture_pageview: true';
		$config_parts[] = 'capture_pageleave: true';

		// Consent mode: start opted-out, use memory persistence until consent.
		if ( $consent_mode ) {
			$config_parts[] = 'opt_out_capturing_by_default: true';
			$config_parts[] = "persistence: 'memory'";
		}

		$config_js = implode( ', ', $config_parts );

		// PostHog SDK stub + loader.
		$script = '!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing startSessionRecording stopSessionRecording sessionRecordingStarted loadToolbar get_property getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSurvey onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSurvey getSurveyResponse".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);' . "\n";

		// Init call.
		$script .= 'posthog.init(' . wp_json_encode( $api_key ) . ', { ' . $config_js . ' });' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are JSON-encoded.

		// For logged-in users, call posthog.identify() so the JS SDK uses
		// the same wp_XX distinct_id as the server. This is critical for
		// connecting browser events (Product Viewed, Checkout Started) with
		// server events (Order Completed) in PostHog funnels.
		if ( is_user_logged_in() ) {
			$wp_distinct_id = 'wp_' . get_current_user_id();
			$script        .= 'posthog.identify(' . wp_json_encode( $wp_distinct_id ) . ');' . "\n";
		}

		// Consent mode helpers.
		if ( $consent_mode ) {
			$script .= "window.aphaOptIn = function() {\n";
			$script .= "	if (window.posthog) {\n";
			$script .= "		posthog.opt_in_capturing();\n";
			$script .= "		posthog.set_config({persistence: 'localStorage+cookie'});\n";
			$script .= "		if (typeof window.aphaInitFormIdentify === 'function') { window.aphaInitFormIdentify(); }\n";
			$script .= "		if (typeof window.aphaInitElementVisibility === 'function') { window.aphaInitElementVisibility(); }\n";
			$script .= "	}\n";
			$script .= "};\n";
			$script .= "window.aphaOptOut = function() {\n";
			$script .= "	if (window.posthog) { posthog.opt_out_capturing(); }\n";
			$script .= "};\n";
			// CookieYes integration.
			$script .= "document.addEventListener('cookie_consent_given', function() { window.aphaOptIn(); });\n";
			// Complianz integration.
			$script .= "document.addEventListener('cmplz_fire_statistics', function() { window.aphaOptIn(); });\n";
		}

		return $script;
	}
}
