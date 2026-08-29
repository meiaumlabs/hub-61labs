<?php
/**
 * Hub61_Report — relatório de performance dos plugins 61 Labs.
 *
 * Reúne seções fornecidas por cada plugin (filtro `hub61_report_sections`), gera um PDF
 * (Dompdf embutido) e envia por e-mail e por WhatsApp (Evolution API) conforme a
 * configuração de cada usuário. O número do dono ({@see Hub61_Evolution::OWNER_NUMBER})
 * sempre recebe uma cópia via WhatsApp.
 *
 * Fase 1: cada site relata a si mesmo. A seleção remota de sites (console central) fica
 * para a fase 2.
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Report {

	const OPT_PLUGINS  = 'hub61_report_plugins';   // array<string> slugs incluídos
	const OPT_SCHEDULE = 'hub61_report_schedule';  // off | weekly | monthly
	const OPT_RANGE    = 'hub61_report_range';     // 7d | 28d | 90d | 365d

	const META_OPTIN    = 'hub61_report_optin';    // '1' | ''
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

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Configuração                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Slugs de plugins selecionados para o relatório (default: todos do catálogo).
	 *
	 * @return array<int,string>
	 */
	public static function selected_slugs() {
		$saved = get_option( self::OPT_PLUGINS, null );
		if ( is_array( $saved ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $saved ) ) );
		}
		return wp_list_pluck( Hub61_Catalog::all(), 'slug' );
	}

	/**
	 * Itens (catálogo + extra) correspondentes aos slugs selecionados.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function selected_items() {
		$slugs = self::selected_slugs();
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

	/** Range configurado (default 28d). */
	public static function range() {
		$r = (string) get_option( self::OPT_RANGE, '28d' );
		return in_array( $r, array( '7d', '28d', '90d', '365d' ), true ) ? $r : '28d';
	}

	/* --------------------------------------------------------------------- */
	/* Coleta + PDF                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Contexto do relatório (período).
	 *
	 * @return array{range:string,from:string,to:string}
	 */
	public static function context() {
		$range = self::range();
		$days  = array( '7d' => 7, '28d' => 28, '90d' => 90, '365d' => 365 );
		$to    = current_time( 'Y-m-d' );
		$from  = gmdate( 'Y-m-d', strtotime( $to . ' -' . ( isset( $days[ $range ] ) ? $days[ $range ] : 28 ) . ' days' ) );
		return array( 'range' => $range, 'from' => $from, 'to' => $to );
	}

	/**
	 * Reúne as seções do relatório (filtro + fallback básico por plugin instalado).
	 *
	 * @param array $ctx Contexto {range,from,to}.
	 * @return array<int,array<string,mixed>>
	 */
	public static function gather( $ctx ) {
		/**
		 * Cada plugin 61 Labs adiciona sua seção:
		 *   ['slug','name','metrics'=>[['label','value']],'tables'=>[['title','columns'=>[],'rows'=>[[]]]]]
		 */
		$sections = apply_filters( 'hub61_report_sections', array(), $ctx );
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}
		$provided = array();
		foreach ( $sections as $s ) {
			if ( isset( $s['slug'] ) ) {
				$provided[] = $s['slug'];
			}
		}

		foreach ( self::selected_items() as $item ) {
			if ( in_array( $item['slug'], $provided, true ) ) {
				continue;
			}
			$state = Hub61_Installer::state( $item );
			if ( 'not-installed' === $state ) {
				continue; // não relata plugin que não está no site
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
	 * Monta o HTML do relatório (usado pelo Dompdf).
	 *
	 * @param array $sections Seções.
	 * @param array $meta     Metadados do site/período.
	 * @return string
	 */
	public static function build_html( $sections, $meta ) {
		$esc = 'esc_html';
		ob_start();
		?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>
	* { font-family: 'DejaVu Sans', sans-serif; }
	body { color: #1e2530; font-size: 12px; }
	.head { border-bottom: 3px solid #16a34a; padding-bottom: 10px; margin-bottom: 18px; }
	.head h1 { font-size: 20px; margin: 0 0 4px; color: #0f172a; }
	.head .sub { font-size: 11px; color: #64748b; }
	h2 { font-size: 15px; margin: 22px 0 8px; color: #0f172a; border-left: 4px solid #16a34a; padding-left: 8px; }
	.kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 8px; }
	.kpis td { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; width: 16%; vertical-align: top; }
	.kpis .l { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
	.kpis .v { font-size: 16px; font-weight: bold; color: #0f172a; }
	table.data { width: 100%; border-collapse: collapse; margin: 6px 0 4px; }
	table.data th { background: #0f172a; color: #fff; font-size: 10px; text-align: left; padding: 6px 8px; }
	table.data td { border-bottom: 1px solid #e2e8f0; padding: 5px 8px; font-size: 11px; }
	.tt { font-size: 12px; font-weight: bold; margin: 12px 0 2px; color: #334155; }
	.foot { margin-top: 26px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #94a3b8; }
	.empty { color: #94a3b8; font-size: 11px; }
</style></head><body>
	<div class="head">
		<h1><?php echo $esc( __( 'Relatório de Performance — 61 Labs', 'hub-61labs' ) ); ?></h1>
		<div class="sub">
			<?php echo $esc( $meta['site'] ); ?> &middot; <?php echo $esc( $meta['url'] ); ?><br>
			<?php printf( esc_html__( 'Período: %1$s (%2$s a %3$s) · Gerado em %4$s', 'hub-61labs' ),
				esc_html( $meta['range'] ), esc_html( $meta['from'] ), esc_html( $meta['to'] ), esc_html( $meta['date'] ) ); ?>
		</div>
	</div>
		<?php if ( empty( $sections ) ) : ?>
		<p class="empty"><?php echo $esc( __( 'Nenhum plugin selecionado/instalado com dados para relatar.', 'hub-61labs' ) ); ?></p>
		<?php endif; ?>
		<?php foreach ( $sections as $s ) : ?>
		<h2><?php echo $esc( isset( $s['name'] ) ? $s['name'] : '' ); ?></h2>
			<?php if ( ! empty( $s['metrics'] ) && is_array( $s['metrics'] ) ) : ?>
			<table class="kpis"><tr>
				<?php foreach ( array_slice( $s['metrics'], 0, 6 ) as $m ) : ?>
				<td><div class="l"><?php echo $esc( isset( $m['label'] ) ? $m['label'] : '' ); ?></div><div class="v"><?php echo $esc( isset( $m['value'] ) ? $m['value'] : '' ); ?></div></td>
				<?php endforeach; ?>
			</tr></table>
			<?php endif; ?>
			<?php if ( ! empty( $s['tables'] ) && is_array( $s['tables'] ) ) : ?>
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
	<div class="foot"><?php echo $esc( __( '© 61 Labs — os dados são seus. Relatório gerado automaticamente pelo Hub 61 Labs.', 'hub-61labs' ) ); ?></div>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renderiza o HTML em PDF (binário) usando o Dompdf embutido.
	 *
	 * @param string $html HTML.
	 * @return string|WP_Error Binário do PDF ou erro.
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
	 * Gera e envia o relatório.
	 *
	 * @param array $recipients ['emails'=>[], 'whatsapp'=>[]].
	 * @param string $trigger   'manual' | 'cron'.
	 * @return array|WP_Error Resumo do envio.
	 */
	public static function send( $recipients, $trigger = 'manual' ) {
		$ctx  = self::context();
		$meta = array(
			'site'  => get_bloginfo( 'name' ),
			'url'   => home_url(),
			'range' => $ctx['range'],
			'from'  => $ctx['from'],
			'to'    => $ctx['to'],
			'date'  => date_i18n( 'd/m/Y H:i' ),
		);
		$html = self::build_html( self::gather( $ctx ), $meta );
		$pdf  = self::render_pdf( $html );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}

		$filename = 'relatorio-61labs-' . sanitize_title( $meta['site'] ) . '-' . gmdate( 'Ymd-Hi' ) . '.pdf';
		$subject  = sprintf( __( 'Relatório de performance — %s', 'hub-61labs' ), $meta['site'] );
		$body     = sprintf(
			/* translators: 1: site, 2: período, 3: data */
			__( "Segue em anexo o relatório de performance dos plugins 61 Labs.\n\nSite: %1\$s\nPeríodo: %2\$s\nGerado em: %3\$s", 'hub-61labs' ),
			$meta['site'], $meta['range'], $meta['date']
		);

		$sent   = array( 'emails' => 0, 'whatsapp' => 0 );
		$errors = array();

		// E-mail (com anexo em arquivo temporário).
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

		// WhatsApp (destinatários + sempre o dono).
		$numbers = (array) $recipients['whatsapp'];
		$numbers[] = Hub61_Evolution::OWNER_NUMBER;
		$seen = array();
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

	/** (Re)agenda o cron conforme a opção de schedule. */
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
		self::send( self::optin_recipients(), 'cron' );
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
		wp_enqueue_style( 'hub61-app', HUB61_URL . 'admin/css/app.css', array(), HUB61_VERSION );
		wp_enqueue_script( 'hub61-reports', HUB61_URL . 'admin/js/reports.js', array(), HUB61_VERSION, true );
		wp_localize_script( 'hub61-reports', 'HUB61R', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'hub61_report' ),
		) );
	}

	public static function ajax_save() {
		self::guard();
		// Evolution (global).
		update_option( Hub61_Evolution::OPT_URL, esc_url_raw( wp_unslash( $_POST['evo_url'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_KEY, sanitize_text_field( wp_unslash( $_POST['evo_key'] ?? '' ) ) );
		update_option( Hub61_Evolution::OPT_INSTANCE, sanitize_text_field( wp_unslash( $_POST['evo_instance'] ?? '' ) ) );

		// Plugins + schedule + range (global).
		$plugins = isset( $_POST['plugins'] ) ? (array) wp_unslash( $_POST['plugins'] ) : array();
		update_option( self::OPT_PLUGINS, array_values( array_filter( array_map( 'sanitize_key', $plugins ) ) ) );

		$sched = sanitize_key( wp_unslash( $_POST['schedule'] ?? 'off' ) );
		update_option( self::OPT_SCHEDULE, in_array( $sched, array( 'off', 'weekly', 'monthly' ), true ) ? $sched : 'off' );

		$range = sanitize_key( wp_unslash( $_POST['range'] ?? '28d' ) );
		update_option( self::OPT_RANGE, in_array( $range, array( '7d', '28d', '90d', '365d' ), true ) ? $range : '28d' );

		// Preferências do usuário atual.
		$uid = get_current_user_id();
		update_user_meta( $uid, self::META_OPTIN, empty( $_POST['optin'] ) ? '' : '1' );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		update_user_meta( $uid, self::META_EMAIL, $email );
		update_user_meta( $uid, self::META_WHATSAPP, preg_replace( '/[^\d+ ()-]/', '', (string) wp_unslash( $_POST['whatsapp'] ?? '' ) ) );

		self::sync_schedule();
		wp_send_json_success( array( 'message' => __( 'Configurações salvas.', 'hub-61labs' ) ) );
	}

	public static function ajax_test_evolution() {
		self::guard();
		// Salva antes de testar, para usar os valores digitados.
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
				: sprintf( __( 'Instância respondeu, estado: %s. Conecte o WhatsApp na Evolution se necessário.', 'hub-61labs' ), isset( $r['state'] ) ? $r['state'] : '?' ),
		) );
	}

	public static function ajax_send_now() {
		self::guard();
		$uid   = get_current_user_id();
		$email = get_user_meta( $uid, self::META_EMAIL, true );
		$email = $email ? $email : wp_get_current_user()->user_email;
		$wa    = get_user_meta( $uid, self::META_WHATSAPP, true );
		$res   = self::send( array(
			'emails'   => $email ? array( $email ) : array(),
			'whatsapp' => $wa ? array( $wa ) : array(),
		), 'manual' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$msg = sprintf(
			__( 'Relatório enviado: %1$d e-mail(s), %2$d WhatsApp.', 'hub-61labs' ),
			$res['sent']['emails'], $res['sent']['whatsapp']
		);
		if ( ! empty( $res['errors'] ) ) {
			$msg .= ' ' . __( 'Avisos:', 'hub-61labs' ) . ' ' . implode( ' | ', $res['errors'] );
		}
		wp_send_json_success( array( 'message' => $msg, 'sent' => $res['sent'] ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'hub-61labs' ) ), 403 );
		}
		check_ajax_referer( 'hub61_report', 'nonce' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$c        = Hub61_Evolution::config();
		$sched    = (string) get_option( self::OPT_SCHEDULE, 'off' );
		$range    = self::range();
		$selected = self::selected_slugs();
		$uid      = get_current_user_id();
		$optin    = get_user_meta( $uid, self::META_OPTIN, true );
		$u_email  = get_user_meta( $uid, self::META_EMAIL, true );
		$u_email  = $u_email ? $u_email : wp_get_current_user()->user_email;
		$u_wa     = get_user_meta( $uid, self::META_WHATSAPP, true );

		// Relatório cobre as ferramentas 61 Labs (catálogo), não os extras de terceiros.
		$list = Hub61_Catalog::all();
		?>
		<div class="wrap hub61-wrap hub61-reports">
			<div class="hub61-header">
				<div class="hub61-brand">
					<div>
						<h1><?php esc_html_e( 'Relatórios de Performance', 'hub-61labs' ); ?></h1>
						<p><?php esc_html_e( 'Gere e envie um PDF com a performance dos plugins 61 Labs por e-mail e WhatsApp.', 'hub-61labs' ); ?></p>
					</div>
				</div>
			</div>
			<hr class="wp-header-end">

			<div id="hub61-report-msg" class="hub61-notice" hidden></div>

			<form id="hub61-report-form">
			<section class="hub61-section">
				<div class="hub61-section-head"><h2><?php esc_html_e( 'Conexão WhatsApp (Evolution API)', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Dados da sua instância da Evolution API para envio via WhatsApp.', 'hub-61labs' ); ?></p></div>
				<div class="hub61-panel">
					<p><label><?php esc_html_e( 'URL da instância', 'hub-61labs' ); ?><br>
						<input type="url" id="evo_url" class="regular-text" style="width:100%;max-width:520px" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://evo.seudominio.com"></label></p>
					<p><label><?php esc_html_e( 'API key', 'hub-61labs' ); ?><br>
						<input type="text" id="evo_key" class="regular-text" style="width:100%;max-width:520px" value="<?php echo esc_attr( $c['key'] ); ?>" autocomplete="off"></label></p>
					<p><label><?php esc_html_e( 'Instância', 'hub-61labs' ); ?><br>
						<input type="text" id="evo_instance" class="regular-text" value="<?php echo esc_attr( $c['instance'] ); ?>" placeholder="minha-instancia"></label></p>
					<p><button type="button" class="hub61-btn hub61-btn-ghost" id="hub61-evo-test"><?php esc_html_e( 'Testar conexão', 'hub-61labs' ); ?></button>
						<span id="hub61-evo-status" class="hub61-ver"></span></p>
					<p class="description"><?php printf( esc_html__( 'Uma cópia de todo relatório é sempre enviada para o WhatsApp do administrador da 61 Labs (%s).', 'hub-61labs' ), esc_html( Hub61_Evolution::OWNER_NUMBER ) ); ?></p>
				</div>
			</section>

			<section class="hub61-section">
				<div class="hub61-section-head"><h2><?php esc_html_e( 'Plugins no relatório', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Marque os plugins cujos dados entram no PDF (somente instalados são relatados).', 'hub-61labs' ); ?></p></div>
				<div class="hub61-panel">
					<div class="hub61-report-plugins">
					<?php foreach ( $list as $it ) :
						$checked = in_array( $it['slug'], $selected, true );
						$inst    = ( 'not-installed' !== Hub61_Installer::state( $it ) );
						?>
						<label class="hub61-report-plugin<?php echo $inst ? '' : ' is-off'; ?>">
							<input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $it['slug'] ); ?>"<?php checked( $checked ); ?>>
							<?php echo esc_html( $it['name'] ); ?>
							<?php echo $inst ? '' : '<em style="color:#94a3b8"> — ' . esc_html__( 'não instalado', 'hub-61labs' ) . '</em>'; ?>
						</label>
					<?php endforeach; ?>
					</div>
					<p style="margin-top:12px"><label><?php esc_html_e( 'Período dos dados:', 'hub-61labs' ); ?>
						<select id="hub61-range">
							<?php foreach ( array( '7d' => '7 dias', '28d' => '28 dias', '90d' => '90 dias', '365d' => '365 dias' ) as $k => $lbl ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $range, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
				</div>
			</section>

			<section class="hub61-section">
				<div class="hub61-section-head"><h2><?php esc_html_e( 'Envio e agendamento', 'hub-61labs' ); ?></h2>
					<p><?php esc_html_e( 'Onde e quando você quer receber o relatório deste site.', 'hub-61labs' ); ?></p></div>
				<div class="hub61-panel">
					<p><label><input type="checkbox" id="optin" <?php checked( '1', $optin ); ?>> <?php esc_html_e( 'Quero receber o relatório deste site.', 'hub-61labs' ); ?></label></p>
					<p><label><?php esc_html_e( 'Meu e-mail', 'hub-61labs' ); ?><br>
						<input type="email" id="email" class="regular-text" value="<?php echo esc_attr( $u_email ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Meu WhatsApp (com DDI/DDD)', 'hub-61labs' ); ?><br>
						<input type="text" id="whatsapp" class="regular-text" value="<?php echo esc_attr( $u_wa ); ?>" placeholder="+55 61 99999-9999"></label></p>
					<p><label><?php esc_html_e( 'Agendamento automático:', 'hub-61labs' ); ?>
						<select id="schedule">
							<option value="off"<?php selected( $sched, 'off' ); ?>><?php esc_html_e( 'Desligado', 'hub-61labs' ); ?></option>
							<option value="weekly"<?php selected( $sched, 'weekly' ); ?>><?php esc_html_e( 'Semanal', 'hub-61labs' ); ?></option>
							<option value="monthly"<?php selected( $sched, 'monthly' ); ?>><?php esc_html_e( 'Mensal', 'hub-61labs' ); ?></option>
						</select></label></p>
				</div>
			</section>

			<p class="hub61-report-actions">
				<button type="button" class="hub61-btn hub61-btn-primary" id="hub61-save"><?php esc_html_e( 'Salvar configurações', 'hub-61labs' ); ?></button>
				<button type="button" class="hub61-btn hub61-btn-signal" id="hub61-send-now"><?php esc_html_e( 'Enviar relatório agora', 'hub-61labs' ); ?></button>
			</p>
			</form>
		</div>
		<?php
	}
}
