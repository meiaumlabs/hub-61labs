<?php
/**
 * Hub61_Catalog — catálogo dos plugins da 61 Labs.
 *
 * Cada item descreve um plugin publicado pela 61 Labs no GitHub (org meiaumlabs).
 * O campo `plugin_file` é o basename (pasta/arquivo.php) usado para detectar se o
 * plugin já está instalado/ativo. O campo `repo` (owner/repo) é usado pelo
 * instalador para buscar o ZIP da última Release via API pública do GitHub.
 *
 * @package Hub61
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub61_Catalog {

	/**
	 * Retorna a lista de plugins da 61 Labs.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function all() {
		return array(
			array(
				'slug'        => 'cluster-engine',
				'name'        => 'Cluster Engine',
				'tagline'     => 'Autoridade Tópica & Linkagem Interna',
				'description' => 'Motor de autoridade tópica: mapeia clusters de conteúdo e palavras-chave, diagnostica SEO/AEO/GEO, sugere e corrige linkagem interna e reescreve metadados com IA.',
				'category'    => 'SEO & Conteúdo',
				'icon'        => 'cluster',
				'repo'        => 'meiaumlabs/cluster-engine',
				'plugin_file' => 'cluster-engine-61labs/cluster-engine.php',
				'admin_url'   => 'admin.php?page=cluster-engine',
			),
			array(
				'slug'        => 'orbit-track',
				'name'        => 'Orbit Track',
				'tagline'     => 'Tracking Orgânico & de Anúncios',
				'description' => 'Tracking profissional e independente: origem dos acessos, páginas, tempo de permanência, região, dispositivo e navegador — direto no painel, sem serviços externos.',
				'category'    => 'Analytics',
				'icon'        => 'orbit',
				'repo'        => 'meiaumlabs/orbit-track',
				'plugin_file' => 'orbit-track/orbit-track.php',
				'admin_url'   => 'admin.php?page=orbit-track',
			),
			array(
				'slug'        => 'digital-metrics',
				'name'        => 'Digital Metrics',
				'tagline'     => 'Métricas das Redes Sociais',
				'description' => 'Métricas das suas redes sociais no painel do WordPress: YouTube (Google OAuth) e Instagram (Meta), com arquitetura pronta para LinkedIn e TikTok.',
				'category'    => 'Analytics',
				'icon'        => 'metrics',
				'repo'        => 'meiaumlabs/digital-metrics',
				'plugin_file' => 'digital-metrics/digital-metrics.php',
				'admin_url'   => 'admin.php?page=digital-metrics',
			),
			array(
				'slug'        => 'smartlink-qr',
				'name'        => 'SmartLink QR',
				'tagline'     => 'QR Codes, Encurtador & Cliques',
				'description' => 'Gerador de QR Code, encurtador de links, analytics de cliques e passthrough de UTM — com painel administrativo em React e app público.',
				'category'    => 'Links & Marketing',
				'icon'        => 'qr',
				'repo'        => 'meiaumlabs/smartlink-qr',
				'plugin_file' => 'smartlink-qr/smartlink-qr.php',
				'admin_url'   => 'admin.php?page=smartlink-qr',
			),
			array(
				'slug'        => 'author-seo-elementor',
				'name'        => 'Author SEO',
				'tagline'     => 'Bloco de Autor & Schema p/ Elementor',
				'description' => 'Addon de SEO para Elementor Pro focado em autoria: widget de bloco de autor estilizável, Schema.org Person (JSON-LD) e gestão de perfis de autor com sameAs.',
				'category'    => 'SEO & Conteúdo',
				'icon'        => 'author',
				'repo'        => 'meiaumlabs/author-seo-elementor',
				'plugin_file' => 'author-seo-elementor/author-seo-elementor.php',
				'admin_url'   => 'admin.php?page=author-seo',
			),
			array(
				'slug'        => 'diario-da-clinica',
				'name'        => 'Diário da Clínica',
				'tagline'     => 'Fechamento Diário da Recepção',
				'description' => 'Recebe e interpreta o relatório diário de fechamento da recepção, armazena de forma estruturada e gera relatórios consolidados com gráficos e exportação.',
				'category'    => 'Gestão',
				'icon'        => 'clinic',
				'repo'        => 'meiaumlabs/diario-da-clinica',
				'plugin_file' => 'diario-da-clinica/diario-da-clinica.php',
				'admin_url'   => 'admin.php?page=diario-da-clinica',
			),
		);
	}

	/**
	 * Busca um item do catálogo pelo slug.
	 *
	 * @param string $slug Slug do plugin.
	 * @return array<string,string>|null
	 */
	public static function get( $slug ) {
		foreach ( self::all() as $item ) {
			if ( $item['slug'] === $slug ) {
				return $item;
			}
		}
		return null;
	}
}
