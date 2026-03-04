<?php
/**
 * Advanced PostHog Analytics PostHog API Client.
 *
 * Handles all HTTP communication with the PostHog capture API
 * using the WordPress HTTP API (wp_remote_post).
 *
 * @package AdvancedPostHogAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_PostHog_API
 *
 * Provides methods to send events, identify users, merge identities,
 * and batch multiple events via the PostHog HTTP API.
 */
class APHA_PostHog_API {

	/**
	 * PostHog project API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * PostHog host URL.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Constructor.
	 *
	 * Reads configuration from APHA_Settings static helpers.
	 */
	public function __construct() {
		$this->api_key = APHA_Settings::get_api_key();
		$this->host    = APHA_Settings::get_posthog_host();
	}

	/**
	 * Get the PostHog capture endpoint URL.
	 *
	 * @return string Full URL to the /capture/ endpoint.
	 */
	private function get_capture_endpoint() {
		return $this->host . '/capture/';
	}

	/**
	 * Get the PostHog batch endpoint URL.
	 *
	 * @return string Full URL to the /batch/ endpoint.
	 */
	private function get_batch_endpoint() {
		return $this->host . '/batch/';
	}

	/**
	 * Send an event to PostHog.
	 *
	 * @param string $distinct_id Unique user identifier.
	 * @param string $event       Event name.
	 * @param array  $properties  Optional. Additional event properties.
	 * @param bool   $blocking    Optional. Whether to wait for the response. Default false.
	 *
	 * @return bool True on success (or fire-and-forget), false on failure.
	 */
	public function capture( $distinct_id, $event, $properties = array(), $blocking = false ) {
		if ( empty( $this->api_key ) || empty( $distinct_id ) ) {
			return false;
		}

		$properties = array_merge(
			$properties,
			array(
				'distinct_id'  => $distinct_id,
				'$lib'         => 'advanced-posthog-analytics',
				'$lib_version' => APHA_VERSION,
			)
		);

		$payload = array(
			'api_key'    => $this->api_key,
			'event'      => $event,
			'properties' => $properties,
			'timestamp'  => gmdate( 'c' ),
		);

		return $this->post( $this->get_capture_endpoint(), $payload, $blocking );
	}

	/**
	 * Identify a user in PostHog with $set and $set_once support.
	 *
	 * @param string $distinct_id Unique user identifier.
	 * @param array  $set         Optional. Person properties to set (overwrite).
	 * @param array  $set_once    Optional. Person properties to set only on first encounter.
	 * @param bool   $blocking    Optional. Whether to wait for the response. Default false.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function identify( $distinct_id, $set = array(), $set_once = array(), $blocking = false ) {
		$properties = array();

		if ( ! empty( $set ) ) {
			$properties['$set'] = $set;
		}

		if ( ! empty( $set_once ) ) {
			$properties['$set_once'] = $set_once;
		}

		return $this->capture( $distinct_id, '$identify', $properties, $blocking );
	}

	/**
	 * Merge an anonymous identity with an identified user in PostHog.
	 *
	 * Uses the canonical $identify event with $anon_distinct_id instead
	 * of the deprecated $create_alias approach.
	 *
	 * @param string $identified_id    The authenticated/known distinct ID.
	 * @param string $anonymous_id     The anonymous distinct ID to merge.
	 * @param array  $person_set       Optional. Person properties to $set.
	 * @param array  $person_set_once  Optional. Person properties to $set_once.
	 * @param bool   $blocking         Optional. Whether to wait for the response. Default false.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function merge_identities( $identified_id, $anonymous_id, $person_set = array(), $person_set_once = array(), $blocking = false ) {
		$properties = array(
			'$anon_distinct_id' => $anonymous_id,
		);

		if ( ! empty( $person_set ) ) {
			$properties['$set'] = $person_set;
		}

		if ( ! empty( $person_set_once ) ) {
			$properties['$set_once'] = $person_set_once;
		}

		return $this->capture( $identified_id, '$identify', $properties, $blocking );
	}

	/**
	 * Send a batch of events to PostHog in a single HTTP request.
	 *
	 * Each item in the batch array should have: event, distinct_id, properties, timestamp.
	 *
	 * @param array $events   Array of event payloads.
	 * @param bool  $blocking Optional. Whether to wait for the response. Default false.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function batch( $events, $blocking = false ) {
		if ( empty( $this->api_key ) || empty( $events ) ) {
			return false;
		}

		$batch = array();

		foreach ( $events as $event ) {
			$properties = isset( $event['properties'] ) ? $event['properties'] : array();

			$properties = array_merge(
				$properties,
				array(
					'distinct_id'  => $event['distinct_id'],
					'$lib'         => 'advanced-posthog-analytics',
					'$lib_version' => APHA_VERSION,
				)
			);

			$batch[] = array(
				'event'      => $event['event'],
				'properties' => $properties,
				'timestamp'  => isset( $event['timestamp'] ) ? $event['timestamp'] : gmdate( 'c' ),
			);
		}

		$payload = array(
			'api_key' => $this->api_key,
			'batch'   => $batch,
		);

		return $this->post( $this->get_batch_endpoint(), $payload, $blocking );
	}

	/**
	 * Send an HTTP POST request to PostHog.
	 *
	 * @param string $url      Endpoint URL.
	 * @param array  $payload  Request body data.
	 * @param bool   $blocking Whether to wait for the response.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function post( $url, $payload, $blocking ) {
		$response = wp_remote_post(
			$url,
			array(
				'body'        => wp_json_encode( $payload ),
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'timeout'     => $blocking ? 5 : 1,
				'blocking'    => $blocking,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'APHA: PostHog API error - ' . $response->get_error_message() );
			return false;
		}

		if ( $blocking ) {
			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $response_code ) {
				error_log(
					sprintf(
						'APHA: PostHog API returned HTTP %d.',
						$response_code
					)
				);
				return false;
			}

			return true;
		}

		// Non-blocking: fire-and-forget.
		return true;
	}
}
