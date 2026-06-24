<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Settings;

use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Reporting\Auth\ServiceAccountAuth;
use OrchardGrove\PrecisionAnalytics\Reporting\Reports;
use OrchardGrove\PrecisionAnalytics\Reporting\Sync;
use OrchardGrove\PrecisionAnalytics\Tracking\Dimensions;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings page. The whole option is saved through options.php with a
 * single sanitize callback that merges each submitted tab over stored values,
 * so other tabs are never clobbered.
 */
final class SettingsPage implements ModuleInterface {

	private const GROUP = 'precision_analytics_group';
	private const PAGE  = 'precision-analytics';

	/** @var array<string,string> */
	private array $tabs;

	public function __construct( private Options $options ) {
		$this->tabs = [
			'general'    => __( 'General', 'precision-analytics' ),
			'attributes' => __( 'Attributes', 'precision-analytics' ),
			'sampling'   => __( 'Sampling', 'precision-analytics' ),
			'consent'    => __( 'Consent', 'precision-analytics' ),
			'reporting'  => __( 'Reporting', 'precision-analytics' ),
			'advanced'   => __( 'Advanced', 'precision-analytics' ),
		];
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSetting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_precision_analytics_sync_now', [ $this, 'handleSyncNow' ] );
		add_action( 'admin_post_precision_analytics_test', [ $this, 'handleTest' ] );
		add_filter( 'plugin_action_links_' . PRECISION_ANALYTICS_BASENAME, [ $this, 'actionLinks' ] );
	}

	public function addMenu(): void {
		add_menu_page(
			__( 'Precision Analytics', 'precision-analytics' ),
			__( 'Precision Analytics', 'precision-analytics' ),
			'manage_options',
			self::PAGE,
			[ $this, 'renderPage' ],
			'dashicons-chart-area',
			80
		);
	}

	public function registerSetting(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'precision-analytics-admin', PRECISION_ANALYTICS_URL . 'assets/admin/admin.css', [], PRECISION_ANALYTICS_VERSION );
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function actionLinks( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'precision-analytics' ) . '</a>' );
		return $links;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab is display-only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $this->tabs[ $current ] ) ) {
			$current = 'general';
		}

		$icons = [
			'general'    => 'admin-settings',
			'attributes' => 'tag',
			'sampling'   => 'filter',
			'consent'    => 'privacy',
			'reporting'  => 'chart-bar',
			'advanced'   => 'admin-generic',
		];
		?>
		<div class="wrap pa-settings">
			<div class="pa-header">
				<div class="pa-brand">
					<span class="pa-mark" aria-hidden="true"><span class="dashicons dashicons-chart-area"></span></span>
					<div class="pa-brand-text">
						<h1><?php esc_html_e( 'Precision Analytics', 'precision-analytics' ); ?></h1>
						<p class="pa-tagline"><?php esc_html_e( 'Precise Google Analytics 4 — by Orchard Grove Media', 'precision-analytics' ); ?></p>
					</div>
				</div>
				<span class="pa-version">v<?php echo esc_html( PRECISION_ANALYTICS_VERSION ); ?></span>
			</div>
			<hr class="wp-header-end" />

			<?php $this->maybeNotice(); ?>

			<nav class="pa-tabs" aria-label="<?php esc_attr_e( 'Precision Analytics settings', 'precision-analytics' ); ?>">
				<?php foreach ( $this->tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
						class="pa-tab <?php echo $slug === $current ? 'is-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $slug ] ?? 'admin-generic' ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="pa-layout">
				<div class="pa-main">
					<div class="pa-card">
						<?php $this->tabIntro( $current ); ?>
						<form method="post" action="options.php">
							<?php
							settings_fields( self::GROUP );
							echo '<table class="form-table" role="presentation">';
							$this->renderTab( $current );
							echo '</table>';
							submit_button();
							?>
						</form>
						<?php
						if ( 'reporting' === $current ) {
							$this->renderReportingActions();
						}
						?>
					</div>
				</div>
				<aside class="pa-sidebar">
					<?php $this->renderSidebar(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	private function tabIntro( string $tab ): void {
		$intros = [
			'general'    => __( 'Your GA4 Measurement ID and how the tag is delivered — directly via gtag.js, or pushed to the GTM dataLayer.', 'precision-analytics' ),
			'attributes' => __( 'Choose which post and visitor facts to send as GA4 custom dimensions. Register the listed parameter names in GA4 to report on them.', 'precision-analytics' ),
			'sampling'   => __( 'Track a percentage of visitors, override the rate for specific segments, and exclude staff. Unrelated to GA4’s own report sampling.', 'precision-analytics' ),
			'consent'    => __( 'Google Consent Mode v2 — a GDPR feature for EEA/UK/Swiss visitors. US sites should leave it off (the default) so GA4 collects from everyone.', 'precision-analytics' ),
			'reporting'  => __( 'Read data back into WordPress on a schedule with your own Google credentials, for the dashboard widget, [pa_popular_posts], and the block.', 'precision-analytics' ),
			'advanced'   => __( 'Optional client-side events and uninstall behavior.', 'precision-analytics' ),
		];
		if ( isset( $intros[ $tab ] ) ) {
			echo '<p class="pa-intro">' . esc_html( $intros[ $tab ] ) . '</p>';
		}
	}

	private function renderSidebar(): void {
		$reports    = new Reports( $this->options );
		$configured = $reports->isConfigured();
		$last       = $reports->lastSync();
		?>
		<div class="pa-card pa-aside">
			<h2><?php esc_html_e( 'Status', 'precision-analytics' ); ?></h2>
			<ul class="pa-status">
				<li>
					<span class="dashicons dashicons-<?php echo '' !== trim( $this->trackingId() ) ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
					<?php echo '' !== trim( $this->trackingId() ) ? esc_html__( 'Tracking active', 'precision-analytics' ) : esc_html__( 'No tracking ID set', 'precision-analytics' ); ?>
				</li>
				<li>
					<span class="dashicons dashicons-<?php echo $configured ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
					<?php echo $configured ? esc_html__( 'Reporting connected', 'precision-analytics' ) : esc_html__( 'Reporting not connected', 'precision-analytics' ); ?>
				</li>
				<?php if ( $last > 0 ) : ?>
					<li>
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php
						/* translators: %s: human-readable time difference. */
						printf( esc_html__( 'Synced %s ago', 'precision-analytics' ), esc_html( human_time_diff( $last ) ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
			<p class="pa-aside-foot">
				<?php
				/* translators: %s: version number. */
				printf( esc_html__( 'Precision Analytics v%s', 'precision-analytics' ), esc_html( PRECISION_ANALYTICS_VERSION ) );
				?><br />
				<?php esc_html_e( 'by Orchard Grove Media, LLC', 'precision-analytics' ); ?>
			</p>
		</div>
		<?php
	}

	private function renderTab( string $tab ): void {
		switch ( $tab ) {
			case 'general':
				$this->inputRow( __( 'GA4 Measurement ID', 'precision-analytics' ), 'general.measurement_id', 'text', 'G-XXXXXXXXXX' );
				$this->selectRow( __( 'Transport', 'precision-analytics' ), 'general.transport', [
					'gtag' => __( 'gtag.js (direct)', 'precision-analytics' ),
					'gtm'  => __( 'Google Tag Manager (dataLayer)', 'precision-analytics' ),
				] );
				$this->inputRow( __( 'GTM container ID', 'precision-analytics' ), 'general.gtm_id', 'text', 'GTM-XXXXXXX', __( 'Used only when Transport is Google Tag Manager.', 'precision-analytics' ) );
				$this->checkboxRow( __( 'Debug mode', 'precision-analytics' ), 'general.debug_mode', __( 'Send debug_mode so hits show in GA4 DebugView', 'precision-analytics' ) );
				break;

			case 'attributes':
				foreach ( Dimensions::REGISTRY as $key => $meta ) {
					/* translators: 1: GA4 parameter name, 2: dimension scope (event or user). */
					$help = sprintf( __( 'Send as %1$s (%2$s-scoped)', 'precision-analytics' ), $meta['param'], $meta['scope'] );
					$this->checkboxRow( $meta['label'], "attributes.$key", $help );
				}
				$this->registerHelper();
				break;

			case 'sampling':
				$this->checkboxRow( __( 'Enable sampling', 'precision-analytics' ), 'sampling.enabled', __( 'Off = track every visitor. Exclusions below always apply.', 'precision-analytics' ) );
				$this->inputRow( __( 'Global send-rate (%)', 'precision-analytics' ), 'sampling.rate', 'number', '100', __( 'Percentage of visitors that send data when sampling is on.', 'precision-analytics' ) );
				$this->help( __( 'Exclusions (always applied)', 'precision-analytics' ) );
				$this->checkboxRow( __( 'Logged-in users', 'precision-analytics' ), 'sampling.exclude_logged_in', __( 'Never track logged-in users', 'precision-analytics' ) );
				$this->rolesRow();
				$this->inputRow( __( 'Exclude user IDs', 'precision-analytics' ), 'sampling.exclude_user_ids', 'text', '1, 5, 12', __( 'Comma-separated user IDs to never track.', 'precision-analytics' ) );
				$this->help( __( 'Per-segment overrides', 'precision-analytics' ) );
				$this->textareaRow(
					__( 'Rules', 'precision-analytics' ),
					'sampling.rules',
					__( 'One per line: attribute:value:rate. Attributes: post_type, post_id, page_type, author, category, tag, logged_in, user_role. Example: post_type:product:100', 'precision-analytics' ),
					5
				);
				break;

			case 'consent':
				echo '<tr><td colspan="2"><div class="pa-inline-notice pa-callout"><strong>' . esc_html__( 'US sites: leave this OFF.', 'precision-analytics' ) . '</strong> ' . esc_html__( 'Consent Mode is a GDPR feature for EEA/UK/Swiss visitors and only helps alongside a cookie-consent banner. With it off, GA4 collects from everyone — the right setup for a US audience, and the default. Turn it on only if you serve EEA visitors and have a banner that grants consent.', 'precision-analytics' ) . '</div></td></tr>';
					$this->checkboxRow( __( 'Enable Consent Mode v2', 'precision-analytics' ), 'consent.enabled', __( 'Leave OFF for a US audience. When ON, analytics/cookies are withheld until consent is granted — for EEA/UK/CH visitors only, as long as “EEA only” below stays checked.', 'precision-analytics' ) );
					$this->help( __( 'The settings below take effect only when Consent Mode is enabled.', 'precision-analytics' ) );
				$this->selectRow( __( 'Analytics storage default', 'precision-analytics' ), 'consent.analytics_default', $this->grantedDeniedChoices() );
				$this->selectRow( __( 'Ads storage default', 'precision-analytics' ), 'consent.ad_default', $this->grantedDeniedChoices() );
				$this->checkboxRow( __( 'EEA only (recommended)', 'precision-analytics' ), 'consent.eea_only', __( 'Keep this checked. Grants consent worldwide (including the US) and applies the denied defaults to EEA/UK/CH visitors only. Unchecking it denies everyone — including your US audience — until a banner grants consent.', 'precision-analytics' ) );
				$this->checkboxRow( __( 'URL passthrough', 'precision-analytics' ), 'consent.url_passthrough', __( 'Preserve ad-click info across pages while consent is denied. Harmless to leave on.', 'precision-analytics' ) );
				$this->inputRow( __( 'Wait for update (ms)', 'precision-analytics' ), 'consent.wait_for_update', 'number', '500', __( 'How long the tag waits for a consent decision before proceeding. 500 is typical.', 'precision-analytics' ) );
				echo '<tr><td colspan="2"><div class="pa-inline-notice">'
					. esc_html__( 'Only relevant when Consent Mode is on: your cookie banner must call window.precisionAnalytics.updateConsent({analytics_storage:"granted", ad_storage:"granted"}) after the visitor accepts — otherwise EEA visitors are never counted.', 'precision-analytics' )
					. '</div></td></tr>';
				break;

			case 'reporting':
				$this->checkboxRow( __( 'Enable reporting', 'precision-analytics' ), 'reporting.enabled', __( 'Fetch GA4 data into WordPress on a schedule', 'precision-analytics' ) );
				$this->selectRow( __( 'Authentication', 'precision-analytics' ), 'reporting.auth_method', [
					'service_account' => __( 'Service account (JSON key)', 'precision-analytics' ),
					'oauth'           => __( 'OAuth (coming soon)', 'precision-analytics' ),
				] );
				$this->inputRow( __( 'GA4 property ID', 'precision-analytics' ), 'reporting.property_id', 'text', '123456789', __( 'The numeric Property ID (Admin → Property settings), not the G- Measurement ID.', 'precision-analytics' ) );
				$this->serviceAccountRow();
				$this->inputRow( __( 'Sync interval (seconds)', 'precision-analytics' ), 'reporting.sync_interval', 'number', '900', __( 'Minimum 300. Lower = fresher data, more API calls.', 'precision-analytics' ) );
				$this->checkboxRow( __( 'Dashboard widget', 'precision-analytics' ), 'reporting.widget_enabled', __( 'Show the summary widget on the WordPress dashboard', 'precision-analytics' ) );
				break;

			case 'advanced':
				$this->help( __( 'Client-side events (optional)', 'precision-analytics' ) );
				echo '<tr><td colspan="2"><div class="pa-inline-notice">'
					. esc_html__( 'GA4 Enhanced Measurement may already track these automatically. Enable here only if you have turned that off, to avoid double counting.', 'precision-analytics' )
					. '</div></td></tr>';
				$this->checkboxRow( __( 'Enable events', 'precision-analytics' ), 'events.enabled', __( 'Send the events below via gtag (gtag transport only)', 'precision-analytics' ) );
				$this->checkboxRow( __( 'Outbound links', 'precision-analytics' ), 'events.outbound_links', __( 'Track clicks to external domains', 'precision-analytics' ) );
				$this->checkboxRow( __( 'File downloads', 'precision-analytics' ), 'events.file_downloads', __( 'Track downloads of the extensions below', 'precision-analytics' ) );
				$this->inputRow( __( 'Download extensions', 'precision-analytics' ), 'events.download_exts', 'text', 'pdf,doc,zip' );
				$this->help( __( 'Data', 'precision-analytics' ) );
				$this->checkboxRow( __( 'Uninstall', 'precision-analytics' ), 'advanced.delete_data_on_uninstall', __( 'Delete all plugin data when the plugin is deleted', 'precision-analytics' ) );
				break;
		}
	}

	private function registerHelper(): void {
		echo '<tr><td colspan="2"><div class="pa-register-helper">';
		echo '<p class="description"><strong>' . esc_html__( 'Register these in GA4', 'precision-analytics' ) . '</strong> — '
			. esc_html__( 'Admin → Custom definitions → Create custom dimension, using the parameter name and scope below.', 'precision-analytics' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attribute', 'precision-analytics' ) . '</th><th>'
			. esc_html__( 'Parameter name', 'precision-analytics' ) . '</th><th>' . esc_html__( 'Scope', 'precision-analytics' ) . '</th></tr></thead><tbody>';
		foreach ( Dimensions::REGISTRY as $key => $meta ) {
			if ( ! $this->options->bool( "attributes.$key" ) ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $meta['label'] ) . '</td><td><code>' . esc_html( $meta['param'] ) . '</code></td><td>' . esc_html( $meta['scope'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></td></tr>';
	}

	private function rolesRow(): void {
		$selected = $this->options->arr( 'sampling.exclude_roles' );
		$name     = Options::OPTION . '[sampling][exclude_roles][]';
		$roles    = function_exists( 'get_editable_roles' ) ? get_editable_roles() : [];

		echo '<tr><th scope="row">' . esc_html__( 'Exclude roles', 'precision-analytics' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="__none__" />';
		echo '<div class="pa-roles">';
		foreach ( $roles as $slug => $role ) {
			$id = 'pa_role_' . $slug;
			echo '<label for="' . esc_attr( $id ) . '" class="pa-role">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $slug ) . '" '
				. checked( in_array( $slug, $selected, true ), true, false ) . ' /> ';
			echo esc_html( translate_user_role( $role['name'] ?? $slug ) );
			echo '</label>';
		}
		echo '</div></td></tr>';
	}

	private function serviceAccountRow(): void {
		$constant = defined( 'PA_GA4_SERVICE_ACCOUNT_JSON' );
		echo '<tr><th scope="row"><label for="pa_reporting_service_account_json">' . esc_html__( 'Service account JSON', 'precision-analytics' ) . '</label></th><td>';
		if ( $constant ) {
			echo '<p class="description">' . esc_html__( 'Loaded from the PA_GA4_SERVICE_ACCOUNT_JSON constant in wp-config.php.', 'precision-analytics' ) . '</p>';
		} else {
			$has = '' !== trim( $this->options->str( 'reporting.service_account_json' ) );
			echo '<textarea id="pa_reporting_service_account_json" name="' . esc_attr( Options::OPTION ) . '[reporting][service_account_json]" rows="4" class="large-text code" placeholder=\'{ "type": "service_account", ... }\'>'
				. esc_textarea( $this->options->str( 'reporting.service_account_json' ) ) . '</textarea>';
			echo '<p class="description">'
				. esc_html__( 'Paste the JSON key, then add the service-account email as a Viewer on your GA4 property. For better security, define PA_GA4_SERVICE_ACCOUNT_JSON in wp-config.php instead.', 'precision-analytics' )
				. ( $has ? ' <strong>' . esc_html__( 'A key is currently saved.', 'precision-analytics' ) . '</strong>' : '' )
				. '</p>';
		}
		echo '</td></tr>';
	}

	private function renderReportingActions(): void {
		$post = admin_url( 'admin-post.php' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Actions', 'precision-analytics' ); ?></h2>
		<p>
			<form method="post" action="<?php echo esc_url( $post ); ?>" style="display:inline">
				<?php wp_nonce_field( 'precision_analytics_test' ); ?>
				<input type="hidden" name="action" value="precision_analytics_test" />
				<?php submit_button( __( 'Test connection', 'precision-analytics' ), 'secondary', 'submit', false ); ?>
			</form>
			&nbsp;
			<form method="post" action="<?php echo esc_url( $post ); ?>" style="display:inline">
				<?php wp_nonce_field( 'precision_analytics_sync_now' ); ?>
				<input type="hidden" name="action" value="precision_analytics_sync_now" />
				<?php submit_button( __( 'Sync now', 'precision-analytics' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>
		<p class="description"><?php esc_html_e( 'Test verifies your credentials. Sync now refreshes the cached reports immediately.', 'precision-analytics' ); ?></p>
		<?php
	}

	// --- Save -------------------------------------------------------------

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$current = get_option( Options::OPTION, [] );
		$current = is_array( $current ) ? $current : [];
		$input   = is_array( $input ) ? $input : [];

		$clean = [];
		foreach ( $this->fieldSchema() as $path => $type ) {
			if ( ! $this->hasNested( $input, $path ) ) {
				continue;
			}
			$this->setNested( $clean, $path, $this->sanitizeValue( $this->getNested( $input, $path ), $type ) );
		}

		// Exclude-roles checkbox group — validate against real roles.
		if ( isset( $input['sampling']['exclude_roles'] ) && is_array( $input['sampling']['exclude_roles'] ) ) {
			$valid  = array_keys( function_exists( 'get_editable_roles' ) ? get_editable_roles() : [] );
			$picked = array_map( 'sanitize_key', $input['sampling']['exclude_roles'] );
			$this->setNested( $clean, 'sampling.exclude_roles', array_values( array_intersect( $picked, $valid ) ) );
		}

		$merged = $this->mergeDeep( $current, $clean );

		// React to credential / interval changes.
		$key_changed = ( $current['reporting']['service_account_json'] ?? '' ) !== ( $merged['reporting']['service_account_json'] ?? '' );
		if ( $key_changed ) {
			ServiceAccountAuth::forgetToken();
		}
		if ( ( $current['reporting']['sync_interval'] ?? 0 ) !== ( $merged['reporting']['sync_interval'] ?? 0 ) ) {
			Sync::reschedule();
		}

		return $merged;
	}

	/** @return array<string,string> dotted path => type spec. */
	private function fieldSchema(): array {
		$schema = [
			'general.measurement_id'           => 'text',
			'general.transport'                => 'enum:gtag,gtm',
			'general.gtm_id'                   => 'text',
			'general.debug_mode'               => 'bool',
			'sampling.enabled'                 => 'bool',
			'sampling.rate'                    => 'intrange:0:100',
			'sampling.exclude_logged_in'       => 'bool',
			'sampling.exclude_user_ids'        => 'text',
			'sampling.rules'                   => 'textarea',
			'consent.enabled'                  => 'bool',
			'consent.analytics_default'        => 'enum:granted,denied',
			'consent.ad_default'               => 'enum:granted,denied',
			'consent.eea_only'                 => 'bool',
			'consent.url_passthrough'          => 'bool',
			'consent.wait_for_update'          => 'intrange:0:10000',
			'reporting.enabled'                => 'bool',
			'reporting.property_id'            => 'text',
			'reporting.auth_method'            => 'enum:service_account,oauth',
			'reporting.service_account_json'   => 'json',
			'reporting.sync_interval'          => 'intrange:300:86400',
			'reporting.widget_enabled'         => 'bool',
			'events.enabled'                   => 'bool',
			'events.outbound_links'            => 'bool',
			'events.file_downloads'            => 'bool',
			'events.download_exts'             => 'text',
			'advanced.delete_data_on_uninstall' => 'bool',
		];

		foreach ( array_keys( Dimensions::REGISTRY ) as $key ) {
			$schema[ "attributes.$key" ] = 'bool';
		}

		return $schema;
	}

	/** @param mixed $raw */
	private function sanitizeValue( $raw, string $type ): mixed {
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			$value   = is_string( $raw ) ? $raw : '';
			return in_array( $value, $allowed, true ) ? $value : $allowed[0];
		}
		if ( str_starts_with( $type, 'intrange:' ) ) {
			[ , $min, $max ] = explode( ':', $type );
			return max( (int) $min, min( (int) $max, (int) $raw ) );
		}
		return match ( $type ) {
			'textarea' => sanitize_textarea_field( (string) $raw ),
			'json'     => $this->sanitizeJson( (string) $raw ),
			'bool'     => (bool) $raw,
			'int'      => (int) $raw,
			default    => sanitize_text_field( (string) $raw ),
		};
	}

	private function sanitizeJson( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$decoded = json_decode( $raw, true );
		// Keep only well-formed service-account JSON; reject anything else.
		return is_array( $decoded ) && isset( $decoded['client_email'], $decoded['private_key'] ) ? $raw : '';
	}

	// --- Action handlers --------------------------------------------------

	public function handleSyncNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'precision_analytics_sync_now' );

		$reports = new Reports( $this->options );
		if ( ! $reports->isConfigured() ) {
			$this->redirect( 'not_configured' );
		}
		$reports->syncAll();
		$this->redirect( 'synced' );
	}

	public function handleTest(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'precision_analytics_test' );

		$reports = new Reports( $this->options );
		$result  = $reports->testConnection();

		if ( true === $result ) {
			$this->redirect( 'test_ok' );
		}

		$detail = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error.', 'precision-analytics' );
		set_transient(
			'precision_analytics_notice',
			sprintf(
				/* translators: %s: error detail from Google. */
				__( 'Connection failed: %s', 'precision-analytics' ),
				$detail
			),
			60
		);
		$this->redirect( 'test_fail' );
	}

	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE, 'tab' => 'reporting', 'pa_notice' => $notice ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function maybeNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		if ( ! isset( $_GET['pa_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice  = sanitize_key( wp_unslash( $_GET['pa_notice'] ) );
		$stored  = get_transient( 'precision_analytics_notice' );
		$is_error = in_array( $notice, [ 'not_configured', 'test_fail' ], true );

		$message = match ( $notice ) {
			'synced'         => __( 'Reports synced.', 'precision-analytics' ),
			'test_ok'        => __( 'Connection succeeded — data is flowing.', 'precision-analytics' ),
			'not_configured' => __( 'Reporting is not configured yet — set a property ID and credentials.', 'precision-analytics' ),
			'test_fail'      => is_string( $stored ) && '' !== $stored ? $stored : __( 'Connection failed.', 'precision-analytics' ),
			default          => '',
		};
		if ( is_string( $stored ) ) {
			delete_transient( 'precision_analytics_notice' );
		}
		if ( '' === $message ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$is_error ? 'error' : 'success',
			esc_html( $message )
		);
	}

	// --- Field renderers --------------------------------------------------

	private function inputRow( string $label, string $path, string $type = 'text', string $placeholder = '', string $help = '' ): void {
		$id = $this->id( $path );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%4$s" value="%5$s" class="regular-text" placeholder="%6$s" />%7$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $this->name( $path ) ),
			esc_attr( (string) $this->options->get( $path, '' ) ),
			esc_attr( $placeholder ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function textareaRow( string $label, string $path, string $help = '', int $rows = 5 ): void {
		$id = $this->id( $path );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '" rows="' . absint( $rows ) . '" class="large-text code">'
			. esc_textarea( (string) $this->options->get( $path, '' ) ) . '</textarea>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @param array<string,string> $choices */
	private function selectRow( string $label, string $path, array $choices ): void {
		$id      = $this->id( $path );
		$current = (string) $this->options->get( $path, '' );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '">';
		foreach ( $choices as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function checkboxRow( string $label, string $path, string $help = '' ): void {
		$id   = $this->id( $path );
		$name = $this->name( $path );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label for="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $this->options->bool( $path ), true, false ) . ' /> ';
		echo esc_html( $help );
		echo '</label></td></tr>';
	}

	private function help( string $text ): void {
		echo '<tr><td colspan="2"><p class="description"><strong>' . esc_html( $text ) . '</strong></p></td></tr>';
	}

	/** @return array<string,string> */
	private function grantedDeniedChoices(): array {
		return [
			'denied'  => __( 'Denied', 'precision-analytics' ),
			'granted' => __( 'Granted', 'precision-analytics' ),
		];
	}

	private function trackingId(): string {
		return 'gtm' === $this->options->str( 'general.transport', 'gtag' )
			? $this->options->str( 'general.gtm_id' )
			: $this->options->str( 'general.measurement_id' );
	}

	// --- Path helpers -----------------------------------------------------

	private function name( string $path ): string {
		return Options::OPTION . '[' . implode( '][', explode( '.', $path ) ) . ']';
	}

	private function id( string $path ): string {
		return 'pa_' . str_replace( '.', '_', $path );
	}

	/** @param array<string,mixed> $array */
	private function hasNested( array $array, string $path ): bool {
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $array ) || ! array_key_exists( $segment, $array ) ) {
				return false;
			}
			$array = $array[ $segment ];
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $array
	 * @return mixed
	 */
	private function getNested( array $array, string $path ): mixed {
		foreach ( explode( '.', $path ) as $segment ) {
			$array = $array[ $segment ];
		}
		return $array;
	}

	/**
	 * @param array<string,mixed> $array
	 * @param mixed               $value
	 */
	private function setNested( array &$array, string $path, $value ): void {
		$segments = explode( '.', $path );
		$ref      = &$array;
		foreach ( $segments as $i => $segment ) {
			if ( $i === count( $segments ) - 1 ) {
				$ref[ $segment ] = $value;
			} else {
				if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
					$ref[ $segment ] = [];
				}
				$ref = &$ref[ $segment ];
			}
		}
	}

	/**
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	private function mergeDeep( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->mergeDeep( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
