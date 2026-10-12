<?php
/**
 * Security settings that belong to this site, not to the dashboard.
 *
 * - Allowed dashboard IP addresses: signed requests from any other address are
 *   refused, so a stolen dashboard key is useless from anywhere else.
 * - Permissions: switch off what the dashboard may do here (install plugins
 *   from a ZIP or web address, delete plugins, run updates, ...).
 * - Alert emails: tell someone on this site when the dashboard signs in,
 *   installs or deletes plugins, changes security settings, or when a correctly
 *   signed request is blocked.
 *
 * The dashboard can only ever make these settings stricter (lock to its IP when
 * no list is set, switch permissions off). Loosening them is only possible in
 * this site's own wp-admin. Note that one-click login and installing plugins from
 * a ZIP or web address each give the dashboard full control of the site, so for
 * the strongest protection lock the site to the dashboard's address and switch
 * those off unless they are needed.
 *
 * Optional wp-config.php constants:
 *   USCN_ALLOWED_IPS       Allowed addresses (comma or space separated, CIDR allowed).
 *                          Overrides the setting and cannot be changed in wp-admin.
 *   USCN_CLIENT_IP_HEADER  $_SERVER key holding the real visitor address when this
 *                          site is behind a proxy, e.g. 'HTTP_CF_CONNECTING_IP'.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Security {

	const OPT      = 'uscn_security';
	const OPT_SEEN = 'uscn_dashboard_seen';
	const OPT_BLK  = 'uscn_blocked';

	/** Permission => label. One-click login has its own setting (USCN_Login). */
	public static function perm_labels() {
		return array(
			'update'        => __( 'Run updates (plugins, themes, WordPress)', 'unstoppable-site-connector' ),
			'toggle'        => __( 'Activate and deactivate plugins', 'unstoppable-site-connector' ),
			'install_wporg' => __( 'Install plugins from WordPress.org', 'unstoppable-site-connector' ),
			'install_zip'   => __( 'Install plugins from a ZIP file or web address', 'unstoppable-site-connector' ),
			'delete'        => __( 'Delete plugins', 'unstoppable-site-connector' ),
		);
	}

	public static function alert_labels() {
		return array(
			'login'   => __( 'The dashboard signs in to this site', 'unstoppable-site-connector' ),
			'plugins' => __( 'The dashboard installs or deletes a plugin', 'unstoppable-site-connector' ),
			'blocked' => __( 'A correctly signed request comes from an address that is not allowed', 'unstoppable-site-connector' ),
			'changed' => __( 'The dashboard changes this site\'s security settings', 'unstoppable-site-connector' ),
			'paired'  => __( 'This site is connected to a dashboard', 'unstoppable-site-connector' ),
		);
	}

	/** @var string Dashboard user who sent the current signed request, if it said. */
	public static $by = '';

	/** @var string Address the current signed request came from. */
	public static $ip = '';

	public static function settings() {
		$s     = get_option( self::OPT );
		$s     = is_array( $s ) ? $s : array();
		$perms = array();
		foreach ( array_keys( self::perm_labels() ) as $p ) {
			$perms[ $p ] = isset( $s['perms'][ $p ] ) ? (bool) $s['perms'][ $p ] : true;
		}
		$alerts = array();
		foreach ( array_keys( self::alert_labels() ) as $a ) {
			$alerts[ $a ] = isset( $s['alerts'][ $a ] ) ? (bool) $s['alerts'][ $a ] : ( 'login' !== $a );
		}
		return array(
			'ips'         => isset( $s['ips'] ) && is_array( $s['ips'] ) ? array_values( $s['ips'] ) : array(),
			'perms'       => $perms,
			'alerts'      => $alerts,
			'alert_email' => isset( $s['alert_email'] ) ? (string) $s['alert_email'] : '',
		);
	}

	public static function save( array $s ) {
		update_option( self::OPT, $s, false );
	}

	public static function allowed( $perm ) {
		$s = self::settings();
		return ! isset( $s['perms'][ $perm ] ) || $s['perms'][ $perm ];
	}

	/* ---------- addresses ---------- */

	public static function ips_from_config() {
		return defined( 'USCN_ALLOWED_IPS' ) && '' !== trim( (string) USCN_ALLOWED_IPS );
	}

	/** @return string[] The allowed list in force (empty = any address). */
	public static function allowed_ips() {
		if ( self::ips_from_config() ) {
			return self::parse_ips( (string) USCN_ALLOWED_IPS )['ok'];
		}
		return self::settings()['ips'];
	}

	/**
	 * @param string $text Addresses separated by commas, spaces or new lines.
	 * @return array { ok: string[], bad: string[] }
	 */
	public static function parse_ips( $text ) {
		$ok  = array();
		$bad = array();
		foreach ( preg_split( '/[\s,]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $rule ) {
			if ( self::valid_rule( $rule ) ) {
				$bits = explode( '/', $rule );
				$addr = self::normalise_ip( $bits[0] );
				if ( isset( $bits[1] ) && $addr !== strtolower( $bits[0] ) ) {
					$bits[1] = (string) max( 0, (int) $bits[1] - 96 ); // ::ffff:a.b.c.d/120 -> a.b.c.d/24.
				}
				$ok[] = $addr . ( isset( $bits[1] ) ? '/' . $bits[1] : '' );
			} else {
				$bad[] = $rule;
			}
		}
		return array(
			'ok'  => array_slice( array_values( array_unique( $ok ) ), 0, 50 ),
			'bad' => $bad,
		);
	}

	private static function valid_rule( $rule ) {
		$parts = explode( '/', $rule );
		if ( count( $parts ) > 2 || ! filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( 2 === count( $parts ) ) {
			$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
			return ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
		}
		return true;
	}

	public static function ip_matches( $ip, $rule ) {
		$ip    = self::normalise_ip( $ip );
		$parts = explode( '/', $rule );
		$addr  = self::normalise_ip( $parts[0] );
		if ( isset( $parts[1] ) && $addr !== strtolower( $parts[0] ) ) {
			$parts[1] = (string) max( 0, (int) $parts[1] - 96 );
		}
		$parts[0] = $addr;
		$a     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b     = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $a ) * 8;
		$full = intdiv( $bits, 8 );
		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $a[ $full ] & $mask ) === ( $b[ $full ] & $mask );
	}

	public static function is_ip_allowed( $ip ) {
		$list = self::allowed_ips();
		if ( ! $list ) {
			return true;
		}
		foreach ( $list as $rule ) {
			if ( self::ip_matches( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/** The address this request came from. */
	public static function client_ip() {
		$key = defined( 'USCN_CLIENT_IP_HEADER' ) ? (string) USCN_CLIENT_IP_HEADER : 'REMOTE_ADDR';
		$raw = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// With a forwarding header, the right-most entry is the one the proxy itself added;
		// anything to its left was sent by the client and can't be trusted.
		$parts = explode( ',', $raw );
		$raw   = trim( (string) end( $parts ) );
		return filter_var( $raw, FILTER_VALIDATE_IP ) ? self::normalise_ip( $raw ) : '';
	}

	/** "::ffff:203.0.113.10" (IPv4 on a dual-stack server) is the same as "203.0.113.10". */
	public static function normalise_ip( $ip ) {
		$ip = strtolower( (string) $ip );
		if ( 0 === strpos( $ip, '::ffff:' ) && filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return substr( $ip, 7 );
		}
		return $ip;
	}

	/**
	 * Called for every correctly signed request, after the signature and replay checks.
	 *
	 * @return true|WP_Error
	 */
	public static function check_request( WP_REST_Request $request ) {
		self::$ip = self::client_ip();
		$p        = $request->get_json_params();
		self::$by = is_array( $p ) && isset( $p['_by'] ) ? substr( sanitize_user( (string) $p['_by'], true ), 0, 60 ) : '';

		if ( ! self::is_ip_allowed( self::$ip ) ) {
			$b = get_option( self::OPT_BLK );
			$b = is_array( $b ) ? $b : array();
			update_option(
				self::OPT_BLK,
				array(
					'ip'    => self::$ip,
					'time'  => time(),
					'route' => $request->get_route(),
					'count' => ( isset( $b['ip'] ) && $b['ip'] === self::$ip ? (int) $b['count'] : 0 ) + 1,
				),
				false
			);
			$throttle = 'uscn_blk_' . md5( self::$ip );
			if ( ! get_transient( $throttle ) ) {
				set_transient( $throttle, 1, HOUR_IN_SECONDS );
				USCN_Activity::log( 'security', 'request_blocked', self::$ip ? self::$ip : 'unknown address' );
				self::alert(
					'blocked',
					__( 'A dashboard request was blocked', 'unstoppable-site-connector' ),
					sprintf(
						/* translators: %s: IP address. */
						__( "A request signed with your dashboard's key came from %s, which is not on this site's list of allowed dashboard addresses, so it was refused.\n\nIf your dashboard moved to a new server, add the new address under Tools > Unstoppable Site Connector > Security.\nIf it did not, someone else may have your dashboard's key: click Disconnect on that screen, secure your dashboard, then connect again.", 'unstoppable-site-connector' ),
						self::$ip ? self::$ip : 'an unknown address'
					)
				);
			}
			return new WP_Error(
				'uscn_ip_blocked',
				sprintf(
					/* translators: %s: IP address. */
					__( 'This site only accepts dashboard requests from its allowed addresses, and this one came from %s. Add it on the client site under Tools > Unstoppable Site Connector > Security.', 'unstoppable-site-connector' ),
					self::$ip ? self::$ip : 'an unknown address'
				),
				array( 'status' => 403 )
			);
		}

		update_option(
			self::OPT_SEEN,
			array(
				'ip'   => self::$ip,
				'time' => time(),
			),
			false
		);
		return true;
	}

	/* ---------- permissions for dashboard jobs ---------- */

	/** @return true|WP_Error */
	public static function permit( $perm ) {
		if ( 'login' === $perm ? USCN_Login::settings()['enabled'] : self::allowed( $perm ) ) {
			return true;
		}
		$labels = self::perm_labels();
		$what   = 'login' === $perm ? __( 'One-click login', 'unstoppable-site-connector' ) : ( isset( $labels[ $perm ] ) ? $labels[ $perm ] : $perm );
		return new WP_Error(
			'uscn_not_permitted',
			/* translators: %s: name of the permission. */
			sprintf( __( '"%s" is switched off on this site. It can be switched back on in the site\'s wp-admin under Tools > Unstoppable Site Connector > Security.', 'unstoppable-site-connector' ), $what ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check a plugins job.
	 *
	 * @param array $args activate, overwrite (install options).
	 * @return true|WP_Error
	 */
	public static function permit_plugins( $op, array $items, array $args = array() ) {
		if ( 'delete' === $op ) {
			return self::permit( 'delete' );
		}
		if ( in_array( $op, array( 'activate', 'deactivate' ), true ) ) {
			return self::permit( 'toggle' );
		}
		if ( 'install' === $op ) {
			foreach ( $items as $item ) {
				$src = is_array( $item ) && isset( $item['source'] ) ? $item['source'] : '';
				$ok  = self::permit( 'wporg' === $src ? 'install_wporg' : 'install_zip' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			if ( ! empty( $args['activate'] ) ) {
				$ok = self::permit( 'toggle' ); // Installing and activating runs the plugin.
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			if ( ! empty( $args['overwrite'] ) ) {
				$ok = self::permit( 'update' ); // Replacing an installed plugin is an update.
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		}
		return true;
	}

	/* ---------- what the dashboard sees and may change ---------- */

	public static function summary() {
		$s    = self::settings();
		$seen = get_option( self::OPT_SEEN );
		$blk  = get_option( self::OPT_BLK );
		return array(
			'ip_lock'      => (bool) self::allowed_ips(),
			'ip_count'     => count( self::allowed_ips() ),
			'ip_config'    => self::ips_from_config(),
			'perms'        => array_merge( array( 'login' => USCN_Login::settings()['enabled'] ), $s['perms'] ),
			'alerts'       => array_keys( array_filter( $s['alerts'] ) ),
			'last_blocked' => is_array( $blk ) ? array( 'ip' => $blk['ip'], 'time' => (int) $blk['time'], 'count' => (int) $blk['count'] ) : null,
			'seen_ip'      => is_array( $seen ) ? $seen['ip'] : '',
		);
	}

	/**
	 * Signed route /security. The dashboard can read the settings and make them
	 * stricter; nothing here can loosen them.
	 */
	public static function rest( WP_REST_Request $request ) {
		$p       = $request->get_json_params();
		$p       = is_array( $p ) ? $p : array();
		$op      = isset( $p['op'] ) ? sanitize_key( $p['op'] ) : 'get';
		$changes = array();

		if ( 'lock_ip' === $op ) {
			if ( self::ips_from_config() ) {
				$changes = array(); // Set in wp-config.php; nothing to do.
			} elseif ( ! self::allowed_ips() && self::$ip ) {
				$s        = self::settings();
				$s['ips'] = array( self::$ip );
				self::save( $s );
				/* translators: %s: IP address. */
				$changes[] = sprintf( __( 'Only accept dashboard requests from %s', 'unstoppable-site-connector' ), self::$ip );
			}
		} elseif ( 'restrict' === $op ) {
			$want = isset( $p['perms'] ) && is_array( $p['perms'] ) ? array_map( 'sanitize_key', $p['perms'] ) : array();
			$s    = self::settings();
			foreach ( $want as $perm ) {
				if ( 'login' === $perm ) {
					$l = USCN_Login::settings();
					if ( $l['enabled'] ) {
						$l['enabled'] = false;
						update_option( USCN_Login::OPT, $l, false );
						$changes[] = __( 'Switched off: One-click login', 'unstoppable-site-connector' );
					}
				} elseif ( isset( $s['perms'][ $perm ] ) && $s['perms'][ $perm ] ) {
					$s['perms'][ $perm ] = false;
					/* translators: %s: name of the permission. */
					$changes[] = sprintf( __( 'Switched off: %s', 'unstoppable-site-connector' ), self::perm_labels()[ $perm ] );
				}
			}
			self::save( $s );
		} elseif ( 'get' !== $op ) {
			return new WP_Error( 'uscn_bad_op', 'Unknown security action.', array( 'status' => 400 ) );
		}

		if ( $changes ) {
			USCN_Activity::log( 'security', 'settings_tightened', implode( '; ', $changes ) );
			self::alert(
				'changed',
				__( 'Security settings were changed from the dashboard', 'unstoppable-site-connector' ),
				__( "The dashboard made this site's connector settings stricter:", 'unstoppable-site-connector' ) . "\n\n- " . implode( "\n- ", $changes ) . "\n\n" . __( 'These can only be loosened again in this site\'s wp-admin, under Tools > Unstoppable Site Connector > Security.', 'unstoppable-site-connector' )
			);
		}

		return rest_ensure_response(
			array(
				'changed'  => $changes,
				'security' => self::summary(),
			)
		);
	}

	/* ---------- alert emails ---------- */

	public static function alert_to() {
		$s  = self::settings();
		$to = $s['alert_email'] ? $s['alert_email'] : get_option( 'admin_email' );
		return is_email( $to ) ? $to : '';
	}

	/**
	 * @param string $event   Key from alert_labels().
	 * @param string $subject Short headline.
	 * @param string $body    Plain text.
	 */
	public static function alert( $event, $subject, $body ) {
		$s  = self::settings();
		$to = self::alert_to();
		if ( empty( $s['alerts'][ $event ] ) || ! $to ) {
			return false;
		}
		$conn  = USCN_Auth::connection();
		$lines = array( $body, '', '---' );
		/* translators: %s: site address. */
		$lines[] = sprintf( __( 'Site: %s', 'unstoppable-site-connector' ), home_url() );
		/* translators: %s: date and time. */
		$lines[] = sprintf( __( 'Time: %s', 'unstoppable-site-connector' ), wp_date( 'M j, Y g:i a T' ) );
		if ( ! empty( $conn['dashboard_name'] ) ) {
			/* translators: %s: dashboard name. */
			$lines[] = sprintf( __( 'Dashboard: %s', 'unstoppable-site-connector' ), $conn['dashboard_name'] );
		}
		if ( self::$by ) {
			/* translators: %s: username on the dashboard. */
			$lines[] = sprintf( __( 'Dashboard user: %s', 'unstoppable-site-connector' ), self::$by );
		}
		if ( self::$ip ) {
			/* translators: %s: IP address. */
			$lines[] = sprintf( __( 'Request from: %s', 'unstoppable-site-connector' ), self::$ip );
		}
		$lines[] = '';
		$lines[] = __( 'Change these alerts under Tools > Unstoppable Site Connector > Security:', 'unstoppable-site-connector' );
		$lines[] = admin_url( 'tools.php?page=' . USCN_Admin::SLUG . '#uscn-security' );

		return wp_mail(
			$to,
			/* translators: 1: site name, 2: alert headline. */
			sprintf( __( '[%1$s] Security alert: %2$s', 'unstoppable-site-connector' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject ),
			implode( "\n", $lines )
		);
	}

	/** Alert after a plugins job that installed or deleted something. */
	public static function after_plugins( $op, $result ) {
		if ( ! in_array( $op, array( 'install', 'delete' ), true ) || ! is_array( $result ) || empty( $result['results'] ) ) {
			return;
		}
		$done = array();
		$self = plugin_basename( USCN_FILE );
		foreach ( $result['results'] as $r ) {
			if ( isset( $r['id'] ) && $self === $r['id'] ) {
				continue; // The dashboard updating this connector is routine, not worth an alert.
			}
			if ( isset( $r['result'] ) && in_array( $r['result'], array( 'installed', 'deleted', 'ok', 'updated' ), true ) ) {
				$done[] = isset( $r['name'] ) && $r['name'] ? $r['name'] : ( isset( $r['id'] ) ? $r['id'] : '' );
			}
		}
		if ( ! $done ) {
			return;
		}
		self::alert(
			'plugins',
			'install' === $op ? __( 'Plugin installed from the dashboard', 'unstoppable-site-connector' ) : __( 'Plugin deleted from the dashboard', 'unstoppable-site-connector' ),
			( 'install' === $op ? __( 'The dashboard installed:', 'unstoppable-site-connector' ) : __( 'The dashboard deleted:', 'unstoppable-site-connector' ) ) . "\n\n- " . implode( "\n- ", array_filter( $done ) )
		);
	}
}
