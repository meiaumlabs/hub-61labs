=== Hub 61 Labs ===
Contributors: 61labs
Tags: 61labs, plugins, hub, instalador, marketplace
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Central de plugins da 61 Labs. Descubra, instale e ative todas as ferramentas da 61 Labs em um só lugar, envie ideias e fale com o suporte — direto do painel do WordPress.

== Description ==

O **Hub 61 Labs** é o plugin principal do ecossistema 61 Labs. A partir dele você:

* Vê **todas as ferramentas da 61 Labs** numa grade organizada por categoria.
* **Instala e ativa** cada plugin com um clique — sem sair do painel, sem baixar ZIP manualmente.
* **Envia ideias** do que falta no seu WordPress para virarem os próximos plugins.
* **Fala com o suporte** da 61 Labs por um botão direto.

Cada plugin é baixado da sua última versão oficial (Release do GitHub, org meiaumlabs) e, depois de instalado, mantém a própria atualização automática dentro do WordPress.

Ferramentas atuais no catálogo:

* **Cluster Engine** — Autoridade tópica, clusters de conteúdo e linkagem interna com IA.
* **Orbit Track** — Tracking orgânico e de anúncios, independente e self-hosted.
* **Digital Metrics** — Métricas de YouTube e Instagram no painel.
* **SmartLink QR** — QR Codes, encurtador e analytics de cliques.
* **Author SEO** — Bloco de autor e Schema.org para Elementor.
* **Diário da Clínica** — Fechamento diário da recepção com relatórios.

== Installation ==

1. Faça upload da pasta `hub-61labs` para `/wp-content/plugins/`, ou instale o ZIP pelo painel em Plugins > Adicionar novo > Enviar plugin.
2. Ative o **Hub 61 Labs** pelo menu Plugins.
3. Acesse **Hub 61 Labs** no menu lateral do painel.

== Frequently Asked Questions ==

= De onde vêm os plugins? =
Das Releases públicas oficiais da 61 Labs no GitHub (org meiaumlabs). O Hub usa o próprio mecanismo de instalação do WordPress.

= Desinstalar o Hub remove os outros plugins? =
Não. Os plugins instalados pelo Hub são independentes e continuam funcionando normalmente.

= Preciso de um administrador? =
Instalar e ativar plugins exige a permissão `install_plugins` (normalmente administradores).

== Changelog ==

= 1.4.0 =
* Segurança contra quebra do site ao instalar/atualizar Extra Plugins:
  * **Pré-checagem** de `Requires PHP` e `Requires at least` (WordPress) antes de tocar no disco — bloqueia com mensagem clara se o site não atende.
  * **Backup + rollback automático** no update: se a nova versão gerar erro crítico, o Hub reverte para a versão anterior sozinho.
  * **Verificação de saúde do site** (loopback) após instalar/atualizar; no install, desativa e remove a versão que quebrar.
  * **Aviso de downgrade** no seletor de versão (confirmação antes de baixar de versão).
* Catálogo de Extra Plugins ampliado no repositório curado.

= 1.3.0 =
* Nova seção **Extra Plugins**: plugins extras (ex.: Elementor, Elementor Pro, JetEngine, Rank Math) liberados por acesso, lidos do repositório curado meiaumlabs/extra-plugins.
* **Seletor de versão** por plugin: escolha qual versão instalar ou atualizar (inclui downgrade).
* Aviso de "Atualização disponível" também para os Extra Plugins instalados.
* Base para acesso restrito por chave (token read-only) já embutida — dormant enquanto o repositório é público.

= 1.1.0 =
* Cada card agora mostra a versão instalada e a última versão publicada.
* Aviso de "Atualização disponível" com botão para atualizar o plugin direto do Hub.
* Correção: os avisos do WordPress não aparecem mais dentro do cabeçalho escuro (marcador wp-header-end).

= 1.0.0 =
* Lançamento inicial: grade de plugins, instalação/ativação com um clique, envio de ideia e contato com o suporte.
