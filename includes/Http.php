<?php
/**
 * One place for calls to payment providers, so tests can stand in for the network.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class Http {

	/** @var null|callable(string,string,array<string,string>,mixed):array{code:int,body:string}|\WP_Error Tests set this. */
	public static $fake = null;

	/**
	 * @param string               $method  GET or POST.
	 * @param array<string,string> $headers
	 * @param string|null          $body    Already encoded.
	 * @return array{code:int,json:array<string,mixed>}|\WP_Error json is [] when the body is not JSON.
	 */
	public static function request( string $method, string $url, array $headers = array(), ?string $body = null ) {
		if ( null !== self::$fake ) {
			$res = ( self::$fake )( $method, $url, $headers, $body );
		} else {
			$res = wp_remote_request(
				$url,
				array(
					'method'     => $method,
					'timeout'    => 20,
					'headers'    => $headers,
					'body'       => $body,
					'user-agent' => 'SprintBooking/' . SB_VERSION,
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$res = array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ) );
			}
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$json = json_decode( (string) $res['body'], true );
		return array( 'code' => (int) $res['code'], 'json' => is_array( $json ) ? $json : array() );
	}
}
