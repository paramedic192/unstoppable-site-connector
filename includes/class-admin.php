<?php
/**
 * Tools > Unstoppable Site Connector: generate a connection key, see status, disconnect,
 * and set the white-label name clients see in their Plugins list.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Admin {

	const SLUG = 'unstoppable-site-connector';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_uscn_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_uscn_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_uscn_branding', array( __CLASS__, 'handle_branding' ) );
		add_action( 'admin_post_uscn_login_settings', array( __CLASS__, 'handle_login_settings' ) );
		add_action( 'admin_post_uscn_security', array( __CLASS__, 'handle_security' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'all_plugins', array( __CLASS__, 'white_label' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( USCN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'uscn-admin', plugins_url( 'assets/admin.css', USCN_FILE ), array(), USCN_VERSION );
		wp_enqueue_script( 'uscn-admin', plugins_url( 'assets/admin.js', USCN_FILE ), array(), USCN_VERSION, true );
		wp_localize_script(
			'uscn-admin',
			'USCN',
			array( 'confirmDisconnect' => __( 'Disconnect this site from the dashboard?', 'unstoppable-site-connector' ) )
		);
	}

	public static function menu() {
		add_management_page( self::brand_name(), self::brand_name(), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function brand_name() {
		$b = get_option( 'uscn_branding' );
		return ( is_array( $b ) && ! empty( $b['name'] ) ) ? $b['name'] : 'Unstoppable Site Connector';
	}

	public static function white_label( $plugins ) {
		$b    = get_option( 'uscn_branding' );
		$base = plugin_basename( USCN_FILE );
		if ( is_array( $b ) && isset( $plugins[ $base ] ) ) {
			foreach ( array( 'name' => 'Name', 'author' => 'Author', 'description' => 'Description' ) as $key => $field ) {
				if ( ! empty( $b[ $key ] ) ) {
					$plugins[ $base ][ $field ] = $b[ $key ];
				}
			}
			if ( ! empty( $b['name'] ) ) {
				$plugins[ $base ]['Title'] = $b['name'];
			}
		}
		return $plugins;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Settings</a>' );
		return $links;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg = '' ) {
		$url = admin_url( 'tools.php?page=' . self::SLUG );
		if ( $msg ) {
			$url = add_query_arg( 'uscn_msg', $msg, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_generate() {
		self::guard( 'uscn_generate' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( empty( $_POST['understand'] ) ) {
			self::back( 'need_consent' );
		}
		$key = USCN_Auth::connection_key( USCN_Auth::create_pairing_code() );
		set_transient( 'uscn_ckey_' . get_current_user_id(), $key, USCN_Auth::PAIR_TTL );
		self::back();
	}

	public static function handle_disconnect() {
		self::guard( 'uscn_disconnect' );
		USCN_Activity::log( 'connector', 'unpaired', home_url() );
		USCN_Auth::disconnect();
		self::back( 'disconnected' );
	}

	public static function handle_branding() {
		self::guard( 'uscn_branding' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked in guard().
		update_option(
			'uscn_branding',
			array(
				'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'author'      => isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( $_POST['author'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
			)
		);
		// phpcs:enable
		self::back( 'saved' );
	}

	public static function handle_login_settings() {
		self::guard( 'uscn_login_settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked in guard().
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			$user_id = 0;
		}
		update_option(
			USCN_Login::OPT,
			array(
				'enabled' => ! empty( $_POST['enabled'] ),
				'user_id' => $user_id,
			),
			false
		);
		// phpcs:enable
		self::back( 'login_saved' );
	}

	public static function handle_security() {
		self::guard( 'uscn_security' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked in guard().
		$s   = USCN_Security::settings();
		$raw = isset( $_POST['ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ips'] ) ) : '';
		if ( ! empty( $_POST['add_seen'] ) ) {
			$seen = get_option( USCN_Security::OPT_SEEN );
			if ( is_array( $seen ) && ! empty( $seen['ip'] ) ) {
				$raw .= "\n" . $seen['ip'];
			}
		}
		if ( ! empty( $_POST['add_blocked'] ) ) {
			$blk = get_option( USCN_Security::OPT_BLK );
			if ( is_array( $blk ) && ! empty( $blk['ip'] ) ) {
				$raw .= "\n" . $blk['ip'];
				delete_option( USCN_Security::OPT_BLK );
			}
		}
		if ( ! empty( $_POST['dismiss_blocked'] ) ) {
			delete_option( USCN_Security::OPT_BLK );
		}
		$ips = USCN_Security::parse_ips( $raw );
		if ( ! USCN_Security::ips_from_config() ) {
			$s['ips'] = $ips['ok'];
		}
		foreach ( array_keys( $s['perms'] ) as $perm ) {
			$s['perms'][ $perm ] = ! empty( $_POST['perms'][ $perm ] );
		}
		foreach ( array_keys( $s['alerts'] ) as $a ) {
			$s['alerts'][ $a ] = ! empty( $_POST['alerts'][ $a ] );
		}
		$email            = isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '';
		$s['alert_email'] = is_email( $email ) ? $email : '';
		// phpcs:enable
		USCN_Security::save( $s );
		if ( empty( $s['perms']['branding'] ) ) {
			USCN_Branding::clear(); // Switched off here: remove what the dashboard set.
		}
		USCN_Activity::log( 'security', 'settings_saved', home_url() );
		if ( $ips['bad'] ) {
			set_transient( 'uscn_bad_ips_' . get_current_user_id(), $ips['bad'], 120 );
		}
		self::back( 'security_saved' );
	}

	private static function render_security() {
		$sec     = USCN_Security::settings();
		$seen    = get_option( USCN_Security::OPT_SEEN );
		$blk     = get_option( USCN_Security::OPT_BLK );
		$bad     = get_transient( 'uscn_bad_ips_' . get_current_user_id() );
		$cfg     = USCN_Security::ips_from_config();
		$seen_ip = is_array( $seen ) && ! empty( $seen['ip'] ) ? $seen['ip'] : '';
		delete_transient( 'uscn_bad_ips_' . get_current_user_id() );
		?>
		<h2 class="uscn-section" id="uscn-security"><?php esc_html_e( 'Security', 'unstoppable-site-connector' ); ?></h2>
		<p><?php esc_html_e( 'These settings belong to this site. The dashboard can make them stricter, but only an administrator here can loosen them.', 'unstoppable-site-connector' ); ?></p>
		<p class="description"><?php esc_html_e( 'Strongest setup: allow only your dashboard\'s address, and switch off one-click login and installing from a ZIP file or web address unless you use them. Each of those two lets whoever controls the dashboard do anything on this site, including changing these settings.', 'unstoppable-site-connector' ); ?></p>

		<?php if ( is_array( $blk ) && ! empty( $blk['ip'] ) ) : ?>
			<div class="notice notice-error inline uscn-blocked">
				<p><strong><?php esc_html_e( 'A dashboard request was blocked.', 'unstoppable-site-connector' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: IP address, 2: time ago, 3: count. */
						_n( 'A request signed with your dashboard\'s key came from %1$s (%2$s ago, %3$d time), which is not an allowed address. If your dashboard moved to a new server, allow the new address. If not, someone else may have your dashboard\'s key: disconnect above, secure the dashboard, then connect again.', 'A request signed with your dashboard\'s key came from %1$s (%2$s ago, %3$d times), which is not an allowed address. If your dashboard moved to a new server, allow the new address. If not, someone else may have your dashboard\'s key: disconnect above, secure the dashboard, then connect again.', (int) $blk['count'], 'unstoppable-site-connector' ),
						$blk['ip'],
						human_time_diff( (int) $blk['time'] ),
						(int) $blk['count']
					)
				);
				?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $bad ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: list of entries. */
				echo esc_html( sprintf( __( 'These entries are not valid addresses and were left out: %s', 'unstoppable-site-connector' ), implode( ', ', (array) $bad ) ) );
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="uscn_security">
			<?php wp_nonce_field( 'uscn_security' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="uscn-ips"><?php esc_html_e( 'Allowed dashboard addresses', 'unstoppable-site-connector' ); ?></label></th>
					<td>
						<?php if ( $cfg ) : ?>
							<p><code><?php echo esc_html( implode( ', ', USCN_Security::allowed_ips() ) ); ?></code></p>
							<p class="description"><?php esc_html_e( 'Set in wp-config.php (USCN_ALLOWED_IPS), so it can\'t be changed here.', 'unstoppable-site-connector' ); ?></p>
						<?php else : ?>
							<textarea id="uscn-ips" name="ips" rows="3" class="regular-text code" placeholder="203.0.113.10"><?php echo esc_textarea( implode( "\n", $sec['ips'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Only accept dashboard requests from these IP addresses (one per line; ranges like 203.0.113.0/24 work). Leave empty to accept any address. A request with a valid signature from any other address is refused, so a stolen dashboard key is useless elsewhere.', 'unstoppable-site-connector' ); ?></p>
						<?php endif; ?>
						<?php if ( $seen_ip ) : ?>
							<p class="uscn-seen">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: IP address, 2: time ago. */
										__( 'Your dashboard\'s requests come from %1$s (last seen %2$s ago).', 'unstoppable-site-connector' ),
										$seen_ip,
										human_time_diff( (int) $seen['time'] )
									)
								);
								?>
								<?php if ( ! $cfg && ( ! $sec['ips'] || ! USCN_Security::is_ip_allowed( $seen_ip ) ) ) : ?>
									<button type="submit" name="add_seen" value="1" class="button button-small"><?php echo $sec['ips'] ? esc_html__( 'Also allow this address', 'unstoppable-site-connector' ) : esc_html__( 'Allow only this address', 'unstoppable-site-connector' ); ?></button>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<?php if ( ! $cfg && is_array( $blk ) && ! empty( $blk['ip'] ) ) : ?>
							<p>
								<button type="submit" name="add_blocked" value="1" class="button button-small">
									<?php
									/* translators: %s: IP address. */
									echo esc_html( sprintf( __( 'Allow %s (my dashboard moved)', 'unstoppable-site-connector' ), $blk['ip'] ) );
									?>
								</button>
								<button type="submit" name="dismiss_blocked" value="1" class="button-link"><?php esc_html_e( 'Dismiss the warning', 'unstoppable-site-connector' ); ?></button>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'The dashboard may', 'unstoppable-site-connector' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( USCN_Security::perm_labels() as $perm => $label ) : ?>
								<label class="uscn-check"><input type="checkbox" name="perms[<?php echo esc_attr( $perm ); ?>]" value="1" <?php checked( $sec['perms'][ $perm ] ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Checking for updates, health and the activity log always works. One-click login is set above.', 'unstoppable-site-connector' ); ?></p>
						<?php $brand = USCN_Branding::get(); ?>
						<?php if ( '' !== $brand['text'] ) : ?>
							<p class="description">
								<?php
								/* translators: %s: footer text. */
								echo esc_html( sprintf( __( 'Admin footer branding set by the dashboard: "%s". It only shows in wp-admin. Switch off "Set the admin footer branding" to remove it.', 'unstoppable-site-connector' ), $brand['text'] ) );
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="uscn-alert-email"><?php esc_html_e( 'Security alerts', 'unstoppable-site-connector' ); ?></label></th>
					<td>
						<input type="email" id="uscn-alert-email" name="alert_email" class="regular-text" value="<?php echo esc_attr( $sec['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Where alerts go. Leave empty to use this site\'s admin email.', 'unstoppable-site-connector' ); ?></p>
						<fieldset class="uscn-alerts">
							<legend><?php esc_html_e( 'Email when:', 'unstoppable-site-connector' ); ?></legend>
							<?php foreach ( USCN_Security::alert_labels() as $a => $label ) : ?>
								<label class="uscn-check"><input type="checkbox" name="alerts[<?php echo esc_attr( $a ); ?>]" value="1" <?php checked( $sec['alerts'][ $a ] ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save security settings', 'unstoppable-site-connector' ), 'primary' ); ?>
		</form>
		<?php
	}

	public static function render() {
		$conn  = USCN_Auth::connection();
		$key   = get_transient( 'uscn_ckey_' . get_current_user_id() );
		$push  = get_option( 'uscn_last_push' );
		$brand = get_option( 'uscn_branding' );
		$brand = is_array( $brand ) ? $brand : array();
		$fs    = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'direct';
		$msg   = isset( $_GET['uscn_msg'] ) ? sanitize_key( $_GET['uscn_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1><?php echo esc_html( self::brand_name() ); ?></h1>

			<?php if ( 'disconnected' === $msg ) : ?>
				<div class="notice notice-success"><p>Disconnected from the dashboard.</p></div>
			<?php elseif ( 'saved' === $msg ) : ?>
				<div class="notice notice-success"><p>Branding saved.</p></div>
			<?php elseif ( 'need_consent' === $msg ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Tick the box to confirm you understand what connecting allows, then generate the key.', 'unstoppable-site-connector' ); ?></p></div>
			<?php elseif ( 'security_saved' === $msg ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Security settings saved.', 'unstoppable-site-connector' ); ?></p></div>
			<?php elseif ( 'login_saved' === $msg ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'One-click login settings saved.', 'unstoppable-site-connector' ); ?></p></div>
			<?php endif; ?>

			<?php
			$enroll = USCN_Enroll::state();
			$econf  = USCN_Enroll::config();
			?>
			<?php if ( ! empty( $enroll['done'] ) && USCN_Auth::is_paired() ) : ?>
				<div class="notice notice-info"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: dashboard name, 2: date. */
							__( 'This site was connected automatically to the dashboard "%1$s" on %2$s, using a bulk-connect copy of this plugin installed by its administrator. That dashboard can now manage this site; limit what it may do under Security below, or click Disconnect.', 'unstoppable-site-connector' ),
							$enroll['dashboard'],
							wp_date( get_option( 'date_format' ), (int) $enroll['time'] )
						)
					);
					?>
				</p></div>
			<?php elseif ( $econf && ! USCN_Auth::is_paired() ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: dashboard name. */
							__( 'This copy of the plugin was made to connect itself to the dashboard "%s".', 'unstoppable-site-connector' ),
							isset( $econf['dashboard'] ) ? $econf['dashboard'] : ''
						)
					);
					echo ' ';
					if ( ! empty( $enroll['error'] ) ) {
						/* translators: %s: error message. */
						echo esc_html( sprintf( __( 'It has not managed to yet: %s', 'unstoppable-site-connector' ), $enroll['error'] ) );
					} else {
						esc_html_e( 'It will do so in the background within a few minutes.', 'unstoppable-site-connector' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( USCN_Migrate::legacy_left() && current_user_can( 'delete_plugins' ) ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'This plugin has taken over FM Connector\'s connection and settings. FM Connector is no longer needed: deactivate and delete it.', 'unstoppable-site-connector' ); ?>
					<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Go to Plugins', 'unstoppable-site-connector' ); ?></a>
				</p></div>
			<?php endif; ?>

			<?php if ( 'direct' !== $fs ) : ?>
				<div class="notice notice-warning"><p>This server needs FTP credentials to install updates, so remote updates won't work until the host allows direct file writes.</p></div>
			<?php endif; ?>

			<h2>Connection</h2>
			<?php if ( USCN_Auth::is_paired() ) : ?>
				<table class="form-table" role="presentation">
					<tr><th>Status</th><td><strong class="uscn-ok">Connected</strong> to <?php echo esc_html( $conn['dashboard_name'] ); ?></td></tr>
					<tr><th>Connected since</th><td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $conn['paired_at'] ) ); ?></td></tr>
					<tr><th>Site ID</th><td><code><?php echo esc_html( $conn['site_id'] ); ?></code></td></tr>
					<tr><th>Last report sent</th><td>
						<?php
						if ( is_array( $push ) && ! empty( $push['time'] ) ) {
							echo esc_html( human_time_diff( (int) $push['time'] ) . ' ago — ' . ( ! empty( $push['ok'] ) ? 'OK' : 'failed: ' . ( isset( $push['error'] ) ? $push['error'] : 'HTTP ' . $push['code'] ) ) );
						} else {
							echo empty( $conn['push_url'] ) ? 'Dashboard pulls data (no push URL set).' : 'Not yet.';
						}
						?>
					</td></tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uscn-disconnect">
					<input type="hidden" name="action" value="uscn_disconnect">
					<?php wp_nonce_field( 'uscn_disconnect' ); ?>
					<?php submit_button( 'Disconnect', 'delete', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>This site is not connected. Generate a connection key and paste it into your dashboard. Keys work once and expire in 15 minutes.</p>
			<?php endif; ?>

			<?php if ( $key ) : ?>
				<h3>Connection key</h3>
				<textarea readonly rows="4" class="large-text code uscn-select"><?php echo esc_textarea( $key ); ?></textarea>
				<p class="description">Paste this into the dashboard's "Add site" box. Treat it like a password until it's used.</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uscn_generate">
				<?php wp_nonce_field( 'uscn_generate' ); ?>
				<div class="uscn-consent">
					<p><strong><?php esc_html_e( 'What connecting allows', 'unstoppable-site-connector' ); ?></strong></p>
					<p><?php esc_html_e( 'The dashboard you connect to (run by you or your web professional on their own WordPress site, not by Unstoppable Solutions) will be able to manage this site remotely: run updates, install, activate, deactivate and delete plugins, and open this admin area signed in as an administrator. You can limit each of these under Security below, and disconnect at any time.', 'unstoppable-site-connector' ); ?></p>
					<label><input type="checkbox" name="understand" value="1" required> <?php esc_html_e( 'I understand, and I trust the person who runs that dashboard to manage this site.', 'unstoppable-site-connector' ); ?></label>
				</div>
				<?php submit_button( USCN_Auth::is_paired() ? 'Generate key to reconnect' : 'Generate connection key', 'primary', 'submit', false ); ?>
			</form>


			<h2 class="uscn-section"><?php esc_html_e( 'One-click login', 'unstoppable-site-connector' ); ?></h2>
			<?php
			$login  = USCN_Login::settings();
			$admins = USCN_Login::admins();
			$as     = USCN_Login::login_user();
			?>
			<p><?php esc_html_e( 'Lets the connected dashboard open this site\'s admin area already signed in, without a password. Each login link works once and expires after 60 seconds. Logins are recorded in the activity log.', 'unstoppable-site-connector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uscn_login_settings">
				<?php wp_nonce_field( 'uscn_login_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Allow one-click login', 'unstoppable-site-connector' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $login['enabled'] ); ?>> <?php esc_html_e( 'Allow the dashboard to sign in', 'unstoppable-site-connector' ); ?></label></td></tr>
					<tr><th><label for="uscn-login-user"><?php esc_html_e( 'Sign in as', 'unstoppable-site-connector' ); ?></label></th><td>
						<select id="uscn-login-user" name="user_id">
							<option value="0" <?php selected( 0, $login['user_id'] ); ?>><?php esc_html_e( 'First administrator', 'unstoppable-site-connector' ); ?></option>
							<?php foreach ( $admins as $a ) : ?>
								<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $a->ID, $login['user_id'] ); ?>><?php echo esc_html( $a->display_name . ' (' . $a->user_login . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( $as ) : ?>
							<p class="description">
								<?php
								/* translators: %s: username. */
								echo esc_html( sprintf( __( 'The dashboard currently signs in as %s.', 'unstoppable-site-connector' ), $as->user_login ) );
								?>
							</p>
						<?php endif; ?>
					</td></tr>
				</table>
				<?php submit_button( __( 'Save login settings', 'unstoppable-site-connector' ), 'secondary' ); ?>
			</form>

			<?php self::render_security(); ?>

			<h2 class="uscn-section">White label</h2>
			<p>Change how this plugin appears to your client in their Plugins list.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uscn_branding">
				<?php wp_nonce_field( 'uscn_branding' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="uscn-name">Plugin name</label></th><td><input id="uscn-name" name="name" class="regular-text" value="<?php echo esc_attr( isset( $brand['name'] ) ? $brand['name'] : '' ); ?>" placeholder="Unstoppable Site Connector"></td></tr>
					<tr><th><label for="uscn-author">Author</label></th><td><input id="uscn-author" name="author" class="regular-text" value="<?php echo esc_attr( isset( $brand['author'] ) ? $brand['author'] : '' ); ?>" placeholder="Unstoppable Solutions"></td></tr>
					<tr><th><label for="uscn-desc">Description</label></th><td><input id="uscn-desc" name="description" class="large-text" value="<?php echo esc_attr( isset( $brand['description'] ) ? $brand['description'] : '' ); ?>"></td></tr>
				</table>
				<?php submit_button( 'Save branding', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
