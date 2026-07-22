<?php
/**
 * Hub61_Admin — menu, assets e render da página do Hub 61 Labs.
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Admin {

	private static $hook = '';

	const SUPPORT_URL = 'https://61labs.com.br/suporte';
	const SITE_URL    = 'https://61labs.com.br';
	const IDEA_EMAIL  = 'contato@61labs.com.br';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		// Ícone da marca 61 Labs (monocromático para a barra do admin).
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 172.33 123.62" fill="#a7aaad"><path d="M17.58 67.34c2.73 0 4.98-1.99 5.41-4.6h31.1v-1.77h-31.1c-.42-2.61-2.68-4.6-5.41-4.6-3.03 0-5.49 2.46-5.49 5.49s2.46 5.49 5.49 5.49Z"/><path d="M51.84 83.92v-1.77H10.89c-.42-2.61-2.68-4.6-5.41-4.6C2.46 77.55 0 80.01 0 83.04s2.46 5.49 5.49 5.49c2.73 0 4.98-1.99 5.41-4.6h40.94Z"/><circle cx="134.75" cy="90.22" r="5.49"/><path d="M160.88 17.97s-11.03 1.09-24.82 18.14h19.05l2.26-1.29-.09 81.27h15.05V17.97h-11.46Z"/><path d="M109.42 39.46l29.67-28.64c.9-.87.94-2.31.08-3.22-.98-1.03-2.63-2.57-5.44-4.76-3.82-2.99-6.94-2.97-8.34-2.74-.47.08-.91.3-1.25.64 0 0-52.46 52.29-52.53 52.38-6.69 7.48-10.77 17.36-10.77 28.19 0 23.37 18.95 42.32 42.32 42.32 13.59 0 25.67-6.41 33.41-16.36l-13.87-9.03c-4.74 5.47-11.73 8.93-19.54 8.93-14.28 0-25.86-11.58-25.86-25.86s11.58-25.86 25.86-25.86c12.55 0 23.01 8.94 25.36 20.8h16.64c-2.26-18.92-16.99-34-35.74-36.79Z"/></svg>';

		self::$hook = add_menu_page(
			'Hub 61 Labs',
			'Hub 61 Labs',
			'manage_options',
			'hub-61labs',
			array( __CLASS__, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( $icon ),
			2
		);
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'hub61-fonts', 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap', array(), HUB61_VERSION );
		wp_enqueue_style( 'hub61-app', HUB61_URL . 'admin/css/app.css', array(), HUB61_VERSION );
		wp_enqueue_script( 'hub61-app', HUB61_URL . 'admin/js/app.js', array(), HUB61_VERSION, true );

		wp_localize_script( 'hub61-app', 'HUB61', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'hub61_admin' ),
			'i18n'  => array(
				'installing' => __( 'Instalando…', 'hub-61labs' ),
				'activating' => __( 'Ativando…', 'hub-61labs' ),
				'error'      => __( 'Ocorreu um erro. Tente novamente.', 'hub-61labs' ),
			),
		) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$catalog   = Hub61_Catalog::all();
		$can_install = current_user_can( 'install_plugins' );
		?>
		<div class="wrap hub61-wrap">

			<div class="hub61-header">
				<div class="hub61-brand">
					<span class="hub61-logo" aria-hidden="true"><?php echo self::logo_svg(); // phpcs:ignore ?></span>
					<div>
						<h1><?php esc_html_e( 'Hub 61 Labs', 'hub-61labs' ); ?></h1>
						<p><?php esc_html_e( 'Todas as ferramentas da 61 Labs em um só lugar. Instale, ative e gerencie direto do painel.', 'hub-61labs' ); ?></p>
					</div>
				</div>
				<div class="hub61-actions">
					<a class="hub61-btn hub61-btn-ghost" href="<?php echo esc_url( self::SITE_URL ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( '61labs.com.br', 'hub-61labs' ); ?>
						<span aria-hidden="true">↗</span>
					</a>
				</div>
			</div>

			<?php if ( ! $can_install ) : ?>
				<div class="hub61-notice hub61-notice-warn">
					<?php esc_html_e( 'Seu usuário não tem permissão para instalar plugins. Fale com um administrador do site.', 'hub-61labs' ); ?>
				</div>
			<?php endif; ?>

			<section class="hub61-section">
				<div class="hub61-section-head">
					<h2><?php esc_html_e( 'Ferramentas disponíveis', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Plugins profissionais e independentes — seus dados continuam sendo seus.', 'hub-61labs' ); ?></p>
				</div>

				<div class="hub61-grid">
					<?php foreach ( $catalog as $item ) :
						$state = Hub61_Installer::state( $item );
						?>
						<article class="hub61-card" data-slug="<?php echo esc_attr( $item['slug'] ); ?>" data-file="<?php echo esc_attr( $item['plugin_file'] ); ?>" data-admin="<?php echo esc_url( admin_url( $item['admin_url'] ) ); ?>">
							<div class="hub61-card-top">
								<span class="hub61-card-icon" aria-hidden="true"><?php echo self::plugin_icon( $item['icon'] ); // phpcs:ignore ?></span>
								<span class="hub61-chip"><?php echo esc_html( $item['category'] ); ?></span>
							</div>
							<h3 class="hub61-card-title"><?php echo esc_html( $item['name'] ); ?></h3>
							<p class="hub61-card-tagline"><?php echo esc_html( $item['tagline'] ); ?></p>
							<p class="hub61-card-desc"><?php echo esc_html( $item['description'] ); ?></p>
							<div class="hub61-card-foot" data-state="<?php echo esc_attr( $state ); ?>">
								<?php self::render_action( $item, $state, $can_install ); ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="hub61-section hub61-two">
				<div class="hub61-panel hub61-panel-idea">
					<div class="hub61-panel-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2Z"/></svg>
					</div>
					<h2><?php esc_html_e( 'Tem uma ideia?', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Conte o que falta no seu WordPress. As melhores ideias viram os próximos plugins da 61 Labs.', 'hub-61labs' ); ?></p>
					<form class="hub61-idea-form" id="hub61-idea-form">
						<textarea id="hub61-idea-text" rows="4" placeholder="<?php esc_attr_e( 'Descreva sua ideia ou necessidade…', 'hub-61labs' ); ?>"></textarea>
						<button type="submit" class="hub61-btn hub61-btn-primary">
							<?php esc_html_e( 'Enviar ideia', 'hub-61labs' ); ?>
						</button>
					</form>
				</div>

				<div class="hub61-panel hub61-panel-support">
					<div class="hub61-panel-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
					</div>
					<h2><?php esc_html_e( 'Precisa de ajuda?', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Nossa equipe de suporte responde suas dúvidas sobre qualquer plugin da 61 Labs.', 'hub-61labs' ); ?></p>
					<a class="hub61-btn hub61-btn-dark" href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Falar com o suporte', 'hub-61labs' ); ?>
						<span aria-hidden="true">↗</span>
					</a>
				</div>
			</section>

			<footer class="hub61-footer">
				<span class="hub61-footer-logo" aria-hidden="true"><?php echo self::logo_svg(); // phpcs:ignore ?></span>
				<span><?php printf(
					/* translators: %s year */
					esc_html__( '© %s 61 Labs — os dados são seus.', 'hub-61labs' ),
					esc_html( gmdate( 'Y' ) )
				); ?></span>
			</footer>
		</div>
		<script type="application/json" id="hub61-idea-cfg"><?php
			echo wp_json_encode( array(
				'email'   => self::IDEA_EMAIL,
				'subject' => __( 'Ideia para a 61 Labs', 'hub-61labs' ),
				'site'    => home_url(),
			) );
		?></script>
		<?php
	}

	/**
	 * Botão de ação de um card conforme o estado.
	 *
	 * @param array<string,string> $item     Item do catálogo.
	 * @param string               $state    not-installed|inactive|active.
	 * @param bool                 $can_install Usuário pode instalar plugins.
	 */
	private static function render_action( $item, $state, $can_install ) {
		if ( 'active' === $state ) {
			printf(
				'<span class="hub61-status hub61-status-active"><span aria-hidden="true">●</span> %s</span>',
				esc_html__( 'Ativo', 'hub-61labs' )
			);
			printf(
				'<a class="hub61-btn hub61-btn-ghost hub61-open" href="%s">%s</a>',
				esc_url( admin_url( $item['admin_url'] ) ),
				esc_html__( 'Abrir', 'hub-61labs' )
			);
			return;
		}
		if ( ! $can_install ) {
			printf(
				'<span class="hub61-status hub61-status-muted">%s</span>',
				'inactive' === $state
					? esc_html__( 'Instalado (inativo)', 'hub-61labs' )
					: esc_html__( 'Não instalado', 'hub-61labs' )
			);
			return;
		}
		if ( 'inactive' === $state ) {
			printf(
				'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="activate">%s</button>',
				esc_html__( 'Ativar', 'hub-61labs' )
			);
			return;
		}
		printf(
			'<button type="button" class="hub61-btn hub61-btn-primary hub61-do" data-act="install">%s</button>',
			esc_html__( 'Instalar', 'hub-61labs' )
		);
	}

	/**
	 * Logo 61 Labs (ícone verde) inline.
	 */
	private static function logo_svg() {
		return '<svg viewBox="0 0 172.33 123.62" fill="currentColor" aria-hidden="true"><path d="M17.58 67.34c2.73 0 4.98-1.99 5.41-4.6h31.1v-1.77h-31.1c-.42-2.61-2.68-4.6-5.41-4.6-3.03 0-5.49 2.46-5.49 5.49s2.46 5.49 5.49 5.49Z"/><path d="M32.41 69.82c-1.38 0-2.5 1.12-2.5 2.5s1.12 2.5 2.5 2.5c1.07 0 1.97-.67 2.33-1.62h16.76v-1.77h-16.76c-.36-.94-1.26-1.62-2.33-1.62Z"/><path d="M42.48 106.24c-1.83 0-3.31 1.48-3.31 3.31s1.48 3.31 3.31 3.31c1.61 0 2.94-1.15 3.24-2.66h15.93v-1.77h-16.08c-.46-1.27-1.67-2.19-3.1-2.19Z"/><path d="M51.84 83.92v-1.77H10.89c-.42-2.61-2.68-4.6-5.41-4.6C2.46 77.55 0 80.01 0 83.04s2.46 5.49 5.49 5.49c2.73 0 4.98-1.99 5.41-4.6h40.94Z"/><path d="M55.89 98.89v-1.77h-21.42c-.42-2.61-2.68-4.6-5.41-4.6-3.03 0-5.49 2.46-5.49 5.49s2.46 5.49 5.49 5.49c2.73 0 4.98-1.99 5.41-4.6h21.42Z"/><circle cx="134.75" cy="90.22" r="5.49"/><path d="M160.88 17.97s-11.03 1.09-24.82 18.14h19.05l2.26-1.29-.09 81.27h15.05V17.97h-11.46Z"/><path d="M109.42 39.46l29.67-28.64c.9-.87.94-2.31.08-3.22-.98-1.03-2.63-2.57-5.44-4.76-3.82-2.99-6.94-2.97-8.34-2.74-.47.08-.91.3-1.25.64 0 0-52.46 52.29-52.53 52.38-6.69 7.48-10.77 17.36-10.77 28.19 0 23.37 18.95 42.32 42.32 42.32 13.59 0 25.67-6.41 33.41-16.36l-13.87-9.03c-4.74 5.47-11.73 8.93-19.54 8.93-14.28 0-25.86-11.58-25.86-25.86s11.58-25.86 25.86-25.86c12.55 0 23.01 8.94 25.36 20.8h16.64c-2.26-18.92-16.99-34-35.74-36.79Z"/></svg>';
	}

	/**
	 * Ícone temático de cada plugin (Feather-style, herda currentColor).
	 *
	 * @param string $key Chave do ícone.
	 */
	private static function plugin_icon( $key ) {
		$icons = array(
			'cluster' => '<circle cx="12" cy="5" r="2.2"/><circle cx="5" cy="17" r="2.2"/><circle cx="19" cy="17" r="2.2"/><path d="M12 7.2v4.8M12 12l-5 3.6M12 12l5 3.6"/>',
			'orbit'   => '<circle cx="12" cy="12" r="2.2" fill="currentColor" stroke="none"/><ellipse cx="12" cy="12" rx="9.5" ry="4.2"/><ellipse cx="12" cy="12" rx="9.5" ry="4.2" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="9.5" ry="4.2" transform="rotate(120 12 12)"/>',
			'metrics' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
			'qr'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v0M17 21h4v-4M14 21h0"/>',
			'author'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'clinic'  => '<path d="M12 5v14M5 12h14" stroke-width="2.4"/><rect x="3" y="3" width="18" height="18" rx="3"/>',
		);
		$body = isset( $icons[ $key ] ) ? $icons[ $key ] : $icons['orbit'];
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
	}
}
