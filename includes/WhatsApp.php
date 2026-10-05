<?php
/**
 * WhatsApp: updates to customers who agreed to them, and a two-way booking assistant.
 *
 *   GET  /sprint-booking/v1/whatsapp/webhook   Meta's one-time check when you save the webhook address
 *   POST /sprint-booking/v1/whatsapp/webhook   messages from customers (Twilio or Meta Cloud API)
 *
 * Every POST is checked against the provider's signature before anything is read. Customers' replies
 * are answered by WhatsAppFlow; what the flow decides to do goes through WhatsAppBackend, so bookings,
 * prices, cancel/change rules and emails are exactly those of the website form.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class WhatsApp {

	public const ERROR_OPTION  = 'sb_wa_error';
	public const OPTOUT_OPTION = 'sb_wa_optout';
	public const VERIFY_OPTION = 'sb_wa_verify';
	private const STATE_TTL    = 6 * HOUR_IN_SECONDS;
	private const MAX_OPTOUTS  = 5000;

	// ── Settings and readiness ────────────────────────────────────

	/** @return array<string,mixed> */
	public static function cfg(): array {
		return (array) Settings::get()['whatsapp'];
	}

	/** Keys for the chosen provider are present (not that they are correct: Settings has a test). */
	public static function ready(): bool {
		$c = self::cfg();
		if ( empty( $c['enabled'] ) ) {
			return false;
		}
		return 'meta' === $c['provider']
			? '' !== $c['meta_phone_id'] && '' !== $c['meta_token']
			: '' !== $c['twilio_sid'] && '' !== $c['twilio_token'] && '' !== WhatsAppRules::digits( (string) $c['twilio_from'] );
	}

	/** Whether the booking form should offer "send my updates on WhatsApp". */
	public static function offers_updates(): bool {
		return self::ready() && ! empty( self::cfg()['notify'] );
	}

	public static function webhook_url(): string {
		return esc_url_raw( rest_url( Rest::NS . '/whatsapp/webhook' ) );
	}

	/** The token Meta asks for when you save the webhook. Made once; not a secret that unlocks anything. */
	public static function verify_token( bool $renew = false ): string {
		$t = (string) get_option( self::VERIFY_OPTION, '' );
		if ( '' === $t || $renew ) {
			$t = 'sbwa_' . wp_generate_password( 24, false );
			update_option( self::VERIFY_OPTION, $t, false );
		}
		return $t;
	}

	public static function last_error(): ?array {
		$e = get_option( self::ERROR_OPTION );
		return is_array( $e ) ? $e : null;
	}

	/** Why the last send failed, for Settings. Keys and tokens that a provider might echo are masked. */
	public static function note_error( string $message ): void {
		$message = (string) preg_replace( '/\b(EAA[A-Za-z0-9]{10,}|AC[a-f0-9]{32}|SK[a-f0-9]{32})\b/', '[key]', wp_strip_all_tags( $message ) );
		update_option( self::ERROR_OPTION, array( 'time' => time(), 'message' => mb_substr( $message, 0, 300 ) ), false );
	}

	// ── Opt-out list (hashes only) ────────────────────────────────

	private static function salt(): string {
		return wp_salt( 'auth' ) . 'sb_wa';
	}

	public static function opted_out( string $number ): bool {
		$list = get_option( self::OPTOUT_OPTION, array() );
		return is_array( $list ) && in_array( WhatsAppRules::hash_number( $number, self::salt() ), $list, true );
	}

	private static function set_opt_out( string $number, bool $out ): void {
		$list = get_option( self::OPTOUT_OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$h    = WhatsAppRules::hash_number( $number, self::salt() );
		$list = array_values( array_diff( $list, array( $h ) ) );
		if ( $out ) {
			$list[] = $h;
		}
		update_option( self::OPTOUT_OPTION, array_slice( $list, -self::MAX_OPTOUTS ), false );
	}

	// ── Sending ───────────────────────────────────────────────────

	/**
	 * @param bool $initiated True for updates we start (they need an approved template when set, because the 24-hour window is probably closed);
	 *                        false for replies inside a conversation the customer started.
	 * @return true|\WP_Error
	 */
	public static function send( string $to, string $text, bool $initiated ) {
		$c = self::cfg();
		if ( ! self::ready() ) {
			return new \WP_Error( 'sb_wa', __( 'WhatsApp is not set up yet.', 'sprint-booking' ) );
		}
		$digits = WhatsAppRules::digits( $to );
		if ( ! WhatsAppRules::valid_digits( $digits ) ) {
			return new \WP_Error( 'sb_wa', __( 'That does not look like a phone number.', 'sprint-booking' ) );
		}
		$template = $initiated ? (string) $c['template'] : '';
		$pieces   = '' !== $template ? array( mb_substr( WhatsAppRules::flatten( $text ), 0, 1000 ) ) : WhatsAppRules::split_text( $text );

		foreach ( $pieces as $piece ) {
			if ( 'meta' === $c['provider'] ) {
				$res = Http::request(
					'POST',
					'https://graph.facebook.com/v21.0/' . rawurlencode( (string) $c['meta_phone_id'] ) . '/messages',
					array( 'Authorization' => 'Bearer ' . $c['meta_token'], 'Content-Type' => 'application/json' ),
					(string) wp_json_encode( WhatsAppRules::meta_payload( $digits, $piece, $template, (string) $c['template_lang'] ) )
				);
			} else {
				$res = Http::request(
					'POST',
					'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( (string) $c['twilio_sid'] ) . '/Messages.json',
					array( 'Authorization' => 'Basic ' . base64_encode( $c['twilio_sid'] . ':' . $c['twilio_token'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ),
					http_build_query( WhatsAppRules::twilio_params( (string) $c['twilio_from'], $digits, $piece, $template ) )
				);
			}
			if ( is_wp_error( $res ) || $res['code'] < 200 || $res['code'] > 299 ) {
				$why = is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? $res['json']['message'] ?? ( 'HTTP ' . $res['code'] ) );
				self::note_error( $why );
				return new \WP_Error( 'sb_wa', $why );
			}
		}
		return true;
	}

	/** Check the saved keys without sending anything. @return string|\WP_Error */
	public static function test_connection() {
		$c = self::cfg();
		if ( ! self::ready() ) {
			return new \WP_Error( 'sb_wa', __( 'Switch WhatsApp on and fill in the keys for your provider first, then save.', 'sprint-booking' ) );
		}
		if ( 'meta' === $c['provider'] ) {
			$res = Http::request( 'GET', 'https://graph.facebook.com/v21.0/' . rawurlencode( (string) $c['meta_phone_id'] ) . '?fields=display_phone_number,verified_name', array( 'Authorization' => 'Bearer ' . $c['meta_token'] ) );
		} else {
			$res = Http::request( 'GET', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( (string) $c['twilio_sid'] ) . '.json', array( 'Authorization' => 'Basic ' . base64_encode( $c['twilio_sid'] . ':' . $c['twilio_token'] ) ) );
		}
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return new \WP_Error( 'sb_wa', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? $res['json']['message'] ?? ( 'HTTP ' . $res['code'] ) ) );
		}
		if ( 'meta' === $c['provider'] ) {
			/* translators: 1: number, 2: business name */
			return sprintf( __( 'Meta accepted the token. Number %1$s (%2$s).', 'sprint-booking' ), (string) ( $res['json']['display_phone_number'] ?? '?' ), (string) ( $res['json']['verified_name'] ?? '?' ) );
		}
		/* translators: %s: account status */
		return sprintf( __( 'Twilio accepted the account SID and token (account is %s).', 'sprint-booking' ), (string) ( $res['json']['status'] ?? 'active' ) );
	}

	// ── Updates to customers ──────────────────────────────────────

	private static function updates_on( array $b ): bool {
		$c = self::cfg();
		return self::ready() && ! empty( $c['notify'] ) && ! empty( $b['whatsapp_optin'] ) && ! self::opted_out( (string) ( $b['customer_phone'] ?? '' ) );
	}

	/** @param array<string,mixed> $b */
	private static function tell( array $b, string $text ): void {
		if ( '' !== $text && self::updates_on( $b ) ) {
			self::send( (string) $b['customer_phone'], $text, true );
		}
	}

	public static function booking_created( array $row, ?array $ret, string $fare, bool $online ): void {
		if ( 'whatsapp' === ( $row['source'] ?? '' ) ) {
			return; // They booked here, so the conversation already told them.
		}
		self::tell( $row, WhatsAppRules::created( $row, $ret, $fare, $online, wp_timezone() ) );
	}

	public static function status_changed( int $id, string $status ): void {
		$b = in_array( $status, array( 'confirmed', 'assigned', 'cancelled' ), true ) ? Bookings::find( $id ) : null;
		if ( $b ) {
			self::tell( $b, WhatsAppRules::status( $b, $status, wp_timezone() ) );
		}
	}

	/** @param string[] $changes */
	public static function booking_updated( array $b, array $changes ): void {
		self::tell( $b, WhatsAppRules::updated( $b, $changes ) );
	}

	public static function payment_received( array $b, int $pence ): void {
		self::tell( $b, WhatsAppRules::paid( $b, Settings::money( $pence ) ) );
	}

	// ── The webhook ───────────────────────────────────────────────

	public static function register(): void {
		register_rest_route(
			Rest::NS,
			'/whatsapp/webhook',
			array(
				array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'verify' ), 'permission_callback' => '__return_true' ),
				array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, 'webhook' ), 'permission_callback' => '__return_true' ),
			)
		);
		// Twilio expects XML back. An empty <Response/> means "nothing more to say"; we answer through the API.
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $request ) {
				if ( $request instanceof \WP_REST_Request && 'POST' === $request->get_method() && '/' . Rest::NS . '/whatsapp/webhook' === $request->get_route() && 'twilio' === self::cfg()['provider'] && 200 === $result->get_status() ) {
					header( 'Content-Type: text/xml; charset=UTF-8' );
					echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>'; // phpcs:ignore WordPress.Security.EscapeOutput
					return true;
				}
				return $served;
			},
			10,
			3
		);
	}

	/** Meta's one-time handshake. */
	public static function verify( \WP_REST_Request $req ) {
		$answer = WhatsAppRules::meta_challenge( (array) $req->get_query_params(), self::verify_token() );
		if ( null === $answer ) {
			return new \WP_Error( 'sb_forbidden', __( 'Not allowed.', 'sprint-booking' ), array( 'status' => 403 ) );
		}
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo $answer; // phpcs:ignore WordPress.Security.EscapeOutput -- the challenge is only released when the token matched, and is echoed as plain text.
		exit;
	}

	public static function webhook( \WP_REST_Request $req ) {
		$c = self::cfg();
		if ( empty( $c['enabled'] ) ) {
			return new \WP_Error( 'sb_wa_off', __( 'WhatsApp is switched off.', 'sprint-booking' ), array( 'status' => 403 ) );
		}
		$forbidden = new \WP_Error( 'sb_forbidden', __( 'Not allowed.', 'sprint-booking' ), array( 'status' => 403 ) );

		if ( 'meta' === $c['provider'] ) {
			if ( ! WhatsAppRules::meta_ok( $req->get_body(), (string) $c['meta_secret'], (string) $req->get_header( 'x_hub_signature_256' ) ) ) {
				return $forbidden;
			}
			$data = (array) json_decode( $req->get_body(), true );
		} else {
			$data = (array) $req->get_body_params();
			if ( ! WhatsAppRules::twilio_ok( self::webhook_url(), $data, (string) $c['twilio_token'], (string) $req->get_header( 'x_twilio_signature' ) ) ) {
				return $forbidden;
			}
		}

		foreach ( WhatsAppRules::parse_inbound( (string) $c['provider'], $data ) as $m ) {
			// Providers retry; handle each message once.
			$seen = 'sb_wa_m_' . md5( (string) $c['provider'] . '|' . $m['id'] );
			if ( '' !== $m['id'] ) {
				if ( get_transient( $seen ) ) {
					continue;
				}
				set_transient( $seen, 1, DAY_IN_SECONDS );
			}
			self::handle_message( $m, $c );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** @param array{from:string,text:string,id:string,name:string} $m */
	private static function handle_message( array $m, array $c ): void {
		$from = $m['from'];
		$cfg  = Settings::get();

		if ( VoiceRules::is_blocked( (string) $cfg['voice']['blocked_numbers'], $from ) ) {
			Calls::log( 'blocked', 'whatsapp', '', $from );
			return;
		}
		// A customer, a bug or a loop could flood us; this is per number, because every message comes from the provider's own addresses.
		$key   = 'sb_wa_rl_' . md5( $from );
		$count = (int) get_transient( $key );
		if ( $count >= 40 ) {
			return;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		if ( WhatsAppRules::is_stop( $m['text'] ) ) {
			self::set_opt_out( $from, true );
			self::send( $from, __( 'You will not get booking updates on WhatsApp any more. Send START to turn them back on. Booking emails are not affected.', 'sprint-booking' ), false );
			return;
		}
		if ( WhatsAppRules::is_start( $m['text'] ) ) {
			self::set_opt_out( $from, false );
			self::send( $from, __( 'Booking updates on WhatsApp are on again. Send MENU to make a booking.', 'sprint-booking' ), false );
			return;
		}
		if ( empty( $c['assistant'] ) ) {
			$hint = '' !== (string) $cfg['voice']['operator_number'] ? ' ' . sprintf( /* translators: %s: phone number */ __( 'Please call us on %s.', 'sprint-booking' ), $cfg['voice']['operator_number'] ) : '';
			$once = 'sb_wa_busy_' . md5( $from );
			if ( ! get_transient( $once ) ) {
				set_transient( $once, 1, 12 * HOUR_IN_SECONDS );
				self::send( $from, __( 'Thanks for your message. We cannot take bookings on WhatsApp yet.', 'sprint-booking' ) . $hint, false );
			}
			return;
		}

		$skey  = 'sb_wa_s_' . md5( $from );
		$state = get_transient( $skey );
		$state = is_array( $state ) ? $state : null;
		$res   = WhatsAppFlow::handle( $state, $m['text'], self::env( $from, $m['name'], $cfg ), new WhatsAppBackend( $from, $m['name'] ) );
		if ( null === $res['state'] ) {
			delete_transient( $skey );
		} else {
			set_transient( $skey, $res['state'], self::STATE_TTL );
		}
		foreach ( $res['replies'] as $reply ) {
			self::send( $from, $reply, false );
		}
	}

	/** @return array<string,mixed> What the flow needs to know about today's settings. */
	private static function env( string $from, string $name, array $cfg ): array {
		$tz       = wp_timezone();
		$services = array();
		foreach ( $cfg['services'] as $k => $s ) {
			$services[ $k ] = array( 'label' => $s['label'], 'quote_only' => ! empty( $s['quote_only'] ), 'minibus_only' => ! empty( $s['minibus_only'] ) );
		}
		$vehicles = array();
		foreach ( $cfg['vehicles'] as $k => $v ) {
			$vehicles[ $k ] = array( 'label' => $v['label'], 'capacity' => (int) $v['capacity'], 'bags' => (int) $v['bags'], 'minibus' => ! empty( $v['minibus'] ) );
		}
		return array(
			'now'          => new \DateTimeImmutable( 'now', $tz ),
			'earliest'     => \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', Rest::earliest_local( $cfg ), $tz ) ?: new \DateTimeImmutable( '+1 hour', $tz ),
			'from'         => '+' . $from,
			'profile_name' => $name,
			'operator'     => (string) $cfg['voice']['operator_number'],
			'greeting'     => (string) $cfg['whatsapp']['greeting'],
			'services'     => $services,
			'vehicles'     => $vehicles,
		);
	}
}
