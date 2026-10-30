-- Schema do banco de dados do wedding-confirm
-- MySQL / MariaDB, InnoDB, utf8mb4
--
-- Este arquivo reflete o estado final (multi-evento) do banco, para setups
-- locais novos. Para levar um banco de produção existente (pré-multi-evento)
-- até este mesmo estado, use as migrações em sql/migrations/ em ordem
-- (001 a 009) em vez de reaplicar este arquivo.

CREATE TABLE IF NOT EXISTS events (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(180) NOT NULL,
  event_type            ENUM('wedding','birthday','graduation','other') NOT NULL DEFAULT 'wedding',

  -- marca / dados do convite (nomes, data, local, chave Pix etc), preenchidos
  -- pelo admin do evento em vez de fixos no código. host_name_secondary e
  -- venue_name_secondary ficam NULL em eventos de um anfitrião só (ex: aniversário).
  host_name             VARCHAR(150) NOT NULL,
  host_name_secondary   VARCHAR(150) NULL,
  -- linha opcional abaixo dos nomes ("Medicina · UFMG", "30 anos"); no tipo
  -- 'other' faz as vezes do nome do evento ("Chá de bebê do Theo").
  subtitle              VARCHAR(150) NULL,
  event_date            DATETIME NULL,
  venue_name            VARCHAR(190) NULL,
  venue_name_secondary  VARCHAR(190) NULL,
  address               VARCHAR(255) NULL,
  maps_url              VARCHAR(500) NULL,
  -- link do mapa do local da festa (venue_name_secondary), quando ele não
  -- fica no mesmo endereço da cerimônia.
  maps_url_secondary    VARCHAR(500) NULL,
  dress_code            VARCHAR(150) NULL,
  -- mensagem livre dos noivos exibida na tela interna do convite.
  invite_message        VARCHAR(1000) NULL,
  -- modelo da mensagem que o admin copia para enviar o convite, com
  -- variáveis ({primeiro_nome}, {link}...) trocadas no front. NULL = padrão.
  share_message         VARCHAR(1000) NULL,
  pix_key               VARCHAR(190) NULL,
  logo_path             VARCHAR(255) NULL,
  -- foto do casal (capa do convite) e música tocada ao abrir o envelope,
  -- no mesmo formato de logo_path ("uploads/...").
  cover_photo_path      VARCHAR(255) NULL,
  -- ponto de foco da capa, em % da foto (50/50 = centro): o convite recorta
  -- em volta dele.
  cover_focus_x         TINYINT UNSIGNED NOT NULL DEFAULT 50,
  cover_focus_y         TINYINT UNSIGNED NOT NULL DEFAULT 50,
  music_path            VARCHAR(255) NULL,
  color_primary        CHAR(7) NOT NULL DEFAULT '#d2afff',
  -- fonte usada no nome dos noivos/aniversariante na página do convite:
  -- 'sans' (padrão do site) ou uma das fontes de caligrafia disponíveis.
  name_font             ENUM('sans','fleur','pinyon') NOT NULL DEFAULT 'sans',
  -- abertura do convite: 'direct' (direto no conteúdo), 'letter' (envelope
  -- com aba), 'doors'/'vertical' (abertura central, para os lados ou para
  -- cima e para baixo). Escolhida pelo admin do evento nas configurações.
  invite_style          ENUM('direct','letter','doors','vertical') NOT NULL DEFAULT 'letter',
  -- seções opcionais do convite (contagem regressiva, "Salve a data").
  show_countdown        TINYINT(1) NOT NULL DEFAULT 1,
  show_calendar         TINYINT(1) NOT NULL DEFAULT 1,

  -- plano do evento: 'standard' (Padrão), 'premium' (Premium) ou
  -- 'unlimited' (Ilimitado, interno: sem limites e sem prazo, para qualquer
  -- evento que o admin superior quiser liberar). O que cada plano libera
  -- fica em event_entitlements() (src/Plans.php).
  plan                  ENUM('standard','premium','unlimited') NOT NULL DEFAULT 'standard',
  -- módulo de presentes (lista + Pix). Desligado esconde, não apaga.
  gifts_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  -- faixa de pessoas (titulares + relacionados) e admins contratados.
  -- NULL = sem limite.
  guest_limit           INT UNSIGNED NULL,
  admin_limit           INT UNSIGNED NULL,
  -- evento de exemplo da página inicial (GET /demos): o convite aceita
  -- confirmar e escolher presente sem gravar nada.
  is_demo               TINYINT(1) NOT NULL DEFAULT 0,

  -- ciclo de acesso do admin do evento. NULL = nunca expira. Quando
  -- event_date é definida, o padrão é event_date + o prazo do plano
  -- (config access.grace_days, ou grace_days_premium no Premium),
  -- calculado pelo backend ao criar o evento — mas o admin superior pode
  -- sempre sobrescrever manualmente (renovação). Ilimitado = sempre NULL.
  access_expires_at     DATETIME NULL,

  -- controle financeiro do admin superior; sem nenhuma lógica de cobrança
  -- automatizada, apenas registro manual (o pagamento em si acontece fora
  -- do sistema, ex: Pix/transferência).
  price_charged         DECIMAL(10,2) NULL,
  -- cupom usado na venda (coupons.code), pra contar os usos de cada cupom.
  coupon_code           VARCHAR(40) NULL,
  last_payment_at       DATE NULL,
  payment_notes         VARCHAR(500) NULL,
  -- JSON do orçamento usado na venda (histórico).
  price_breakdown       TEXT NULL,

  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_events_slug (slug),
  KEY idx_events_coupon_code (coupon_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contas de admin: uma por email. is_super = 1 é o admin superior (gerencia
-- eventos e seus admins); as demais administram um ou mais eventos, ligadas
-- por event_admins (migração 021).
-- event_id ficou por compatibilidade com a API anterior à 021: a API mantém
-- nele um dos eventos da conta (NULL só no super), ver src/Accounts.php.
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      INT UNSIGNED NULL,
  is_super      TINYINT(1) NOT NULL DEFAULT 0,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_users_email (email),
  KEY idx_admin_users_event (event_id),
  CONSTRAINT fk_admin_users_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quais eventos cada conta administra (uma conta, vários eventos).
CREATE TABLE IF NOT EXISTS event_admins (
  event_id      INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, admin_user_id),
  KEY idx_event_admins_user (admin_user_id),
  CONSTRAINT fk_event_admins_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_admins_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de acesso (Bearer) emitidos no login. Permite logout/expiração
-- sem depender de cookies entre subdomínios diferentes (front x api).
CREATE TABLE IF NOT EXISTS admin_sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  -- evento ativo desta sessão (o que o painel mostra); NULL no super-admin.
  -- Trocar de evento é trocar este valor (POST /admin/session/event).
  event_id      INT UNSIGNED NULL,
  -- super-admin que abriu esta sessão no painel do evento ("entrar como
  -- admin", suporte); NULL = login normal. Ignora a expiração do evento.
  impersonated_by INT UNSIGNED NULL,
  token         CHAR(64) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NOT NULL,
  UNIQUE KEY uq_admin_sessions_token (token),
  KEY idx_admin_sessions_user (admin_user_id),
  CONSTRAINT fk_admin_sessions_user
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_sessions_event
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_sessions_impersonated_by
    FOREIGN KEY (impersonated_by) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de "esqueci senha" e de convite de novo admin (mesmo mecanismo:
-- provar controle do email, deixar a pessoa definir password_hash).
-- Guardados com hash (diferente de admin_sessions) por ficarem um tempo
-- indeterminado numa caixa de email, um cofre mais fraco que uma sessão ativa.
CREATE TABLE IF NOT EXISTS admin_password_tokens (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  purpose       ENUM('invite','reset') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NOT NULL,
  used_at       TIMESTAMP NULL,
  UNIQUE KEY uq_admin_password_tokens_hash (token_hash),
  KEY idx_admin_password_tokens_user (admin_user_id),
  CONSTRAINT fk_admin_password_tokens_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convidados. Uma pessoa "titular" tem related_to_id = NULL e um slug próprio
-- (usado no link individual, ex: /pedro-e-maria/jose-14). Um "dependente" tem
-- related_to_id apontando para o id do titular e não possui slug/link
-- próprio: ele é confirmado junto com o titular através do link dele.
-- slug é único por evento (uq_guests_event_slug), não mais globalmente.
CREATE TABLE IF NOT EXISTS guests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      INT UNSIGNED NOT NULL,
  name          VARCHAR(150) NOT NULL,
  slug          VARCHAR(180) NULL,
  related_to_id INT UNSIGNED NULL,
  -- papel no evento, exibido como destaque no convite. Só tem efeito no
  -- titular (vale para o link inteiro).
  role          ENUM('convidado','padrinho','madrinha','pais','especial') NOT NULL DEFAULT 'convidado',
  status        ENUM('pendente', 'confirmado', 'recusado') NOT NULL DEFAULT 'pendente',
  confirmed_at  TIMESTAMP NULL,
  -- métrica só para o admin: quantas vezes o link abriu a lista de
  -- presentes (contada no titular) e quando foi a última.
  gifts_views          INT UNSIGNED NOT NULL DEFAULT 0,
  gifts_last_viewed_at TIMESTAMP NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guests_event_slug (event_id, slug),
  KEY idx_guests_related_to (related_to_id),
  CONSTRAINT fk_guests_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_guests_related_to
    FOREIGN KEY (related_to_id) REFERENCES guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lista de presentes, escopada por evento. quantity é quanto daquele item
-- pode ser presenteado (itens simples podem aceitar mais de um
-- presenteador); a quantidade já dada é calculada a partir de gift_claims,
-- não guardada aqui.
CREATE TABLE IF NOT EXISTS gifts (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id          INT UNSIGNED NOT NULL,
  name              VARCHAR(150) NOT NULL,
  description       VARCHAR(500) NULL,
  image_path        VARCHAR(255) NULL,
  suggested_amount  DECIMAL(10,2) NOT NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gifts_event (event_id),
  CONSTRAINT fk_gifts_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Presentes-modelo por tipo de evento, mantidos pelo admin superior. Um
-- admin de evento pode clonar esses itens (nome, descrição, valor, imagem)
-- para dentro da própria `gifts` no primeiro acesso à tela de presentes;
-- a partir daí a linha clonada é independente, sem nenhum vínculo com o
-- modelo original.
CREATE TABLE IF NOT EXISTS gift_templates (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type        ENUM('wedding','birthday','graduation','other') NOT NULL,
  name              VARCHAR(150) NOT NULL,
  description       VARCHAR(500) NULL,
  image_path        VARCHAR(255) NULL,
  suggested_amount  DECIMAL(10,2) NOT NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gift_templates_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro de quem presenteou o quê. guest_id é sempre o titular do convite
-- (identificado pelo slug na URL da página de presentes), não um dependente.
-- Um titular só pode presentear o mesmo item uma vez (uq_gift_claims_gift_guest).
CREATE TABLE IF NOT EXISTS gift_claims (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gift_id       INT UNSIGNED NOT NULL,
  guest_id      INT UNSIGNED NOT NULL,
  message       VARCHAR(500) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gift_claims_gift_guest (gift_id, guest_id),
  KEY idx_gift_claims_gift (gift_id),
  KEY idx_gift_claims_guest (guest_id),
  CONSTRAINT fk_gift_claims_gift
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_gift_claims_guest
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Matriz de preços ("Preços e planos" no super-admin). Valor base de cada
-- tipo de evento (base_wedding, base_birthday, base_graduation, base_other)
-- e dos adicionais (gifts, extra_admin, premium). O orçamento é calculado
-- só no backend (compute_quote em src/Pricing.php).
CREATE TABLE IF NOT EXISTS price_items (
  item_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  label     VARCHAR(100) NOT NULL,
  amount    DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Faixas de convidados. A maior é aberta ("500+"): cobre qualquer
-- quantidade acima e o evento vendido nela fica sem limite.
CREATE TABLE IF NOT EXISTS guest_tiers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  max_guests  INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_guest_tiers_max_guests (max_guests)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parâmetros editáveis no super-admin (lista e limites em APP_SETTINGS,
-- src/Pricing.php): guest_tolerance_percent, included_admins,
-- grace_days_standard, grace_days_premium. Sem a chave, vale o config.php.
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  value        VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cupons de desconto aplicados no orçamento: em % (discount_value 20 =
-- 20%) ou em reais. code sempre em maiúsculas. valid_until NULL = sem validade.
CREATE TABLE IF NOT EXISTS coupons (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  discount_type   ENUM('percent','amount') NOT NULL,
  discount_value  DECIMAL(10,2) NOT NULL,
  valid_until     DATE NULL,
  -- limite de usos (eventos vendidos com o cupom + pedidos pagos que ainda
  -- não viraram evento). NULL = sem limite.
  max_uses        INT UNSIGNED NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pedidos feitos pelo site (pagamento online pelo Mercado Pago). O preço fica
-- travado no pedido (price_breakdown, mesmo formato do evento). public_id é o
-- token da página /pedido/{public_id} e a external_reference no Mercado Pago.
-- Status: pending (aguardando pagamento, até expires_at) → paid (webhook
-- confirmou) → event_created (o super criou o evento, event_id); ou expired,
-- canceled, refunded (estorno/chargeback).
CREATE TABLE IF NOT EXISTS orders (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(32) NOT NULL,
  status              ENUM('pending','paid','event_created','expired','canceled','refunded') NOT NULL DEFAULT 'pending',
  customer_name       VARCHAR(150) NOT NULL,
  customer_email      VARCHAR(190) NOT NULL,
  -- só dígitos, com DDD (e 55 opcional)
  customer_phone      VARCHAR(20) NOT NULL,
  event_type          ENUM('wedding','birthday','graduation','other') NOT NULL,
  host_name           VARCHAR(150) NOT NULL,
  host_name_secondary VARCHAR(150) NULL,
  subtitle            VARCHAR(150) NULL,
  event_date          DATE NULL,
  customer_notes      TEXT NULL,
  price_breakdown     TEXT NOT NULL,
  total               DECIMAL(10,2) NOT NULL,
  coupon_code         VARCHAR(40) NULL,
  mp_preference_id    VARCHAR(64) NULL,
  checkout_url        VARCHAR(500) NULL,
  mp_payment_id       VARCHAR(32) NULL,
  -- última conferência no MP pela external_reference (limita a uma a cada 30 s)
  mp_checked_at       DATETIME NULL,
  payment_method      VARCHAR(30) NULL,
  installments        TINYINT UNSIGNED NULL,
  paid_amount         DECIMAL(10,2) NULL,
  paid_at             DATETIME NULL,
  expires_at          DATETIME NOT NULL,
  event_id            INT UNSIGNED NULL,
  super_notes         TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_public_id (public_id),
  KEY idx_orders_status (status),
  KEY idx_orders_coupon_code (coupon_code),
  KEY idx_orders_mp_payment_id (mp_payment_id),
  CONSTRAINT fk_orders_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Diário de cada aviso (webhook) do Mercado Pago e do que ele fez no pedido
-- (outcome: paid, refunded, ignored, amount_mismatch…).
CREATE TABLE IF NOT EXISTS payment_notifications (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NULL,
  mp_payment_id VARCHAR(32) NULL,
  mp_status     VARCHAR(30) NULL,
  outcome       VARCHAR(30) NOT NULL,
  payload       TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payment_notifications_order (order_id),
  CONSTRAINT fk_payment_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro das migrações aplicadas pelos scripts de deploy (sql/deploy_*.sql),
-- que confere se um ajuste de dados já rodou sem consultar o information_schema.
CREATE TABLE IF NOT EXISTS schema_migrations (
  name        VARCHAR(40) NOT NULL PRIMARY KEY,
  applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
