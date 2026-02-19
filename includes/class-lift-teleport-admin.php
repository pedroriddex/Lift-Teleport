<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Admin {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register plugin menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Lift Teleport', 'lift-teleport' ),
			__( 'Lift Teleport', 'lift-teleport' ),
			'manage_options',
			'lift-teleport',
			array( $this, 'render_page' ),
			'dashicons-migrate',
			58
		);
	}

	/**
	 * Load admin scripts/styles only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_lift-teleport' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lift-teleport-admin',
			LIFT_TELEPORT_URL . 'assets/css/admin.css',
			array(),
			LIFT_TELEPORT_VERSION
		);

		wp_enqueue_script(
			'lift-teleport-admin',
			LIFT_TELEPORT_URL . 'assets/js/admin.js',
			array(),
			LIFT_TELEPORT_VERSION,
			true
		);

		wp_localize_script(
			'lift-teleport-admin',
			'liftTeleportAdmin',
			array(
				'baseUrl'      => esc_url_raw( rest_url( 'lift-teleport/v1/import' ) ),
				'pollInterval' => 2000,
				'retryBaseMs'  => 400,
				'retryMaxAttempts' => 4,
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render admin interface prototype.
	 */
	public function render_page() {
		?>
		<div class="wrap lift-teleport">
			<div class="lift-teleport__hero">
				<div>
					<h1><?php esc_html_e( 'Teleport', 'lift-teleport' ); ?></h1>
					<p><?php esc_html_e( 'Migra cualquier web WordPress con un solo archivo .lift.', 'lift-teleport' ); ?></p>
				</div>
				<span class="lift-teleport__badge"><?php esc_html_e( 'Prototype', 'lift-teleport' ); ?></span>
			</div>

			<div class="lift-teleport__cards">
				<section class="lift-teleport__card">
					<h2><?php esc_html_e( 'Exportar sitio', 'lift-teleport' ); ?></h2>
					<p><?php esc_html_e( 'Empaqueta base de datos, media, plugins y temas en un archivo .lift.', 'lift-teleport' ); ?></p>
					<button class="button button-primary button-hero" type="button" disabled>
						<?php esc_html_e( 'Iniciar exportación (próximamente)', 'lift-teleport' ); ?>
					</button>
				</section>

				<section class="lift-teleport__card">
					<h2><?php esc_html_e( 'Importación automática', 'lift-teleport' ); ?></h2>
					<p><?php esc_html_e( 'Arrastra tu archivo .lift y Teleport se encarga de todo.', 'lift-teleport' ); ?></p>

					<label class="lift-teleport__dropzone" for="lift-teleport-file">
						<input id="lift-teleport-file" type="file" accept=".lift" />
						<strong><?php esc_html_e( 'Arrastra aquí tu archivo .lift', 'lift-teleport' ); ?></strong>
						<span><?php esc_html_e( 'o haz click para seleccionarlo', 'lift-teleport' ); ?></span>
					</label>

					<div class="lift-teleport__progress" aria-live="polite" aria-atomic="true">
						<div class="lift-teleport__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
					</div>

					<div class="lift-teleport__metrics">
						<p><strong><?php esc_html_e( 'Fase actual:', 'lift-teleport' ); ?></strong> <span data-lift-teleport-phase><?php esc_html_e( 'Esperando archivo', 'lift-teleport' ); ?></span></p>
						<p><strong><?php esc_html_e( 'Progreso:', 'lift-teleport' ); ?></strong> <span data-lift-teleport-percent>0%</span></p>
						<p><strong><?php esc_html_e( 'Velocidad aprox.:', 'lift-teleport' ); ?></strong> <span data-lift-teleport-speed><?php esc_html_e( 'Calculando…', 'lift-teleport' ); ?></span></p>
						<p><strong><?php esc_html_e( 'Tiempo restante estimado:', 'lift-teleport' ); ?></strong> <span data-lift-teleport-eta><?php esc_html_e( 'Calculando…', 'lift-teleport' ); ?></span></p>
					</div>

					<div class="lift-teleport__controls">
						<button class="button" type="button" data-lift-teleport-action="pause" disabled><?php esc_html_e( 'Pausar', 'lift-teleport' ); ?></button>
						<button class="button" type="button" data-lift-teleport-action="resume" disabled><?php esc_html_e( 'Reanudar', 'lift-teleport' ); ?></button>
						<button class="button button-link-delete" type="button" data-lift-teleport-action="cancel" disabled><?php esc_html_e( 'Cancelar', 'lift-teleport' ); ?></button>
					</div>
					<p class="lift-teleport__status"><?php esc_html_e( 'Esperando archivo .lift…', 'lift-teleport' ); ?></p>
				</section>
			</div>
		</div>
		<?php
	}
}
