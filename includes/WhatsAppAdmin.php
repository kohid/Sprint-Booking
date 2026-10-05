<?php
/**
 * Settings → WhatsApp: the set-up steps, the keys, the webhook address and the test buttons.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class WhatsAppAdmin {

	public static function init(): void {
		add_action( 'admin_post_sb_wa', array( self::class, 'handle' ) );
	}

	private static function link( string $url, string $label ): string {
		return '<a class="sb-ui-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . ' <span aria-hidden="true">↗</span></a>';
	}

	/** Steps from "no WhatsApp number" to "customers get updates and can book". */
	public static function setup_panel( array $c, callable $open, callable $close ): void {
		$w    = $c['whatsapp'];
		$link = array( self::class, 'link' );
		$meta = 'meta' === $w['provider'];

		$steps = array(
			array(
				'title' => __( 'Choose how to connect', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'Twilio is the easier start, and the same account can run the phone line too. Meta (the WhatsApp Cloud API) has no middleman fee but asks you to verify your business first. Pick one in the settings below; you can change later.', 'sprint-booking' ) . '</p><p class="sb-ui-help">' . esc_html__( 'Either way, WhatsApp charges per conversation or message and has to approve a business number. Check current prices with your provider before you go live.', 'sprint-booking' ) . '</p>',
			),
			array(
				'title' => __( 'Twilio: a WhatsApp sender', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'To try it today, use Twilio\'s WhatsApp sandbox: you and your testers send a join code to Twilio\'s number first. For real customers, register your own WhatsApp sender (a number, your business name and a Meta business account).', 'sprint-booking' ) . '</p><p class="sb-ui-links">'
					. $link( 'https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn', __( 'WhatsApp sandbox', 'sprint-booking' ) )
					. $link( 'https://console.twilio.com/us1/develop/sms/senders/whatsapp-senders', __( 'Register a WhatsApp sender', 'sprint-booking' ) )
					. $link( 'https://console.twilio.com/', __( 'Account SID and Auth Token', 'sprint-booking' ) )
					. '</p><p class="sb-ui-help">' . esc_html__( 'The Account SID and Auth Token are on the Console home page, under Account Info. The "From" number is the sandbox number or your own sender, with the country code.', 'sprint-booking' ) . '</p>',
				'done'  => ! $meta && '' !== $w['twilio_sid'] && '' !== $w['twilio_token'],
			),
			array(
				'title' => __( 'Meta: the Cloud API', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'Create a Meta app with the WhatsApp product, add your number, and make a permanent access token (a system user token) rather than the 24-hour test token. Copy the Phone number ID, the token and the App secret.', 'sprint-booking' ) . '</p><p class="sb-ui-links">'
					. $link( 'https://developers.facebook.com/apps/', __( 'Meta for Developers: your apps', 'sprint-booking' ) )
					. $link( 'https://business.facebook.com/settings/system-users', __( 'System users (permanent token)', 'sprint-booking' ) )
					. $link( 'https://developers.facebook.com/docs/whatsapp/cloud-api/get-started', __( 'Cloud API: get started', 'sprint-booking' ) )
					. '</p><p class="sb-ui-help">' . esc_html__( 'Phone number ID and the test token are on the app\'s WhatsApp → API Setup page. The App secret is under App settings → Basic.', 'sprint-booking' ) . '</p>',
				'done'  => $meta && '' !== $w['meta_phone_id'] && '' !== $w['meta_token'] && '' !== $w['meta_secret'],
			),
			array(
				'title' => __( 'Paste the keys and save', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'Fill in the keys for your provider below, tick "Switch WhatsApp on" and save. The keys are never shown again, only their last four characters.', 'sprint-booking' ) . '</p>',
				'done'  => WhatsApp::ready(),
			),
			array(
				'title' => __( 'Tell the provider where to send messages', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'Copy the webhook address from "Connect and test" below into Twilio ("When a message comes in", method POST) or into Meta (WhatsApp → Configuration → Webhook; also subscribe to "messages"). Without this, customers can receive updates but cannot book by replying.', 'sprint-booking' ) . '</p>',
			),
			array(
				'title' => __( 'For updates: an approved message template', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'WhatsApp only lets you message a customer freely for 24 hours after they last wrote to you. Booking updates usually arrive outside that, so they must use a template that WhatsApp has approved. Make one with a single variable, category Utility, for example:', 'sprint-booking' ) . '</p><pre class="sb-ui-pre">' . esc_html( 'Booking update from ' . get_bloginfo( 'name' ) . ': {{1}}' ) . '</pre><p class="sb-ui-links">'
					. $link( 'https://console.twilio.com/us1/develop/sms/content-template-builder', __( 'Twilio: Content Template Builder', 'sprint-booking' ) )
					. $link( 'https://business.facebook.com/wa/manage/message-templates/', __( 'Meta: message templates', 'sprint-booking' ) )
					. '</p><p class="sb-ui-help">' . esc_html__( 'Then enter its name (Meta) or Content SID, starting HX (Twilio), below. Approval can take a few minutes to a day. Replies inside a conversation never need a template.', 'sprint-booking' ) . '</p>',
				'done'  => '' !== $w['template'],
			),
			array(
				'title' => __( 'Check and try it', 'sprint-booking' ),
				'body'  => '<p>' . esc_html__( 'Use "Check the connection" and "Send a test message", then send "hi" to your WhatsApp number from your own phone and make a test booking.', 'sprint-booking' ) . '</p>',
			),
		);

		$open( 'whatsapp', __( 'Set up WhatsApp', 'sprint-booking' ), __( 'Seven steps from no WhatsApp number to customers getting updates and booking by message. Ticked steps are done; the others you tick yourself.', 'sprint-booking' ) );
		echo '<ol class="sb-ui-steps">';
		foreach ( $steps as $i => $st ) {
			$auto = array_key_exists( 'done', $st );
			printf(
				'<li class="sb-ui-step%1$s" data-sb-step="wa%2$d"><span class="sb-ui-step__dot" aria-hidden="true"></span><div class="sb-ui-step__main"><h3>%3$s</h3>%4$s%5$s</div></li>',
				$auto && $st['done'] ? ' is-done' : '',
				(int) $i,
				esc_html( $st['title'] ),
				$st['body'], // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
				$auto ? '' : '<label class="sb-ui-check sb-ui-step__check"><input type="checkbox" data-sb-step-check="wa' . (int) $i . '"> ' . esc_html__( 'I have done this', 'sprint-booking' ) . '</label>'
			);
		}
		echo '</ol>';
		$close();
	}

	/** Inside the settings form. */
	public static function form_panel( array $c, string $name, callable $row, callable $open, callable $close ): void {
		$w = $c['whatsapp'];
		$n = $name . '[whatsapp]';
		$open( 'whatsapp', __( 'WhatsApp settings', 'sprint-booking' ), __( 'Keys and what to use WhatsApp for.', 'sprint-booking' ) );

		$row( 'sb-wa-on', __( 'WhatsApp', 'sprint-booking' ), '<label class="sb-ui-check"><input id="sb-wa-on" type="checkbox" name="' . esc_attr( $n ) . '[enabled]" value="1"' . checked( ! empty( $w['enabled'] ), true, false ) . '> ' . esc_html__( 'Switch WhatsApp on', 'sprint-booking' ) . '</label>' );
		$row( 'sb-wa-notify', __( 'Booking updates', 'sprint-booking' ), '<label class="sb-ui-check"><input id="sb-wa-notify" type="checkbox" name="' . esc_attr( $n ) . '[notify]" value="1"' . checked( ! empty( $w['notify'] ), true, false ) . '> ' . esc_html__( 'Send updates to customers who agreed to them', 'sprint-booking' ) . '</label>', __( 'The booking form then shows a "Send my booking updates on WhatsApp" box. Sent: booking received, confirmed, driver assigned, cancelled, changed by staff, and payment received. Customers can reply STOP.', 'sprint-booking' ) );
		$row( 'sb-wa-assist', __( 'Booking assistant', 'sprint-booking' ), '<label class="sb-ui-check"><input id="sb-wa-assist" type="checkbox" name="' . esc_attr( $n ) . '[assistant]" value="1"' . checked( ! empty( $w['assistant'] ), true, false ) . '> ' . esc_html__( 'Let customers book, cancel and change by messaging this number', 'sprint-booking' ) . '</label>', __( 'The same questions as the website chat, answered one message at a time. Blocked numbers (under Phone agent) are ignored.', 'sprint-booking' ) );
		$row( 'sb-wa-greeting', __( 'Greeting', 'sprint-booking' ), '<textarea id="sb-wa-greeting" class="sb-ui-input" rows="2" maxlength="300" name="' . esc_attr( $n ) . '[greeting]">' . esc_textarea( (string) $w['greeting'] ) . '</textarea>', __( 'Shown above the menu when a customer first writes.', 'sprint-booking' ) );
		$row( 'sb-wa-provider', __( 'Connect with', 'sprint-booking' ), '<select id="sb-wa-provider" class="sb-ui-input sb-ui-input--short" name="' . esc_attr( $n ) . '[provider]"><option value="twilio"' . selected( $w['provider'], 'twilio', false ) . '>Twilio</option><option value="meta"' . selected( $w['provider'], 'meta', false ) . '>' . esc_html__( 'Meta Cloud API', 'sprint-booking' ) . '</option></select>' );

		echo '<div class="sb-ui-gw"><div class="sb-ui-gw__head"><h3>Twilio</h3></div>';
		$row( 'sb-wa-tsid', __( 'Account SID', 'sprint-booking' ), '<input id="sb-wa-tsid" class="sb-ui-input" type="text" autocomplete="off" spellcheck="false" name="' . esc_attr( $n ) . '[twilio_sid]" value="' . esc_attr( (string) $w['twilio_sid'] ) . '" placeholder="AC…">' );
		echo Admin::secret_input( 'sb-wa-ttok', __( 'Auth token', 'sprint-booking' ), $n . '[twilio_token]', (string) $w['twilio_token'], __( 'Auth token', 'sprint-booking' ), __( 'Also used to check that incoming messages really come from Twilio.', 'sprint-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$row( 'sb-wa-tfrom', __( 'From number', 'sprint-booking' ), '<input id="sb-wa-tfrom" class="sb-ui-input" type="tel" name="' . esc_attr( $n ) . '[twilio_from]" value="' . esc_attr( (string) $w['twilio_from'] ) . '" placeholder="+14155238886">', __( 'Your WhatsApp sender, or the sandbox number, with the country code.', 'sprint-booking' ) );
		echo '</div>';

		echo '<div class="sb-ui-gw"><div class="sb-ui-gw__head"><h3>' . esc_html__( 'Meta Cloud API', 'sprint-booking' ) . '</h3></div>';
		$row( 'sb-wa-mid', __( 'Phone number ID', 'sprint-booking' ), '<input id="sb-wa-mid" class="sb-ui-input" type="text" inputmode="numeric" autocomplete="off" name="' . esc_attr( $n ) . '[meta_phone_id]" value="' . esc_attr( (string) $w['meta_phone_id'] ) . '">', __( 'Not the phone number itself: the long ID on the API Setup page.', 'sprint-booking' ) );
		echo Admin::secret_input( 'sb-wa-mtok', __( 'Access token', 'sprint-booking' ), $n . '[meta_token]', (string) $w['meta_token'], 'EAA…', __( 'Use a permanent system-user token.', 'sprint-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo Admin::secret_input( 'sb-wa-msec', __( 'App secret', 'sprint-booking' ), $n . '[meta_secret]', (string) $w['meta_secret'], __( 'App secret', 'sprint-booking' ), __( 'Used to check that incoming messages really come from Meta.', 'sprint-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		echo '<div class="sb-ui-gw"><div class="sb-ui-gw__head"><h3>' . esc_html__( 'Template for updates', 'sprint-booking' ) . '</h3></div>';
		$row( 'sb-wa-tpl', __( 'Template name or Content SID', 'sprint-booking' ), '<input id="sb-wa-tpl" class="sb-ui-input" type="text" autocomplete="off" name="' . esc_attr( $n ) . '[template]" value="' . esc_attr( (string) $w['template'] ) . '" placeholder="booking_update / HX…">', __( 'Meta: the template name. Twilio: the Content SID (starts HX). Leave blank to send plain messages, which WhatsApp only delivers within 24 hours of the customer\'s last message.', 'sprint-booking' ) );
		$row( 'sb-wa-lang', __( 'Template language', 'sprint-booking' ), '<input id="sb-wa-lang" class="sb-ui-input sb-ui-input--short" type="text" maxlength="6" name="' . esc_attr( $n ) . '[template_lang]" value="' . esc_attr( (string) $w['template_lang'] ) . '">', __( 'Meta only, such as en_GB.', 'sprint-booking' ) );
		echo '</div>';
		echo '<label class="sb-ui-check sb-ui-gw__clear"><input type="checkbox" name="' . esc_attr( $n ) . '[clear]" value="1"> ' . esc_html__( 'Remove all saved WhatsApp keys', 'sprint-booking' ) . '</label>';
		$close();
	}

	/** Outside the settings form: the webhook address, and the check and test buttons. */
	public static function connection_panel( callable $open, callable $close ): void {
		$c   = WhatsApp::cfg();
		$url = WhatsApp::webhook_url();
		$open( 'whatsapp', __( 'Connect and test', 'sprint-booking' ), __( 'Save your keys first, then check them. A check sends nothing.', 'sprint-booking' ) );

		$key = 'sb_wa_result_' . get_current_user_id();
		$res = get_transient( $key );
		if ( is_array( $res ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . ( $res['ok'] ? 'success' : 'error' ) . ' inline"><p>' . esc_html( (string) $res['message'] ) . '</p></div>';
		}

		echo '<dl class="sb-ui-endpoints"><div><dt><span class="sb-d-badge sb-d-badge--primary">' . esc_html__( 'Webhook', 'sprint-booking' ) . '</span></dt><dd><code>' . esc_html( $url ) . '</code> <button type="button" class="button-link" data-sb-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button><br><span class="sb-ui-help">'
			. esc_html( 'meta' === $c['provider'] ? __( 'Paste this as the Callback URL in Meta, with the verify token below, and subscribe to "messages".', 'sprint-booking' ) : __( 'Paste this into Twilio as "When a message comes in", method HTTP POST.', 'sprint-booking' ) )
			. '</span></dd></div>';
		if ( 'meta' === $c['provider'] ) {
			$tok = WhatsApp::verify_token();
			echo '<div><dt>' . esc_html__( 'Verify token', 'sprint-booking' ) . '</dt><dd><code>' . esc_html( $tok ) . '</code> <button type="button" class="button-link" data-sb-copy="' . esc_attr( $tok ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button></dd></div>';
		}
		echo '</dl>';
		if ( 0 !== strpos( $url, 'https://' ) ) {
			echo '<p class="sb-ui-help">' . esc_html__( 'WhatsApp providers only call secure (https) addresses. This site address is not https, so incoming messages will not reach it until it is.', 'sprint-booking' ) . '</p>';
		}

		$form = static function ( string $op, string $inner ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-ui-row">';
			wp_nonce_field( 'sb_wa' );
			echo '<input type="hidden" name="action" value="sb_wa"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">' . $inner . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		};
		$form( 'check', '<span class="sb-ui-label">' . esc_html__( 'Keys', 'sprint-booking' ) . '</span><div><button class="sb-d-btn sb-d-btn--light">' . esc_html__( 'Check the connection', 'sprint-booking' ) . '</button></div>' );
		$form( 'send', '<label for="sb-wa-to">' . esc_html__( 'Send a test message to', 'sprint-booking' ) . '</label><div><input id="sb-wa-to" class="sb-ui-input" type="tel" name="to" required placeholder="+44 7700 900123"> <button class="sb-d-btn sb-d-btn--light">' . esc_html__( 'Send test', 'sprint-booking' ) . '</button>'
			. ( '' !== $c['template'] ? '<label class="sb-ui-check"><input type="checkbox" name="use_template" value="1"> ' . esc_html__( 'Send it with the approved template (as a booking update would be)', 'sprint-booking' ) . '</label>' : '' )
			. '<p class="sb-ui-help">' . esc_html__( 'Without the template this only arrives if that phone has messaged your number in the last 24 hours (or has joined the Twilio sandbox).', 'sprint-booking' ) . '</p></div>' );
		if ( 'meta' === $c['provider'] ) {
			$form( 'renew', '<span class="sb-ui-label">' . esc_html__( 'Verify token', 'sprint-booking' ) . '</span><div><button class="sb-d-btn sb-d-btn--light" onclick="return confirm(\'' . esc_js( __( 'Make a new verify token? You must paste it into Meta again.', 'sprint-booking' ) ) . '\')">' . esc_html__( 'Make a new token', 'sprint-booking' ) . '</button></div>' );
		}

		$err = WhatsApp::last_error();
		if ( $err ) {
			echo '<p class="sb-ui-help"><strong>' . esc_html__( 'Last problem:', 'sprint-booking' ) . '</strong> ' . esc_html( wp_date( 'D j M, H:i', (int) $err['time'] ) . ': ' . $err['message'] ) . '</p>';
		}
		$out = get_option( WhatsApp::OPTOUT_OPTION, array() );
		echo '<p class="sb-ui-help">' . esc_html( sprintf( /* translators: %d: number of people */ _n( '%d person has replied STOP. They are kept as a fingerprint of their number, not the number.', '%d people have replied STOP. They are kept as fingerprints of their numbers, not the numbers.', is_array( $out ) ? count( $out ) : 0, 'sprint-booking' ), is_array( $out ) ? count( $out ) : 0 ) ) . '</p>';
		$close();
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_wa' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'renew' === $op ) {
			WhatsApp::verify_token( true );
			$out = array( 'ok' => true, 'message' => __( 'New verify token made. Paste it into Meta.', 'sprint-booking' ) );
		} elseif ( 'send' === $op ) {
			$to  = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
			$res = WhatsApp::send( $to, 'Test message from ' . get_bloginfo( 'name' ) . ': WhatsApp is connected.', ! empty( $_POST['use_template'] ) );
			$out = is_wp_error( $res )
				? array( 'ok' => false, 'message' => $res->get_error_message() )
				: array( 'ok' => true, 'message' => __( 'The provider accepted the message. If it does not arrive, see the notes under the button.', 'sprint-booking' ) );
		} else {
			$res = WhatsApp::test_connection();
			$out = array( 'ok' => ! is_wp_error( $res ), 'message' => is_wp_error( $res ) ? $res->get_error_message() : $res );
		}
		set_transient( 'sb_wa_result_' . get_current_user_id(), $out, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=sb-settings#whatsapp' ) );
		exit;
	}
}
