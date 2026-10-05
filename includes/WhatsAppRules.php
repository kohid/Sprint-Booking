<?php
/**
 * The pure rules behind the WhatsApp channel: phone numbers, webhook signatures, the shape of messages
 * coming in from Twilio or Meta, understanding "tomorrow 2pm", choosing a car, and the wording of the
 * updates we send. No WordPress calls, so tests/whatsapp-rules-test.php can run it on its own.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class WhatsAppRules {

	public const PROVIDERS = array( 'twilio', 'meta' );

	/** Words that switch our update messages off and back on. */
	public const STOP_WORDS  = array( 'stop', 'unsubscribe', 'optout', 'opt out', 'cancel updates' );
	public const START_WORDS = array( 'start', 'subscribe', 'optin', 'opt in' );

	private const MONTHS = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );
	private const DAYS   = array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 );

	// ── Phone numbers ─────────────────────────────────────────────

	/** A number in any common shape (07700 900123, +44 7700 900123, whatsapp:+447700900123) -> digits with the country code, e.g. 447700900123. */
	public static function digits( string $number, string $country = '44' ): string {
		$d = preg_replace( '/\D+/', '', preg_replace( '/^whatsapp:/i', '', trim( $number ) ) ?? '' ) ?? '';
		if ( str_starts_with( $d, '00' ) ) {
			$d = substr( $d, 2 );
		} elseif ( str_starts_with( $d, '0' ) ) {
			$d = $country . substr( $d, 1 );
		}
		return $d;
	}

	public static function valid_digits( string $d ): bool {
		return 1 === preg_match( '/^[1-9][0-9]{8,14}$/', $d );
	}

	/** Same person's number whatever the formatting. */
	public static function same_number( string $a, string $b ): bool {
		$x = self::digits( $a );
		return '' !== $x && $x === self::digits( $b );
	}

	/** Opt-outs are kept as hashes of the number, never the number itself. */
	public static function hash_number( string $number, string $salt ): string {
		return substr( hash_hmac( 'sha256', self::digits( $number ), $salt ), 0, 24 );
	}

	// ── Webhook signatures ────────────────────────────────────────

	/** Twilio signs the full URL plus every POST field, name then value, sorted by name. */
	public static function twilio_signature( string $url, array $params, string $token ): string {
		ksort( $params );
		$data = $url;
		foreach ( $params as $k => $v ) {
			$data .= $k . ( is_array( $v ) ? implode( '', $v ) : (string) $v );
		}
		return base64_encode( hash_hmac( 'sha1', $data, $token, true ) );
	}

	public static function twilio_ok( string $url, array $params, string $token, string $given ): bool {
		return '' !== $token && '' !== $given && hash_equals( self::twilio_signature( $url, $params, $token ), $given );
	}

	/** Meta signs the raw request body: X-Hub-Signature-256 is "sha256=" and the HMAC with the app secret. */
	public static function meta_ok( string $body, string $app_secret, string $header ): bool {
		if ( '' === $app_secret || ! str_starts_with( $header, 'sha256=' ) ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $body, $app_secret ), substr( $header, 7 ) );
	}

	/** Meta's one-time check when you save the webhook: answer with the challenge only if the token matches. */
	public static function meta_challenge( array $query, string $verify_token ): ?string {
		if ( ( $query['hub_mode'] ?? $query['hub.mode'] ?? '' ) !== 'subscribe' || '' === $verify_token ) {
			return null;
		}
		$given = (string) ( $query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '' );
		return hash_equals( $verify_token, $given ) ? (string) ( $query['hub_challenge'] ?? $query['hub.challenge'] ?? '' ) : null;
	}

	// ── Messages coming in ────────────────────────────────────────

	/**
	 * @param string              $provider twilio or meta.
	 * @param array<string,mixed> $data     Twilio: the POST fields. Meta: the decoded JSON.
	 * @return array<int,array{from:string,text:string,id:string,name:string}> Text messages only; delivery receipts and media are ignored.
	 */
	public static function parse_inbound( string $provider, array $data ): array {
		$out = array();
		if ( 'twilio' === $provider ) {
			$from = self::digits( (string) ( $data['From'] ?? '' ) );
			$text = trim( (string) ( $data['Body'] ?? '' ) );
			if ( '' !== $from && '' !== $text ) {
				$out[] = array( 'from' => $from, 'text' => mb_substr( $text, 0, 1000 ), 'id' => (string) ( $data['MessageSid'] ?? $data['SmsMessageSid'] ?? '' ), 'name' => mb_substr( (string) ( $data['ProfileName'] ?? '' ), 0, 80 ) );
			}
			return $out;
		}
		foreach ( (array) ( $data['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				$v     = (array) ( $change['value'] ?? array() );
				$names = array();
				foreach ( (array) ( $v['contacts'] ?? array() ) as $c ) {
					$names[ (string) ( $c['wa_id'] ?? '' ) ] = (string) ( $c['profile']['name'] ?? '' );
				}
				foreach ( (array) ( $v['messages'] ?? array() ) as $m ) {
					$text = '';
					if ( 'text' === ( $m['type'] ?? '' ) ) {
						$text = (string) ( $m['text']['body'] ?? '' );
					} elseif ( 'interactive' === ( $m['type'] ?? '' ) ) {
						$text = (string) ( $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '' );
					} elseif ( 'button' === ( $m['type'] ?? '' ) ) {
						$text = (string) ( $m['button']['text'] ?? '' );
					}
					$from = self::digits( (string) ( $m['from'] ?? '' ) );
					if ( '' !== $from && '' !== trim( $text ) ) {
						$out[] = array( 'from' => $from, 'text' => mb_substr( trim( $text ), 0, 1000 ), 'id' => (string) ( $m['id'] ?? '' ), 'name' => mb_substr( $names[ (string) ( $m['from'] ?? '' ) ] ?? '', 0, 80 ) );
					}
				}
			}
		}
		return $out;
	}

	public static function is_stop( string $text ): bool {
		return in_array( strtolower( trim( $text ) ), self::STOP_WORDS, true );
	}

	public static function is_start( string $text ): bool {
		return in_array( strtolower( trim( $text ) ), self::START_WORDS, true );
	}

	// ── Messages going out ────────────────────────────────────────

	/** @return string[] Pieces no longer than $max, split at line breaks where possible. */
	public static function split_text( string $text, int $max = 1500 ): array {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return array( $text );
		}
		$out = array();
		$cur = '';
		foreach ( explode( "\n", $text ) as $line ) {
			while ( mb_strlen( $line ) > $max ) {
				if ( '' !== $cur ) {
					$out[] = $cur;
					$cur   = '';
				}
				$out[] = mb_substr( $line, 0, $max );
				$line  = mb_substr( $line, $max );
			}
			if ( '' !== $cur && mb_strlen( $cur ) + 1 + mb_strlen( $line ) > $max ) {
				$out[] = $cur;
				$cur   = '';
			}
			$cur .= ( '' === $cur ? '' : "\n" ) . $line;
		}
		if ( '' !== $cur ) {
			$out[] = $cur;
		}
		return $out;
	}

	/**
	 * What to POST to Twilio's Messages API. With a Content SID, Twilio sends that approved template with our text as its one variable
	 * (needed when the customer has not messaged us in the last 24 hours).
	 *
	 * @return array<string,string>
	 */
	public static function twilio_params( string $from_digits, string $to_digits, string $text, string $content_sid = '' ): array {
		$p = array( 'From' => 'whatsapp:+' . self::digits( $from_digits ), 'To' => 'whatsapp:+' . $to_digits );
		if ( '' !== $content_sid ) {
			$p['ContentSid']       = $content_sid;
			$p['ContentVariables'] = (string) json_encode( array( '1' => $text ), JSON_UNESCAPED_UNICODE );
		} else {
			$p['Body'] = $text;
		}
		return $p;
	}

	/** What to POST to Meta's Cloud API. A template name sends that approved template with our text as its one body variable. */
	public static function meta_payload( string $to_digits, string $text, string $template = '', string $language = 'en_GB' ): array {
		if ( '' !== $template ) {
			return array(
				'messaging_product' => 'whatsapp',
				'to'                => $to_digits,
				'type'              => 'template',
				'template'          => array(
					'name'       => $template,
					'language'   => array( 'code' => $language ),
					'components' => array( array( 'type' => 'body', 'parameters' => array( array( 'type' => 'text', 'text' => $text ) ) ) ),
				),
			);
		}
		return array( 'messaging_product' => 'whatsapp', 'to' => $to_digits, 'type' => 'text', 'text' => array( 'preview_url' => false, 'body' => $text ) );
	}

	/** WhatsApp template variables cannot hold line breaks or long runs of spaces. */
	public static function flatten( string $text ): string {
		return trim( preg_replace( '/\s*\n+\s*/', ' | ', preg_replace( '/[ \t]{2,}/', ' ', $text ) ?? '' ) ?? '' );
	}

	/** @param array<string,mixed> $b A booking row. */
	private static function when( array $b, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( (string) $b['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M, H:i' );
	}

	private static function first_name( array $b ): string {
		$p = preg_split( '/\s+/', trim( (string) ( $b['customer_name'] ?? '' ) ) ) ?: array();
		return (string) ( $p[0] ?? '' );
	}

	private static function route( array $b ): string {
		$s = json_decode( (string) ( $b['stops'] ?? '[]' ), true );
		$s = is_array( $s ) ? array_values( $s ) : array();
		if ( count( $s ) < 2 ) {
			return '';
		}
		return (string) ( $s[0]['label'] ?? '' ) . ' to ' . (string) ( $s[ count( $s ) - 1 ]['label'] ?? '' );
	}

	/** "Booking received", for one booking or a way out plus its return. */
	public static function created( array $b, ?array $ret, string $fare_text, bool $pay_online, \DateTimeZone $tz ): string {
		$name = self::first_name( $b );
		$l    = array( ( '' !== $name ? 'Thanks ' . $name . '! ' : '' ) . ( $ret ? 'Your journeys are booked.' : 'Your booking is received.' ) );
		if ( $ret ) {
			$l[] = 'Way out ' . $b['reference'] . ': ' . self::when( $b, $tz );
			$l[] = 'Return ' . $ret['reference'] . ': ' . self::when( $ret, $tz );
		} else {
			$l[] = 'Reference ' . $b['reference'] . ': ' . self::when( $b, $tz );
		}
		$r = self::route( $b );
		if ( '' !== $r ) {
			$l[] = $r;
		}
		if ( '' !== $fare_text ) {
			$l[] = 'Fare ' . $fare_text . ( $ret ? ' in all' : '' ) . ( $pay_online ? ' (pay online or the driver)' : ' (pay the driver)' ) . '.';
		} else {
			$l[] = 'We will price this and message or email you shortly.';
		}
		$l[] = 'Quote a reference to cancel or change that journey.';
		return implode( "\n", $l );
	}

	public static function status( array $b, string $status, \DateTimeZone $tz ): string {
		$say = array(
			'confirmed' => 'Good news: your booking is confirmed.',
			'assigned'  => 'A driver has been assigned to your booking.',
			'cancelled' => 'Your booking has been cancelled. If this is unexpected, please contact us.',
		);
		return isset( $say[ $status ] ) ? $say[ $status ] . "\n" . $b['reference'] . ': ' . self::when( $b, $tz ) : '';
	}

	/** @param string[] $changes */
	public static function updated( array $b, array $changes ): string {
		return 'Your booking ' . $b['reference'] . " has been updated:\n- " . implode( "\n- ", $changes );
	}

	public static function paid( array $b, string $total_text ): string {
		return 'Payment received, thank you. ' . $total_text . ' paid for booking ' . $b['reference'] . '. A receipt is on its way by email.';
	}

	// ── Understanding free text ───────────────────────────────────

	/**
	 * "tomorrow 2pm", "fri 17:30", "12 oct 09:00", "12/10 9am", "2026-10-12 14:00", "14:30" -> a local time.
	 * A time is always needed. With no day, today if the time is still ahead, otherwise tomorrow.
	 */
	public static function parse_when( string $text, \DateTimeImmutable $now ): ?\DateTimeImmutable {
		$t = strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ?? '' ) );
		if ( '' === $t || mb_strlen( $t ) > 60 ) {
			return null;
		}

		$h = null;
		$m = 0;
		if ( preg_match( '/\b(\d{1,2}):(\d{2})\s*(am|pm)?\b/', $t, $x ) ) {
			$h = (int) $x[1];
			$m = (int) $x[2];
			$t = trim( str_replace( $x[0], ' ', $t ) );
			$ap = $x[3] ?? '';
		} elseif ( preg_match( '/\b(\d{1,2})\s*(am|pm)\b/', $t, $x ) ) {
			$h  = (int) $x[1];
			$t  = trim( str_replace( $x[0], ' ', $t ) );
			$ap = $x[2];
		} elseif ( preg_match( '/\bnoon\b/', $t ) ) {
			$h  = 12;
			$t  = trim( str_replace( 'noon', ' ', $t ) );
			$ap = '';
		} else {
			return null;
		}
		if ( '' !== $ap && ( $h < 1 || $h > 12 ) ) {
			return null;
		}
		if ( 'pm' === $ap && $h < 12 ) {
			$h += 12;
		} elseif ( 'am' === $ap && 12 === $h ) {
			$h = 0;
		}
		if ( $h > 23 || $m > 59 ) {
			return null;
		}

		$today = $now->setTime( 0, 0 );
		$date  = null;
		$year_given = false;
		if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $t, $d ) ) {
			$date = self::ymd( (int) $d[1], (int) $d[2], (int) $d[3], $now );
			$year_given = true;
		} elseif ( preg_match( '/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $t, $d ) ) {
			$y          = isset( $d[3] ) ? ( (int) $d[3] < 100 ? 2000 + (int) $d[3] : (int) $d[3] ) : (int) $now->format( 'Y' );
			$year_given = isset( $d[3] );
			$date       = self::ymd( $y, (int) $d[2], (int) $d[1], $now );
		} elseif ( preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\s*(?:of\s+)?(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:\s+(\d{4}))?\b/', $t, $d ) ) {
			$year_given = isset( $d[3] );
			$date       = self::ymd( isset( $d[3] ) ? (int) $d[3] : (int) $now->format( 'Y' ), self::MONTHS[ $d[2] ], (int) $d[1], $now );
		} elseif ( preg_match( '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s+(\d{4}))?\b/', $t, $d ) ) {
			$year_given = isset( $d[3] );
			$date       = self::ymd( isset( $d[3] ) ? (int) $d[3] : (int) $now->format( 'Y' ), self::MONTHS[ $d[1] ], (int) $d[2], $now );
		} elseif ( preg_match( '/\btomorrow\b/', $t ) ) {
			$date = $today->modify( '+1 day' );
		} elseif ( preg_match( '/\btoday\b|\btonight\b/', $t ) ) {
			$date = $today;
		} elseif ( preg_match( '/\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\b/', $t, $d ) ) {
			$diff = ( self::DAYS[ $d[1] ] - (int) $today->format( 'w' ) + 7 ) % 7;
			$date = $today->modify( '+' . $diff . ' day' );
			if ( 0 === $diff && $date->setTime( $h, $m ) <= $now ) {
				$date = $date->modify( '+7 day' );
			}
		}

		if ( null === $date ) {
			if ( preg_match( '/[a-z0-9]/', preg_replace( '/\b(at|on|around|about|for|the)\b/', '', $t ) ?? '' ) ) {
				return null; // Something we did not understand is left over: ask again rather than guess.
			}
			$date = $today;
			if ( $date->setTime( $h, $m ) <= $now ) {
				$date = $date->modify( '+1 day' );
			}
		} elseif ( ! $year_given && $date < $today ) {
			$date = $date->modify( '+1 year' ); // "5 Jan" typed in December means next January.
		}
		return $date->setTime( $h, $m );
	}

	private static function ymd( int $y, int $mo, int $d, \DateTimeImmutable $now ): ?\DateTimeImmutable {
		if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 || ! checkdate( $mo, $d, $y ) ) {
			return null;
		}
		return $now->setDate( $y, $mo, $d )->setTime( 0, 0 );
	}

	/** "2", "two" -> 2. Null when it is not a small whole number. */
	public static function number( string $text, int $min, int $max ): ?int {
		$words = array( 'zero' => 0, 'none' => 0, 'no' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10 );
		$t     = strtolower( trim( $text ) );
		$n     = isset( $words[ $t ] ) ? $words[ $t ] : ( preg_match( '/^\d{1,3}$/', $t ) ? (int) $t : null );
		return null !== $n && $n >= $min && $n <= $max ? $n : null;
	}

	/** yes/no in everyday words. Null when unclear. */
	public static function yes_no( string $text ): ?bool {
		$t = strtolower( trim( preg_replace( '/[.!]+$/', '', $text ) ?? '' ) );
		if ( in_array( $t, array( 'yes', 'y', 'yeah', 'yep', 'yup', 'ok', 'okay', 'sure', 'please', 'confirm', 'correct', 'book it', 'go ahead', '1' ), true ) ) {
			return true;
		}
		if ( in_array( $t, array( 'no', 'n', 'nope', 'nah', 'not now', 'no thanks', 'no thank you', '2' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * The smallest car that fits the party and the luggage. A minibus service only offers minibuses; otherwise cars first, then minibuses.
	 *
	 * @param array<string,array{capacity:int|string,bags:int|string,minibus?:bool}> $vehicles In size order.
	 */
	public static function pick_vehicle( array $vehicles, int $passengers, int $suitcases, bool $minibus_only ): ?string {
		foreach ( array( false, true ) as $minibus ) {
			if ( $minibus_only && ! $minibus ) {
				continue;
			}
			foreach ( $vehicles as $key => $v ) {
				if ( ! empty( $v['minibus'] ) === $minibus && (int) $v['capacity'] >= $passengers && (int) $v['bags'] >= $suitcases ) {
					return (string) $key;
				}
			}
		}
		return null;
	}
}
