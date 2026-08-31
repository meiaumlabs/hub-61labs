<?php
/**
 * Hub61_Report — relatório de performance dos plugins 61 Labs.
 *
 * Reúne seções fornecidas por cada plugin (filtro `hub61_report_sections`), gera um PDF
 * (Dompdf embutido) e envia por e-mail e por WhatsApp (Evolution API). Suporta MODELOS
 * de relatório (logotipo, cores, textos, plugins, período, seções) com pré-visualização
 * (HTML ao vivo e PDF) antes do envio. O número do dono
 * ({@see Hub61_Evolution::OWNER_NUMBER}) sempre recebe uma cópia via WhatsApp.
 *
 * Fase 1: cada site relata a si mesmo. Seleção remota de sites = fase 2.
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Report {

	const OPT_TEMPLATES = 'hub61_report_templates'; // array<id,config>
	const OPT_ACTIVE    = 'hub61_report_active';     // id do modelo usado no envio/agendamento
	const OPT_SCHEDULE  = 'hub61_report_schedule';   // off | weekly | monthly

	const META_OPTIN    = 'hub61_report_optin';      // '1' | ''
	const META_EMAIL    = 'hub61_report_email';
	const META_WHATSAPP = 'hub61_report_whatsapp';

	const CRON_HOOK = 'hub61_report_cron';

	/** Hook da página de Relatórios (para enfileirar assets só nela). */
	private static $page_hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_hub61_report_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_hub61_report_test', array( __CLASS__, 'ajax_test_evolution' ) );
		add_action( 'wp_ajax_hub61_report_send', array( __CLASS__, 'ajax_send_now' ) );
		add_action( 'wp_ajax_hub61_report_tpl_save', array( __CLASS__, 'ajax_tpl_save' ) );
		add_action( 'wp_ajax_hub61_report_tpl_get', array( __CLASS__, 'ajax_tpl_get' ) );
		add_action( 'wp_ajax_hub61_report_tpl_delete', array( __CLASS__, 'ajax_tpl_delete' ) );
		add_action( 'wp_ajax_hub61_report_preview_html', array( __CLASS__, 'ajax_preview_html' ) );
		add_action( 'wp_ajax_hub61_report_preview', array( __CLASS__, 'ajax_preview_pdf' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Modelos (templates)                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Configuração padrão de um modelo.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_template() {
		return array(
			'name'          => __( 'Modelo padrão', 'hub-61labs' ),
			'logo'          => '',
			'color_primary' => '#16a34a',
			'color_ink'     => '#0f172a',
			'intro'         => '',
			'footer'        => __( '© 61 Labs — os dados são seus.', 'hub-61labs' ),
			'plugins'       => wp_list_pluck( Hub61_Catalog::all(), 'slug' ),
			'range'         => '28d',
			'show_kpis'     => true,
			'show_tables'   => true,
			'show_charts'   => true,
		);
	}

	/**
	 * Todos os modelos salvos (garante ao menos o "default").
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function templates() {
		$saved = get_option( self::OPT_TEMPLATES, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return array( 'default' => self::default_template() );
		}
		return $saved;
	}

	/**
	 * Um modelo pelo id, com defaults aplicados.
	 *
	 * @param string $id Id do modelo.
	 * @return array<string,mixed>
	 */
	public static function get_template( $id ) {
		$all = self::templates();
		$cfg = isset( $all[ $id ] ) ? $all[ $id ] : reset( $all );
		return wp_parse_args( is_array( $cfg ) ? $cfg : array(), self::default_template() );
	}

	/** Id do modelo ativo (usado no envio/agendamento). */
	public static function active_id() {
		$id  = (string) get_option( self::OPT_ACTIVE, 'default' );
		$all = self::templates();
		return isset( $all[ $id ] ) ? $id : (string) key( $all );
	}

	/**
	 * Monta uma config de modelo a partir do $_POST (sanitizada), sem salvar.
	 *
	 * @return array<string,mixed>
	 */
	public static function template_from_post() {
		$def     = self::default_template();
		$plugins = isset( $_POST['plugins'] ) ? (array) wp_unslash( $_POST['plugins'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$primary = isset( $_POST['color_primary'] ) ? sanitize_hex_color( wp_unslash( $_POST['color_primary'] ) ) : ''; // phpcs:ignore
		$ink     = isset( $_POST['color_ink'] ) ? sanitize_hex_color( wp_unslash( $_POST['color_ink'] ) ) : ''; // phpcs:ignore
		$range   = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : $def['range']; // phpcs:ignore
		return array(
			'name'          => isset( $_POST['tpl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tpl_name'] ) ) : $def['name'], // phpcs:ignore
			'logo'          => isset( $_POST['logo'] ) ? esc_url_raw( wp_unslash( $_POST['logo'] ) ) : '', // phpcs:ignore
			'color_primary' => $primary ? $primary : $def['color_primary'],
			'color_ink'     => $ink ? $ink : $def['color_ink'],
			'intro'         => isset( $_POST['intro'] ) ? wp_kses_post( wp_unslash( $_POST['intro'] ) ) : '', // phpcs:ignore
			'footer'        => isset( $_POST['footer'] ) ? sanitize_text_field( wp_unslash( $_POST['footer'] ) ) : $def['footer'], // phpcs:ignore
			'plugins'       => array_values( array_filter( array_map( 'sanitize_key', $plugins ) ) ),
			'range'         => in_array( $range, array( '7d', '28d', '90d', '365d' ), true ) ? $range : '28d',
			'show_kpis'     => ! empty( $_POST['show_kpis'] ), // phpcs:ignore
			'show_tables'   => ! empty( $_POST['show_tables'] ), // phpcs:ignore
			'show_charts'   => ! empty( $_POST['show_charts'] ), // phpcs:ignore
		);
	}

	/* --------------------------------------------------------------------- */
	/* Coleta + PDF                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Contexto do relatório (período) a partir do modelo.
	 *
	 * @param array $tpl Modelo.
	 * @return array{range:string,from:string,to:string}
	 */
	public static function context( $tpl ) {
		$range = isset( $tpl['range'] ) ? $tpl['range'] : '28d';
		$days  = array( '7d' => 7, '28d' => 28, '90d' => 90, '365d' => 365 );
		$to    = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $to . ' -' . ( isset( $days[ $range ] ) ? $days[ $range ] : 28 ) . ' days' ) );
		return array( 'range' => $range, 'from' => $from, 'to' => $to );
	}

	/**
	 * Itens (catálogo) correspondentes aos slugs do modelo.
	 *
	 * @param array $tpl Modelo.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tpl_items( $tpl ) {
		$slugs = isset( $tpl['plugins'] ) && is_array( $tpl['plugins'] ) ? $tpl['plugins'] : array();
		$out   = array();
		foreach ( $slugs as $slug ) {
			$item = Hub61_Catalog::get( $slug );
			if ( ! $item && class_exists( 'Hub61_Extra' ) ) {
				$item = Hub61_Extra::get( $slug );
			}
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Reúne as seções do relatório (filtro + fallback básico), filtrando pelos plugins do modelo.
	 *
	 * @param array $ctx Contexto.
	 * @param array $tpl Modelo.
	 * @return array<int,array<string,mixed>>
	 */
	public static function gather( $ctx, $tpl ) {
		$all = apply_filters( 'hub61_report_sections', array(), $ctx );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$want = isset( $tpl['plugins'] ) && is_array( $tpl['plugins'] ) ? $tpl['plugins'] : array();

		$sections = array();
		$provided = array();
		foreach ( $all as $s ) {
			if ( isset( $s['slug'] ) && in_array( $s['slug'], $want, true ) ) {
				$sections[] = $s;
				$provided[] = $s['slug'];
			}
		}

		foreach ( self::tpl_items( $tpl ) as $item ) {
			if ( in_array( $item['slug'], $provided, true ) ) {
				continue;
			}
			$state = Hub61_Installer::state( $item );
			if ( 'not-installed' === $state ) {
				continue;
			}
			$sections[] = array(
				'slug'    => $item['slug'],
				'name'    => isset( $item['name'] ) ? $item['name'] : $item['slug'],
				'metrics' => array(
					array( 'label' => __( 'Versão instalada', 'hub-61labs' ), 'value' => Hub61_Installer::installed_version( $item ) ?: '—' ),
					array( 'label' => __( 'Status', 'hub-61labs' ), 'value' => ( 'active' === $state ) ? __( 'Ativo', 'hub-61labs' ) : __( 'Inativo', 'hub-61labs' ) ),
				),
				'tables'  => array(),
			);
		}
		return $sections;
	}

	/**
	 * Resolve o logotipo em data URI (evita fetch remoto no Dompdf).
	 *
	 * @param string $url URL do logo (idealmente anexo do próprio site).
	 * @return string data:... ou '' se não resolver.
	 */
	private static function logo_data_uri( $url ) {
		if ( '' === $url ) {
			return '';
		}
		$path = '';
		$id   = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0;
		if ( $id ) {
			$path = get_attached_file( $id );
		}
		if ( ! $path || ! file_exists( $path ) ) {
			// Tenta mapear URL do próprio site para caminho local.
			$base = content_url();
			if ( 0 === strpos( $url, $base ) ) {
				$path = WP_CONTENT_DIR . substr( $url, strlen( $base ) );
			}
		}
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}
		$mime = wp_check_filetype( $path );
		$type = ! empty( $mime['type'] ) ? $mime['type'] : 'image/png';
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $data ) {
			return '';
		}
		return 'data:' . $type . ';base64,' . base64_encode( $data );
	}

	/**
	 * Variação percentual de uma métrica vs período anterior.
	 *
	 * @param array $m Métrica (precisa de 'raw' e 'prev').
	 * @return array{pct:float,dir:string,good:?bool}|null
	 */
	private static function delta( $m ) {
		if ( ! isset( $m['raw'] ) || ! isset( $m['prev'] ) ) {
			return null;
		}
		$cur  = (float) $m['raw'];
		$prev = (float) $m['prev'];
		if ( 0.0 === $prev ) {
			if ( 0.0 === $cur ) {
				return null;
			}
			$pct = 100.0;
		} else {
			$pct = ( $cur - $prev ) / abs( $prev ) * 100.0;
		}
		$dir    = $pct > 0.5 ? 'up' : ( $pct < -0.5 ? 'down' : 'flat' );
		$better = isset( $m['better'] ) ? $m['better'] : 'up';
		$good   = ( 'flat' === $dir ) ? null : ( ( 'up' === $better ) ? ( 'up' === $dir ) : ( 'down' === $dir ) );
		return array( 'pct' => $pct, 'dir' => $dir, 'good' => $good );
	}

	/** HTML da variação (seta + %) de uma métrica, ou '' se não houver comparação. */
	private static function delta_html( $m ) {
		$d = self::delta( $m );
		if ( null === $d ) {
			return '';
		}
		$arrow = 'up' === $d['dir'] ? "\xE2\x96\xB2" : ( 'down' === $d['dir'] ? "\xE2\x96\xBC" : "\xE2\x96\xA0" );
		$color = ( true === $d['good'] ) ? '#16a34a' : ( ( false === $d['good'] ) ? '#dc2626' : '#94a3b8' );
		$sign  = $d['pct'] >= 0 ? '+' : '-';
		return '<div class="d" style="color:' . $color . '">' . $arrow . ' ' . $sign . number_format( abs( $d['pct'] ), 1, ',', '.' ) . '%</div>';
	}

	/** Gráfico de linha (uma ou mais séries) como <img> SVG data-URI. */
	private static function svg_line( $c ) {
		$series = isset( $c['series'] ) && is_array( $c['series'] ) ? $c['series'] : array();
		if ( empty( $series ) ) {
			return '';
		}
		$w = 560; $h = 200; $pt = 30; $pb = 16; $px = 10;
		$max = 1.0; $n = 0;
		foreach ( $series as $s ) {
			$pts = isset( $s['points'] ) ? array_map( 'floatval', (array) $s['points'] ) : array();
			foreach ( $pts as $p ) { if ( $p > $max ) { $max = $p; } }
			if ( count( $pts ) > $n ) { $n = count( $pts ); }
		}
		$iw = $w - 2 * $px; $ih = $h - $pt - $pb;
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
		$svg .= '<rect width="' . $w . '" height="' . $h . '" fill="#ffffff"/>';
		for ( $g = 0; $g <= 4; $g++ ) {
			$y = round( $pt + $ih * $g / 4, 1 );
			$svg .= '<line x1="' . $px . '" y1="' . $y . '" x2="' . ( $w - $px ) . '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"/>';
		}
		$lx = $px;
		foreach ( $series as $s ) {
			$color = isset( $s['color'] ) ? $s['color'] : '#2563eb';
			$label = isset( $s['label'] ) ? (string) $s['label'] : '';
			$pts   = isset( $s['points'] ) ? array_map( 'floatval', (array) $s['points'] ) : array();
			$m     = count( $pts );
			$poly  = '';
			for ( $i = 0; $i < $m; $i++ ) {
				$x = $px + $iw * ( $m > 1 ? $i / ( $m - 1 ) : 0 );
				$y = $pt + $ih - ( $ih * ( $pts[ $i ] / $max ) );
				$poly .= round( $x, 1 ) . ',' . round( $y, 1 ) . ' ';
			}
			if ( '' !== $poly ) {
				$svg .= '<polyline fill="none" stroke="' . $color . '" stroke-width="2" points="' . trim( $poly ) . '"/>';
			}
			$svg .= '<rect x="' . $lx . '" y="10" width="10" height="10" rx="2" fill="' . $color . '"/>';
			$svg .= '<text x="' . ( $lx + 14 ) . '" y="19" font-family="DejaVu Sans, sans-serif" font-size="11" fill="#334155">' . htmlspecialchars( $label, ENT_QUOTES ) . '</text>';
			$lx  += 30 + strlen( $label ) * 6;
		}
		$svg .= '</svg>';
		return '<img class="chart" src="data:image/svg+xml;base64,' . base64_encode( $svg ) . '" alt="">';
	}

	/** Gráfico de barras comparativo (atual vs anterior) como <img> SVG data-URI. */
	private static function svg_bar( $c ) {
		$items = isset( $c['items'] ) && is_array( $c['items'] ) ? array_slice( $c['items'], 0, 6 ) : array();
		if ( empty( $items ) ) {
			return '';
		}
		$w = 560; $h = 210; $pt = 30; $pb = 42; $px = 12;
		$max = 1.0;
		foreach ( $items as $it ) {
			$max = max( $max, (float) ( isset( $it['current'] ) ? $it['current'] : 0 ), (float) ( isset( $it['previous'] ) ? $it['previous'] : 0 ) );
		}
		$iw = $w - 2 * $px; $ih = $h - $pt - $pb;
		$groups = count( $items ); $gw = $iw / $groups; $bw = min( 26, $gw / 3.2 );
		$c_cur = '#2563eb'; $c_prev = '#cbd5e1';
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
		$svg .= '<rect width="' . $w . '" height="' . $h . '" fill="#ffffff"/>';
		for ( $g = 0; $g <= 4; $g++ ) {
			$y = round( $pt + $ih * $g / 4, 1 );
			$svg .= '<line x1="' . $px . '" y1="' . $y . '" x2="' . ( $w - $px ) . '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"/>';
		}
		// Legenda.
		$svg .= '<rect x="' . $px . '" y="10" width="10" height="10" rx="2" fill="' . $c_cur . '"/><text x="' . ( $px + 14 ) . '" y="19" font-family="DejaVu Sans, sans-serif" font-size="11" fill="#334155">Atual</text>';
		$svg .= '<rect x="' . ( $px + 70 ) . '" y="10" width="10" height="10" rx="2" fill="' . $c_prev . '"/><text x="' . ( $px + 84 ) . '" y="19" font-family="DejaVu Sans, sans-serif" font-size="11" fill="#334155">Anterior</text>';
		$base = $pt + $ih;
		foreach ( $items as $i => $it ) {
			$cur   = (float) ( isset( $it['current'] ) ? $it['current'] : 0 );
			$prev  = (float) ( isset( $it['previous'] ) ? $it['previous'] : 0 );
			$label = isset( $it['label'] ) ? (string) $it['label'] : '';
			$gx    = $px + $gw * $i + $gw / 2;
			$hc    = $ih * ( $cur / $max );
			$hp    = $ih * ( $prev / $max );
			$x1    = round( $gx - $bw - 2, 1 ); $x2 = round( $gx + 2, 1 );
			$svg  .= '<rect x="' . $x1 . '" y="' . round( $base - $hc, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $hc, 1 ) . '" fill="' . $c_cur . '"/>';
			$svg  .= '<rect x="' . $x2 . '" y="' . round( $base - $hp, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $hp, 1 ) . '" fill="' . $c_prev . '"/>';
			$lbl   = mb_strimwidth( $label, 0, 12, '…', 'UTF-8' );
			$svg  .= '<text x="' . round( $gx, 1 ) . '" y="' . ( $base + 14 ) . '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="10" fill="#64748b">' . htmlspecialchars( $lbl, ENT_QUOTES ) . '</text>';
		}
		$svg .= '</svg>';
		return '<img class="chart" src="data:image/svg+xml;base64,' . base64_encode( $svg ) . '" alt="">';
	}

	/**
	 * Monta o HTML do relatório conforme o modelo.
	 *
	 * @param array $sections Seções.
	 * @param array $meta     Metadados do site/período.
	 * @param array $tpl      Modelo.
	 * @return string
	 */
	public static function build_html( $sections, $meta, $tpl ) {
		$esc     = 'esc_html';
		$primary = sanitize_hex_color( $tpl['color_primary'] ) ?: '#16a34a';
		$ink     = sanitize_hex_color( $tpl['color_ink'] ) ?: '#0f172a';
		$logo    = self::logo_data_uri( isset( $tpl['logo'] ) ? $tpl['logo'] : '' );
		$footer  = isset( $tpl['footer'] ) && '' !== $tpl['footer'] ? $tpl['footer'] : __( '© 61 Labs — os dados são seus.', 'hub-61labs' );
		ob_start();
		?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>
	* { font-family: 'DejaVu Sans', sans-serif; }
	body { color: #1e2530; font-size: 12px; }
	.head { border-bottom: 3px solid <?php echo $esc( $primary ); ?>; padding-bottom: 10px; margin-bottom: 16px; }
	.head table { width: 100%; }
	.head .logo { max-height: 52px; }
	.head h1 { font-size: 20px; margin: 0 0 4px; color: <?php echo $esc( $ink ); ?>; }
	.head .sub { font-size: 11px; color: #64748b; }
	.intro { font-size: 12px; color: #334155; margin: 0 0 14px; }
	h2 { font-size: 15px; margin: 20px 0 8px; color: <?php echo $esc( $ink ); ?>; border-left: 4px solid <?php echo $esc( $primary ); ?>; padding-left: 8px; }
	.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 8px; }
	.kpis td { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; width: 16%; vertical-align: top; }
	.kpis .l { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
	.kpis .v { font-size: 16px; font-weight: bold; color: <?php echo $esc( $ink ); ?>; }
	.kpis .d { font-size: 10px; font-weight: bold; margin-top: 2px; }
	.chart { display: block; margin: 4px 0 10px; max-width: 100%; }
	table.data { width: 100%; border-collapse: collapse; margin: 6px 0 4px; }
	table.data th { background: <?php echo $esc( $ink ); ?>; color: #fff; font-size: 10px; text-align: left; padding: 6px 8px; }
	table.data td { border-bottom: 1px solid #e2e8f0; padding: 5px 8px; font-size: 11px; }
	.tt { font-size: 12px; font-weight: bold; margin: 12px 0 2px; color: #334155; }
	.foot { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #94a3b8; }
	.empty { color: #94a3b8; font-size: 11px; }
</style></head><body>
	<div class="head"><table><tr>
		<td>
			<h1><?php echo $esc( __( 'Relatório de Performance', 'hub-61labs' ) ); ?></h1>
			<div class="sub"><?php echo $esc( $meta['site'] ); ?> &middot; <?php echo $esc( $meta['url'] ); ?><br>
			<?php printf( esc_html__( 'Período: %1$s (%2$s a %3$s) · Gerado em %4$s', 'hub-61labs' ), esc_html( $meta['range'] ), esc_html( $meta['from'] ), esc_html( $meta['to'] ), esc_html( $meta['date'] ) ); ?></div>
		</td>
		<?php if ( '' !== $logo ) : ?>
		<td style="text-align:right;vertical-align:top"><img class="logo" src="<?php echo esc_attr( $logo ); ?>" alt=""></td>
		<?php endif; ?>
	</tr></table></div>
	<?php if ( ! empty( $tpl['intro'] ) ) : ?>
	<p class="intro"><?php echo wp_kses_post( $tpl['intro'] ); ?></p>
	<?php endif; ?>
	<?php if ( empty( $sections ) ) : ?>
	<p class="empty"><?php echo $esc( __( 'Nenhum plugin selecionado/instalado com dados para relatar.', 'hub-61labs' ) ); ?></p>
	<?php endif; ?>
	<?php foreach ( $sections as $s ) : ?>
	<h2><?php echo $esc( isset( $s['name'] ) ? $s['name'] : '' ); ?></h2>
		<?php if ( ! empty( $tpl['show_kpis'] ) && ! empty( $s['metrics'] ) && is_array( $s['metrics'] ) ) : ?>
		<table class="kpis"><tr>
			<?php foreach ( array_slice( $s['metrics'], 0, 6 ) as $m ) : ?>
			<td><div class="l"><?php echo $esc( isset( $m['label'] ) ? $m['label'] : '' ); ?></div><div class="v"><?php echo $esc( isset( $m['value'] ) ? $m['value'] : '' ); ?></div><?php echo self::delta_html( $m ); // phpcs:ignore ?></td>
			<?php endforeach; ?>
		</tr></table>
		<?php endif; ?>
		<?php if ( ! empty( $tpl['show_charts'] ) && ! empty( $s['charts'] ) && is_array( $s['charts'] ) ) : ?>
			<?php foreach ( $s['charts'] as $ch ) :
				$img = ( isset( $ch['type'] ) && 'bar' === $ch['type'] ) ? self::svg_bar( $ch ) : self::svg_line( $ch );
				if ( '' === $img ) { continue; }
				?>
				<?php if ( ! empty( $ch['title'] ) ) : ?><div class="tt"><?php echo $esc( $ch['title'] ); ?></div><?php endif; ?>
				<?php echo $img; // phpcs:ignore — SVG data-URI gerado internamente ?>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( ! empty( $tpl['show_tables'] ) && ! empty( $s['tables'] ) && is_array( $s['tables'] ) ) : ?>
			<?php foreach ( $s['tables'] as $t ) : ?>
				<?php if ( ! empty( $t['rows'] ) && is_array( $t['rows'] ) ) : ?>
				<div class="tt"><?php echo $esc( isset( $t['title'] ) ? $t['title'] : '' ); ?></div>
				<table class="data">
					<?php if ( ! empty( $t['columns'] ) ) : ?>
					<tr><?php foreach ( $t['columns'] as $col ) : ?><th><?php echo $esc( $col ); ?></th><?php endforeach; ?></tr>
					<?php endif; ?>
					<?php foreach ( $t['rows'] as $row ) : ?>
					<tr><?php foreach ( (array) $row as $cell ) : ?><td><?php echo $esc( (string) $cell ); ?></td><?php endforeach; ?></tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
	<?php endforeach; ?>
	<div class="foot"><?php echo $esc( $footer ); ?></div>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Gera o HTML completo de um relatório para um modelo (usado por preview e envio).
	 *
	 * @param array $tpl Modelo.
	 * @return array{html:string,meta:array}
	 */
	public static function compose( $tpl ) {
		$ctx  = self::context( $tpl );
		$meta = array(
			'site'  => get_bloginfo( 'name' ),
			'url'   => home_url(),
			'range' => $ctx['range'],
			'from'  => $ctx['from'],
			'to'    => $ctx['to'],
			'date'  => date_i18n( 'd/m/Y H:i' ),
		);
		$html = self::build_html( self::gather( $ctx, $tpl ), $meta, $tpl );
		return array( 'html' => $html, 'meta' => $meta );
	}

	/**
	 * Renderiza HTML em PDF (binário) usando o Dompdf embutido.
	 *
	 * @param string $html HTML.
	 * @return string|WP_Error
	 */
	public static function render_pdf( $html ) {
		$autoload = HUB61_DIR . 'includes/lib/dompdf/autoload.inc.php';
		if ( ! file_exists( $autoload ) ) {
			return new WP_Error( 'hub61_pdf', __( 'Biblioteca de PDF não encontrada.', 'hub-61labs' ) );
		}
		require_once $autoload;
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return new WP_Error( 'hub61_pdf', __( 'Não foi possível carregar o gerador de PDF.', 'hub-61labs' ) );
		}
		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'defaultFont', 'DejaVu Sans' );
			$options->set( 'tempDir', get_temp_dir() );
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			return (string) $dompdf->output();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'hub61_pdf', sprintf( __( 'Falha ao gerar o PDF: %s', 'hub-61labs' ), $e->getMessage() ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Envio                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Destinatários de todos os usuários que optaram por receber.
	 *
	 * @return array{emails:array<int,string>,whatsapp:array<int,string>}
	 */
	public static function optin_recipients() {
		$emails = array();
		$wa     = array();
		$users  = get_users( array( 'meta_key' => self::META_OPTIN, 'meta_value' => '1', 'fields' => array( 'ID', 'user_email' ) ) );
		foreach ( $users as $u ) {
			$email = get_user_meta( $u->ID, self::META_EMAIL, true );
			$email = $email ? $email : $u->user_email;
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
			$num = get_user_meta( $u->ID, self::META_WHATSAPP, true );
			if ( $num ) {
				$wa[] = $num;
			}
		}
		return array( 'emails' => $emails, 'whatsapp' => $wa );
	}

	/**
	 * Gera e envia o relatório de um modelo.
	 *
	 * @param array  $tpl        Modelo.
	 * @param array  $recipients ['emails'=>[], 'whatsapp'=>[]].
	 * @param string $trigger    'manual' | 'cron'.
	 * @return array|WP_Error
	 */
	public static function send( $tpl, $recipients, $trigger = 'manual' ) {
		$c    = self::compose( $tpl );
		$pdf  = self::render_pdf( $c['html'] );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$meta     = $c['meta'];
		$filename = 'relatorio-61labs-' . sanitize_title( $meta['site'] ) . '-' . gmdate( 'Ymd-Hi' ) . '.pdf';
		$subject  = sprintf( __( 'Relatório de performance — %s', 'hub-61labs' ), $meta['site'] );
		$body     = sprintf(
			/* translators: 1: site, 2: período, 3: data */
			__( "Segue em anexo o relatório de performance dos plugins 61 Labs.\n\nSite: %1\$s\nPeríodo: %2\$s\nGerado em: %3\$s", 'hub-61labs' ),
			$meta['site'], $meta['range'], $meta['date']
		);

		$sent   = array( 'emails' => 0, 'whatsapp' => 0 );
		$errors = array();

		$emails = array_values( array_unique( array_filter( (array) $recipients['emails'], 'is_email' ) ) );
		if ( $emails ) {
			$tmp = wp_tempnam( $filename );
			if ( $tmp && false !== file_put_contents( $tmp, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$named = dirname( $tmp ) . '/' . $filename;
				@rename( $tmp, $named ); // phpcs:ignore
				$attach = file_exists( $named ) ? $named : $tmp;
				foreach ( $emails as $email ) {
					if ( wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ), array( $attach ) ) ) {
						$sent['emails']++;
					} else {
						$errors[] = sprintf( __( 'Falha ao enviar e-mail para %s.', 'hub-61labs' ), $email );
					}
				}
				@unlink( $attach ); // phpcs:ignore
			} else {
				$errors[] = __( 'Não foi possível preparar o anexo do e-mail.', 'hub-61labs' );
			}
		}

		$numbers   = (array) $recipients['whatsapp'];
		$numbers[] = Hub61_Evolution::OWNER_NUMBER;
		$seen      = array();
		foreach ( $numbers as $num ) {
			$norm = Hub61_Evolution::normalize_number( $num );
			if ( '' === $norm || isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = true;
			if ( ! Hub61_Evolution::is_configured() ) {
				$errors[] = __( 'Evolution API não configurada — WhatsApp não enviado.', 'hub-61labs' );
				break;
			}
			$r = Hub61_Evolution::send_document( $norm, $pdf, $filename, $subject );
			if ( is_wp_error( $r ) ) {
				$errors[] = sprintf( __( 'WhatsApp %1$s: %2$s', 'hub-61labs' ), $norm, $r->get_error_message() );
			} else {
				$sent['whatsapp']++;
			}
		}

		return array( 'sent' => $sent, 'errors' => $errors, 'trigger' => $trigger );
	}

	/* --------------------------------------------------------------------- */
	/* Cron                                                                  */
	/* --------------------------------------------------------------------- */

	public static function cron_schedules( $s ) {
		$s['hub61_weekly']  = array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Semanal (Hub 61 Labs)', 'hub-61labs' ) );
		$s['hub61_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => __( 'Mensal (Hub 61 Labs)', 'hub-61labs' ) );
		return $s;
	}

	public static function sync_schedule() {
		$sched = (string) get_option( self::OPT_SCHEDULE, 'off' );
		$ts    = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		if ( 'weekly' === $sched ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hub61_weekly', self::CRON_HOOK );
		} elseif ( 'monthly' === $sched ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hub61_monthly', self::CRON_HOOK );
		}
	}

	public static function cron_run() {
		self::send( self::get_template( self::active_id() ), self::optin_recipients(), 'cron' );
	}

	/* --------------------------------------------------------------------- */
	/* AJAX                                                                  */
	/* --------------------------------------------------------------------- */

	public static function ajax_save() {
		self::guard();
		update_option( Hub61_Evolution::OPT_URL, esc_url_raw( wp_unslash( $_POST['evo_url'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_KEY, sanitize_text_field( wp_unslash( $_POST['evo_key'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_INSTANCE, sanitize_text_field( wp_unslash( $_POST['evo_instance'] ?? '' ) ) );

		$sched = sanitize_key( wp_unslash( $_POST['schedule'] ?? 'off' ) );
		update_option( self::OPT_SCHEDULE, in_array( $sched, array( 'off', 'weekly', 'monthly' ), true ) ? $sched : 'off' );

		$uid = get_current_user_id();
		update_user_meta( $uid, self::META_OPTIN, empty( $_POST['optin'] ) ? '' : '1' );
		update_user_meta( $uid, self::META_EMAIL, sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		update_user_meta( $uid, self::META_WHATSAPP, preg_replace( '/[^\d+ ()-]/', '', (string) wp_unslash( $_POST['whatsapp'] ?? '' ) ) );

		self::sync_schedule();
		wp_send_json_success( array( 'message' => __( 'Configurações salvas.', 'hub-61labs' ) ) );
	}

	public static function ajax_tpl_save() {
		self::guard();
		$id  = isset( $_POST['tpl_id'] ) ? sanitize_key( wp_unslash( $_POST['tpl_id'] ) ) : '';
		$cfg = self::template_from_post();
		$all = self::templates();
		if ( '' === $id ) {
			$id = 'tpl_' . substr( md5( microtime() . wp_rand() ), 0, 8 );
		}
		$all[ $id ] = $cfg;
		update_option( self::OPT_TEMPLATES, $all );
		if ( ! empty( $_POST['make_active'] ) || 1 === count( $all ) ) {
			update_option( self::OPT_ACTIVE, $id );
		}
		wp_send_json_success( array(
			'id'      => $id,
			'active'  => self::active_id(),
			'list'    => self::tpl_choices(),
			'message' => __( 'Modelo salvo.', 'hub-61labs' ),
		) );
	}

	public static function ajax_tpl_get() {
		self::guard();
		$id  = isset( $_POST['tpl_id'] ) ? sanitize_key( wp_unslash( $_POST['tpl_id'] ) ) : '';
		wp_send_json_success( array( 'id' => $id, 'tpl' => self::get_template( $id ), 'active' => self::active_id() ) );
	}

	public static function ajax_tpl_delete() {
		self::guard();
		$id  = isset( $_POST['tpl_id'] ) ? sanitize_key( wp_unslash( $_POST['tpl_id'] ) ) : '';
		$all = self::templates();
		if ( isset( $all[ $id ] ) && count( $all ) > 1 ) {
			unset( $all[ $id ] );
			update_option( self::OPT_TEMPLATES, $all );
			if ( self::active_id() === $id ) {
				update_option( self::OPT_ACTIVE, (string) key( $all ) );
			}
		}
		wp_send_json_success( array( 'active' => self::active_id(), 'list' => self::tpl_choices(), 'message' => __( 'Modelo excluído.', 'hub-61labs' ) ) );
	}

	/** Lista {id,name} dos modelos para o seletor. */
	public static function tpl_choices() {
		$out = array();
		foreach ( self::templates() as $id => $cfg ) {
			$out[] = array( 'id' => $id, 'name' => isset( $cfg['name'] ) ? $cfg['name'] : $id );
		}
		return $out;
	}

	public static function ajax_preview_html() {
		self::guard();
		$c = self::compose( self::template_from_post() );
		wp_send_json_success( array( 'html' => $c['html'] ) );
	}

	/** Gera o PDF do modelo enviado no POST e devolve inline (para abrir no navegador). */
	public static function ajax_preview_pdf() {
		self::guard();
		$c   = self::compose( self::template_from_post() );
		$pdf = self::render_pdf( $c['html'] );
		if ( is_wp_error( $pdf ) ) {
			status_header( 500 );
			wp_die( esc_html( $pdf->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="preview-relatorio-61labs.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore
		exit;
	}

	public static function ajax_test_evolution() {
		self::guard();
		update_option( Hub61_Evolution::OPT_URL, esc_url_raw( wp_unslash( $_POST['evo_url'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_KEY, sanitize_text_field( wp_unslash( $_POST['evo_key'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_INSTANCE, sanitize_text_field( wp_unslash( $_POST['evo_instance'] ?? '' ) ) );
		$r = Hub61_Evolution::test_connection();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$open = ( isset( $r['state'] ) && 'open' === $r['state'] );
		wp_send_json_success( array(
			'state'   => isset( $r['state'] ) ? $r['state'] : '',
			'message' => $open
				? __( 'Conectado! A instância está online.', 'hub-61labs' )
				: sprintf( __( 'Instância respondeu, estado: %s.', 'hub-61labs' ), isset( $r['state'] ) ? $r['state'] : '?' ),
		) );
	}

	public static function ajax_send_now() {
		self::guard();
		$tpl   = self::template_from_post();
		$uid   = get_current_user_id();
		$email = get_user_meta( $uid, self::META_EMAIL, true );
		$email = $email ? $email : wp_get_current_user()->user_email;
		$wa    = get_user_meta( $uid, self::META_WHATSAPP, true );
		$res   = self::send( $tpl, array(
			'emails'   => $email ? array( $email ) : array(),
			'whatsapp' => $wa ? array( $wa ) : array(),
		), 'manual' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$msg = sprintf( __( 'Relatório enviado: %1$d e-mail(s), %2$d WhatsApp.', 'hub-61labs' ), $res['sent']['emails'], $res['sent']['whatsapp'] );
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . __( 'Avisos:', 'hub-61labs' ) . ' ' . implode( ' | ', $res['errors'] );
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'hub-61labs' ) ), 403 );
		}
		check_ajax_referer( 'hub61_report', 'nonce' );
	}

	/* --------------------------------------------------------------------- */
	/* Admin (página de Relatórios)                                          */
	/* --------------------------------------------------------------------- */

	public static function menu() {
		self::$page_hook = add_submenu_page(
			'hub-61labs',
			__( 'Relatórios', 'hub-61labs' ),
			__( 'Relatórios', 'hub-61labs' ),
			'manage_options',
			'hub-61labs-reports',
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( '' === self::$page_hook || $hook !== self::$page_hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'hub61-app', HUB61_URL . 'admin/css/app.css', array(), HUB61_VERSION );
		wp_enqueue_script( 'hub61-reports', HUB61_URL . 'admin/js/reports.js', array( 'jquery' ), HUB61_VERSION, true );
		wp_localize_script( 'hub61-reports', 'HUB61R', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'hub61_report' ),
			'i18n'  => array(
				'chooseLogo' => __( 'Selecionar logotipo', 'hub-61labs' ),
				'use'        => __( 'Usar este', 'hub-61labs' ),
			),
		) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$c        = Hub61_Evolution::config();
		$sched    = (string) get_option( self::OPT_SCHEDULE, 'off' );
		$uid      = get_current_user_id();
		$optin    = get_user_meta( $uid, self::META_OPTIN, true );
		$u_email  = get_user_meta( $uid, self::META_EMAIL, true );
		$u_email  = $u_email ? $u_email : wp_get_current_user()->user_email;
		$u_wa     = get_user_meta( $uid, self::META_WHATSAPP, true );

		$active   = self::active_id();
		$tpl      = self::get_template( $active );
		$choices  = self::tpl_choices();
		$catalog  = Hub61_Catalog::all();
		?>
		<div class="wrap hub61-wrap hub61-reports">
			<div class="hub61-header">
				<div class="hub61-brand"><div>
					<h1><?php esc_html_e( 'Relatórios de Performance', 'hub-61labs' ); ?></h1>
					<p><?php esc_html_e( 'Monte modelos de relatório, pré-visualize e envie por e-mail e WhatsApp.', 'hub-61labs' ); ?></p>
				</div></div>
			</div>
			<hr class="wp-header-end">
			<div id="hub61-report-msg" class="hub61-notice" hidden></div>

			<div class="hub61-builder" id="hub61-builder"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'hub61_report' ) ); ?>">

				<div class="hub61-builder-form">
					<section class="hub61-section">
						<div class="hub61-section-head"><h2><?php esc_html_e( 'Modelo', 'hub-61labs' ); ?></h2></div>
						<div class="hub61-panel">
							<p><label><?php esc_html_e( 'Modelo em edição', 'hub-61labs' ); ?><br>
								<select id="tpl_select">
									<?php foreach ( $choices as $ch ) : ?>
										<option value="<?php echo esc_attr( $ch['id'] ); ?>"<?php selected( $active, $ch['id'] ); ?>><?php echo esc_html( $ch['name'] ); ?><?php echo ( $active === $ch['id'] ) ? ' · ' . esc_html__( 'ativo p/ envio', 'hub-61labs' ) : ''; ?></option>
									<?php endforeach; ?>
								</select></label>
								<button type="button" class="hub61-btn hub61-btn-ghost" id="tpl_new"><?php esc_html_e( 'Novo', 'hub-61labs' ); ?></button>
								<button type="button" class="hub61-btn hub61-btn-ghost" id="tpl_delete"><?php esc_html_e( 'Excluir', 'hub-61labs' ); ?></button>
							</p>
							<input type="hidden" id="tpl_id" value="<?php echo esc_attr( $active ); ?>">
							<p><label><?php esc_html_e( 'Nome do modelo', 'hub-61labs' ); ?><br>
								<input type="text" id="tpl_name" class="regular-text" value="<?php echo esc_attr( $tpl['name'] ); ?>"></label></p>
							<p><label class="hub61-check"><input type="checkbox" id="make_active" <?php checked( true ); ?>> <?php esc_html_e( 'Usar este modelo nos envios e no agendamento', 'hub-61labs' ); ?></label></p>
						</div>
					</section>

					<section class="hub61-section">
						<div class="hub61-section-head"><h2><?php esc_html_e( 'Identidade visual', 'hub-61labs' ); ?></h2></div>
						<div class="hub61-panel">
							<p><label><?php esc_html_e( 'Logotipo', 'hub-61labs' ); ?></label><br>
								<span class="hub61-logo-prev"><?php if ( $tpl['logo'] ) : ?><img src="<?php echo esc_url( $tpl['logo'] ); ?>" alt=""><?php endif; ?></span><br>
								<input type="hidden" id="logo" value="<?php echo esc_attr( $tpl['logo'] ); ?>">
								<button type="button" class="hub61-btn hub61-btn-ghost" id="logo_pick"><?php esc_html_e( 'Selecionar logotipo', 'hub-61labs' ); ?></button>
								<button type="button" class="hub61-btn hub61-btn-ghost" id="logo_clear"><?php esc_html_e( 'Remover', 'hub-61labs' ); ?></button></p>
							<p><label><?php esc_html_e( 'Cor primária', 'hub-61labs' ); ?><br>
								<input type="color" id="color_primary" value="<?php echo esc_attr( $tpl['color_primary'] ); ?>"></label>
								&nbsp;&nbsp;<label><?php esc_html_e( 'Cor dos títulos', 'hub-61labs' ); ?><br>
								<input type="color" id="color_ink" value="<?php echo esc_attr( $tpl['color_ink'] ); ?>"></label></p>
							<p><label><?php esc_html_e( 'Texto de introdução', 'hub-61labs' ); ?><br>
								<textarea id="intro" rows="2" class="large-text"><?php echo esc_textarea( $tpl['intro'] ); ?></textarea></label></p>
							<p><label><?php esc_html_e( 'Rodapé', 'hub-61labs' ); ?><br>
								<input type="text" id="footer" class="regular-text" style="width:100%;max-width:520px" value="<?php echo esc_attr( $tpl['footer'] ); ?>"></label></p>
						</div>
					</section>

					<section class="hub61-section">
						<div class="hub61-section-head"><h2><?php esc_html_e( 'Conteúdo', 'hub-61labs' ); ?></h2></div>
						<div class="hub61-panel">
							<p><?php esc_html_e( 'Plugins no relatório:', 'hub-61labs' ); ?></p>
							<div class="hub61-report-plugins">
							<?php foreach ( $catalog as $it ) :
								$checked = in_array( $it['slug'], (array) $tpl['plugins'], true );
								$inst    = ( 'not-installed' !== Hub61_Installer::state( $it ) );
								?>
								<label class="hub61-report-plugin<?php echo $inst ? '' : ' is-off'; ?>">
									<input type="checkbox" class="tpl-plugin" value="<?php echo esc_attr( $it['slug'] ); ?>"<?php checked( $checked ); ?>>
									<?php echo esc_html( $it['name'] ); ?><?php echo $inst ? '' : ' — ' . esc_html__( 'não instalado', 'hub-61labs' ); ?>
								</label>
							<?php endforeach; ?>
							</div>
							<p style="margin-top:12px">
								<label class="hub61-check"><input type="checkbox" id="show_kpis" <?php checked( ! empty( $tpl['show_kpis'] ) ); ?>> <?php esc_html_e( 'Indicadores (KPIs + comparação)', 'hub-61labs' ); ?></label>&nbsp;&nbsp;
								<label class="hub61-check"><input type="checkbox" id="show_charts" <?php checked( ! empty( $tpl['show_charts'] ) ); ?>> <?php esc_html_e( 'Gráficos', 'hub-61labs' ); ?></label>&nbsp;&nbsp;
								<label class="hub61-check"><input type="checkbox" id="show_tables" <?php checked( ! empty( $tpl['show_tables'] ) ); ?>> <?php esc_html_e( 'Tabelas', 'hub-61labs' ); ?></label>
							</p>
							<p><label><?php esc_html_e( 'Período dos dados:', 'hub-61labs' ); ?>
								<select id="range">
									<?php foreach ( array( '7d' => '7 dias', '28d' => '28 dias', '90d' => '90 dias', '365d' => '365 dias' ) as $k => $lbl ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $tpl['range'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
									<?php endforeach; ?>
								</select></label></p>
						</div>
					</section>

					<section class="hub61-section">
						<div class="hub61-section-head"><h2><?php esc_html_e( 'Conexão WhatsApp (Evolution API)', 'hub-61labs' ); ?></h2></div>
						<div class="hub61-panel">
							<p><label><?php esc_html_e( 'URL da instância', 'hub-61labs' ); ?><br>
								<input type="url" id="evo_url" style="width:100%;max-width:520px" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://evo.seudominio.com"></label></p>
							<p><label><?php esc_html_e( 'API key', 'hub-61labs' ); ?><br>
								<input type="text" id="evo_key" style="width:100%;max-width:520px" value="<?php echo esc_attr( $c['key'] ); ?>" autocomplete="off"></label></p>
							<p><label><?php esc_html_e( 'Instância', 'hub-61labs' ); ?><br>
								<input type="text" id="evo_instance" value="<?php echo esc_attr( $c['instance'] ); ?>" placeholder="minha-instancia"></label></p>
							<p><button type="button" class="hub61-btn hub61-btn-ghost" id="hub61-evo-test"><?php esc_html_e( 'Testar conexão', 'hub-61labs' ); ?></button>
								<span id="hub61-evo-status" class="hub61-ver"></span></p>
							<p class="description"><?php printf( esc_html__( 'Cópia sempre enviada ao WhatsApp do administrador da 61 Labs (%s).', 'hub-61labs' ), esc_html( Hub61_Evolution::OWNER_NUMBER ) ); ?></p>
						</div>
					</section>

					<section class="hub61-section">
						<div class="hub61-section-head"><h2><?php esc_html_e( 'Meu recebimento e agendamento', 'hub-61labs' ); ?></h2></div>
						<div class="hub61-panel">
							<p><label class="hub61-check"><input type="checkbox" id="optin" <?php checked( '1', $optin ); ?>> <?php esc_html_e( 'Quero receber o relatório deste site.', 'hub-61labs' ); ?></label></p>
							<p><label><?php esc_html_e( 'Meu e-mail', 'hub-61labs' ); ?><br><input type="email" id="email" class="regular-text" value="<?php echo esc_attr( $u_email ); ?>"></label></p>
							<p><label><?php esc_html_e( 'Meu WhatsApp (com DDI/DDD)', 'hub-61labs' ); ?><br><input type="text" id="whatsapp" class="regular-text" value="<?php echo esc_attr( $u_wa ); ?>" placeholder="+55 61 99999-9999"></label></p>
							<p><label><?php esc_html_e( 'Agendamento automático:', 'hub-61labs' ); ?>
								<select id="schedule">
									<option value="off"<?php selected( $sched, 'off' ); ?>><?php esc_html_e( 'Desligado', 'hub-61labs' ); ?></option>
									<option value="weekly"<?php selected( $sched, 'weekly' ); ?>><?php esc_html_e( 'Semanal', 'hub-61labs' ); ?></option>
									<option value="monthly"<?php selected( $sched, 'monthly' ); ?>><?php esc_html_e( 'Mensal', 'hub-61labs' ); ?></option>
								</select></label></p>
						</div>
					</section>

					<p class="hub61-report-actions">
						<button type="button" class="hub61-btn hub61-btn-primary" id="tpl_savebtn"><?php esc_html_e( 'Salvar modelo', 'hub-61labs' ); ?></button>
						<button type="button" class="hub61-btn hub61-btn-ghost" id="cfg_save"><?php esc_html_e( 'Salvar conexão/agendamento', 'hub-61labs' ); ?></button>
						<button type="button" class="hub61-btn hub61-btn-ghost" id="preview_pdf"><?php esc_html_e( 'Ver PDF', 'hub-61labs' ); ?></button>
						<button type="button" class="hub61-btn hub61-btn-signal" id="send_now"><?php esc_html_e( 'Enviar agora', 'hub-61labs' ); ?></button>
					</p>
				</div>

				<div class="hub61-builder-preview">
					<div class="hub61-preview-head"><?php esc_html_e( 'Pré-visualização', 'hub-61labs' ); ?> <span id="preview_spin" class="hub61-ver"></span></div>
					<iframe id="preview_frame" title="preview"></iframe>
				</div>
			</div>
		</div>
		<?php
	}
}
