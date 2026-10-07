<?php
/**
 * Branded HTML emails: one table-based layout (logo header, journey cards, summary, buttons) and a
 * plain-text twin. Pure: it takes data and returns strings, so it can be tested without WordPress mail.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class EmailTemplate {

	public const RED   = '#E20A17';
	public const BLUE  = '#0b6bcb';
	public const INK   = '#101820';
	public const SLATE = '#5b6672';

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/** Only http(s) links go into href/src. */
	private static function url( $u ): string {
		$u = (string) $u;
		return preg_match( '#^https?://#i', $u ) ? self::e( $u ) : '';
	}

	/**
	 * @param array $o {
	 *   brand:     array{name:string,logo_url:string,site_url:string,footer:string}
	 *   preheader: string
	 *   heading:   string
	 *   intro:     string
	 *   refs:      array<int,array{label:string,reference:string,kind:string}>  kind: out|ret|single
	 *   journeys:  array<int,array{label:string,kind:string,when:string,stops:string[],note:string}>
	 *   rows:      array<int,array{0:string,1:string}>     label/value pairs (service, passengers, vehicle...)
	 *   fare:      array<int,array{0:string,1:string}>     fare breakdown lines
	 *   total:     array{0:string,1:string}|null           emphasised last line
	 *   contact:   array<int,array{0:string,1:string}>     customer details (office copy only)
	 *   buttons:   array<int,array{label:string,url:string,style:string}>
	 *   notes:     string[]                                small print paragraphs
	 * }
	 * @return array{html:string,text:string}
	 */
	public static function render( array $o ): array {
		return array(
			'html' => self::html( $o ),
			'text' => self::text( $o ),
		);
	}

	private static function color( string $kind ): string {
		return 'ret' === $kind ? self::BLUE : self::RED;
	}

	public static function html( array $o ): string {
		$brand = $o['brand'] + array( 'name' => '', 'logo_url' => '', 'site_url' => '', 'footer' => '' );
		$name  = self::e( $brand['name'] );
		$home  = self::url( $brand['site_url'] );
		$logo  = self::url( $brand['logo_url'] );

		$font = "font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$h    = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . self::e( $o['heading'] ?? $brand['name'] ) . '</title>';
		$h   .= '<style>@media only screen and (max-width:520px){.sbm-col{display:block!important;width:100%!important;padding:0 0 12px 0!important}.sbm-pad{padding:20px 16px!important}.sbm-ref{font-size:20px!important}}</style></head>';
		$h   .= '<body style="margin:0;padding:0;background:#f3f4f6;' . $font . 'color:' . self::INK . ';">';
		$h   .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f3f4f6;">' . self::e( $o['preheader'] ?? '' ) . str_repeat( '&#8199;&zwnj;', 20 ) . '</div>';
		$h   .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">';
		$h   .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">';

		// Header: logo (or the name as a wordmark) over a red rule.
		$h .= '<tr><td align="center" style="padding:26px 24px 20px;border-bottom:4px solid ' . self::RED . ';background:#ffffff;">';
		if ( $logo ) {
			$img = '<img src="' . $logo . '" alt="' . $name . '" style="display:block;border:0;outline:none;max-width:200px;max-height:72px;width:auto;height:auto;margin:0 auto;">';
			$h  .= $home ? '<a href="' . $home . '" style="text-decoration:none;">' . $img . '</a>' : $img;
		} else {
			$h .= '<div style="' . $font . 'font-size:24px;font-weight:800;letter-spacing:.02em;color:' . self::RED . ';">' . $name . '</div>';
		}
		$h .= '</td></tr>';

		$h .= '<tr><td class="sbm-pad" style="padding:28px 28px 8px;' . $font . '">';
		$h .= '<h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:' . self::INK . ';">' . self::e( $o['heading'] ?? '' ) . '</h1>';
		if ( ! empty( $o['intro'] ) ) {
			$h .= '<p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:' . self::SLATE . ';">' . self::e( $o['intro'] ) . '</p>';
		}

		// References: one dashed box per journey, side by side (stacked on phones).
		$refs = $o['refs'] ?? array();
		if ( $refs ) {
			$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>';
			$n  = count( $refs );
			foreach ( $refs as $i => $r ) {
				$c   = self::color( $r['kind'] ?? 'out' );
				$pad = 1 === $n ? '0' : ( 0 === $i ? '0 6px 0 0' : '0 0 0 6px' );
				$h  .= '<td class="sbm-col" width="' . ( 100 / max( 1, $n ) ) . '%" valign="top" style="padding:' . $pad . ';">';
				$h  .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:14px 8px;border:2px dashed ' . $c . ';border-radius:8px;">';
				if ( '' !== (string) ( $r['label'] ?? '' ) ) {
					$h .= '<div style="display:inline-block;margin:0 0 8px;padding:3px 12px;border-radius:999px;background:' . ( 'ret' === ( $r['kind'] ?? '' ) ? '#e3f0fc' : '#fde8ea' ) . ';color:' . $c . ';font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">' . self::e( $r['label'] ) . '</div><br>';
				}
				$h .= '<span class="sbm-ref" style="font-size:24px;font-weight:800;letter-spacing:.06em;color:' . self::INK . ';">' . self::e( $r['reference'] ) . '</span>';
				$h .= '</td></tr></table></td>';
			}
			$h .= '</tr></table>';
		}

		// Journey cards.
		foreach ( $o['journeys'] ?? array() as $j ) {
			$c  = self::color( $j['kind'] ?? 'out' );
			$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;border:1px solid #e5e7eb;border-left:5px solid ' . $c . ';border-radius:8px;"><tr><td style="padding:14px 16px;' . $font . '">';
			if ( '' !== (string) ( $j['label'] ?? '' ) ) {
				$h .= '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . $c . ';">' . self::e( $j['label'] ) . '</div>';
			}
			$h .= '<div style="margin:2px 0 10px;font-size:17px;font-weight:700;color:' . self::INK . ';">' . self::e( $j['when'] ?? '' ) . '</div>';
			$stops = array_values( (array) ( $j['stops'] ?? array() ) );
			$last  = count( $stops ) - 1;
			$h    .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">';
			foreach ( $stops as $i => $s ) {
				$tag = 0 === $i ? 'Pickup' : ( $i === $last ? 'Drop-off' : 'Via' );
				$dot = 0 === $i ? $c : ( $i === $last ? self::INK : '#9aa3ad' );
				$h  .= '<tr><td width="14" valign="top" style="padding:5px 8px 5px 0;"><div style="width:10px;height:10px;border-radius:50%;background:' . $dot . ';"></div></td><td style="padding:2px 0;font-size:14px;line-height:1.45;color:' . self::INK . ';"><span style="color:' . self::SLATE . ';font-size:12px;text-transform:uppercase;letter-spacing:.05em;">' . $tag . '</span><br>' . self::e( $s ) . '</td></tr>';
			}
			$h .= '</table>';
			if ( ! empty( $j['note'] ) ) {
				$h .= '<div style="margin-top:8px;font-size:13px;color:' . self::SLATE . ';">' . self::e( $j['note'] ) . '</div>';
			}
			$h .= '</td></tr></table>';
		}

		// Details, fare, total.
		$table = static function ( array $rows ) use ( $font ): string {
			$t = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;">';
			foreach ( $rows as $r ) {
				$t .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eef0f2;font-size:14px;color:' . self::SLATE . ';' . $font . '" width="38%" valign="top">' . self::e( $r[0] ) . '</td><td style="padding:7px 0;border-bottom:1px solid #eef0f2;font-size:14px;color:' . self::INK . ';font-weight:600;' . $font . '" valign="top">' . self::e( $r[1] ) . '</td></tr>';
			}
			return $t . '</table>';
		};
		if ( ! empty( $o['rows'] ) ) {
			$h .= $table( $o['rows'] );
		}
		if ( ! empty( $o['fare'] ) ) {
			$h .= $table( $o['fare'] );
		}
		if ( ! empty( $o['total'] ) ) {
			$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;background:#fff5f5;border-radius:8px;"><tr><td style="padding:14px 16px;font-size:15px;font-weight:700;color:' . self::INK . ';' . $font . '">' . self::e( $o['total'][0] ) . '</td><td align="right" style="padding:14px 16px;font-size:22px;font-weight:800;color:' . self::RED . ';' . $font . '">' . self::e( $o['total'][1] ) . '</td></tr></table>';
		}
		if ( ! empty( $o['contact'] ) ) {
			$h .= '<div style="margin:6px 0 4px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . self::SLATE . ';">Customer</div>' . $table( $o['contact'] );
		}

		foreach ( $o['buttons'] ?? array() as $b ) {
			$u = self::url( $b['url'] ?? '' );
			if ( ! $u ) {
				continue;
			}
			$fill = 'ghost' === ( $b['style'] ?? '' ) ? 'background:#ffffff;color:' . self::INK . ';border:2px solid ' . self::INK . ';' : 'background:' . self::RED . ';color:#ffffff;border:2px solid ' . self::RED . ';';
			$h   .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;"><tr><td style="border-radius:8px;' . $fill . '"><a href="' . $u . '" style="display:inline-block;padding:12px 26px;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;' . $fill . $font . '">' . self::e( $b['label'] ) . '</a></td></tr></table>';
		}
		foreach ( $o['notes'] ?? array() as $n ) {
			$h .= '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:' . self::SLATE . ';word-break:break-word;overflow-wrap:anywhere;">' . self::e( $n ) . '</p>';
		}
		$h .= '</td></tr>';

		// Footer.
		$h .= '<tr><td align="center" style="padding:18px 24px 24px;background:#fafafa;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:' . self::SLATE . ';' . $font . '">';
		$h .= '<strong style="color:' . self::INK . ';">' . $name . '</strong>';
		if ( ! empty( $brand['footer'] ) ) {
			$h .= '<br>' . nl2br( self::e( $brand['footer'] ), false );
		}
		if ( $home ) {
			$h .= '<br><a href="' . $home . '" style="color:' . self::RED . ';text-decoration:none;">' . self::e( preg_replace( '#^https?://#i', '', rtrim( $brand['site_url'], '/' ) ) ) . '</a>';
		}
		$h .= '</td></tr></table></td></tr></table></body></html>';
		return $h;
	}

	/** The same content as readable plain text (the email's text/plain part). */
	public static function text( array $o ): string {
		$brand = $o['brand'] + array( 'name' => '', 'footer' => '', 'site_url' => '' );
		$t     = array( $o['heading'] ?? '', '' );
		if ( ! empty( $o['intro'] ) ) {
			$t[] = $o['intro'];
			$t[] = '';
		}
		foreach ( $o['refs'] ?? array() as $r ) {
			$t[] = ( '' !== (string) ( $r['label'] ?? '' ) ? $r['label'] . ' reference: ' : 'Reference: ' ) . $r['reference'];
		}
		if ( ! empty( $o['refs'] ) ) {
			$t[] = '';
		}
		foreach ( $o['journeys'] ?? array() as $j ) {
			$t[] = '== ' . ( '' !== (string) ( $j['label'] ?? '' ) ? strtoupper( $j['label'] ) . ' ==' : 'JOURNEY ==' );
			$t[] = $j['when'] ?? '';
			$stops = array_values( (array) ( $j['stops'] ?? array() ) );
			$last  = count( $stops ) - 1;
			foreach ( $stops as $i => $s ) {
				$t[] = ( 0 === $i ? 'Pickup:   ' : ( $i === $last ? 'Drop-off: ' : 'Via:      ' ) ) . $s;
			}
			if ( ! empty( $j['note'] ) ) {
				$t[] = $j['note'];
			}
			$t[] = '';
		}
		foreach ( array( 'rows', 'fare' ) as $k ) {
			foreach ( $o[ $k ] ?? array() as $r ) {
				$t[] = str_pad( $r[0] . ':', 14 ) . $r[1];
			}
			if ( ! empty( $o[ $k ] ) ) {
				$t[] = '';
			}
		}
		if ( ! empty( $o['total'] ) ) {
			$t[] = strtoupper( $o['total'][0] ) . ': ' . $o['total'][1];
			$t[] = '';
		}
		if ( ! empty( $o['contact'] ) ) {
			$t[] = 'Customer';
			foreach ( $o['contact'] as $r ) {
				$t[] = str_pad( $r[0] . ':', 14 ) . $r[1];
			}
			$t[] = '';
		}
		foreach ( $o['buttons'] ?? array() as $b ) {
			if ( preg_match( '#^https?://#i', (string) ( $b['url'] ?? '' ) ) ) {
				$t[] = $b['label'] . ': ' . $b['url'];
			}
		}
		foreach ( $o['notes'] ?? array() as $n ) {
			$t[] = '';
			$t[] = $n;
		}
		$t[] = '';
		$t[] = '--';
		$t[] = $brand['name'];
		if ( '' !== (string) $brand['footer'] ) {
			$t[] = $brand['footer'];
		}
		if ( '' !== (string) $brand['site_url'] ) {
			$t[] = $brand['site_url'];
		}
		return implode( "\n", $t );
	}
}
