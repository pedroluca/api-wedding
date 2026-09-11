-- Schema do banco de dados do wedding-confirm
-- MySQL / MariaDB, InnoDB, utf8mb4
--
-- Este arquivo reflete o estado final (multi-evento) do banco, para setups
-- locais novos. Para levar um banco de produção existente (pré-multi-evento)
-- até este mesmo estado, use as migrações em sql/migrations/ em ordem
-- (001 a 005) em vez de reaplicar este arquivo.

CREATE TABLE IF NOT EXISTS events (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(180) NOT NULL,
  event_type            ENUM('wedding','birthday') NOT NULL DEFAULT 'wedding',

  -- marca / dados do convite (nomes, data, local, chave Pix etc), preenchidos
  -- pelo admin do evento em vez de fixos no código. host_name_secondary e
  -- venue_name_secondary ficam NULL em eventos de um anfitrião só (ex: aniversário).
  host_name             VARCHAR(150) NOT NULL,
  host_name_secondary   VARCHAR(150) NULL,
  event_date            DATETIME NULL,
  venue_name            VARCHAR(190) NULL,
  venue_name_secondary  VARCHAR(190) NULL,
  address               VARCHAR(255) NULL,
  maps_url              VARCHAR(500) NULL,
  dress_code            VARCHAR(150) NULL,
  pix_key               VARCHAR(190) NULL,
  logo_path             VARCHAR(255) NULL,
  color_primary         CHAR(7) NOT NULL DEFAULT '#d2afff',
  -- fonte usada no nome dos noivos/aniversariante na página do convite:
  -- 'sans' (padrão do site) ou uma das fontes de caligrafia disponíveis.
  name_font             ENUM('sans','fleur','pinyon') NOT NULL DEFAULT 'sans',

  -- ciclo de acesso do admin do evento. NULL = nunca expira. Quando
  -- event_date é definida, o padrão é event_date + config('access.grace_days')
  -- dias, calculado pelo backend ao criar/atualizar o evento — mas o
  -- admin superior pode sempre sobrescrever manualmente (renovação).
  access_expires_at     DATETIME NULL,

  -- controle financeiro do admin superior; sem nenhuma lógica de cobrança
  -- automatizada, apenas registro manual (o pagamento em si acontece fora
  -- do sistema, ex: Pix/transferência).
  price_charged         DECIMAL(10,2) NULL,
  last_payment_at       DATE NULL,
  payment_notes         VARCHAR(500) NULL,

  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_events_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- admin_users.event_id é o único sinal de papel do sistema:
-- NULL = admin superior (gerencia eventos e seus admins);
-- não-nulo = admin de exatamente um evento (gerencia convidados/presentes/marca daquele evento).
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      INT UNSIGNED NULL,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_users_email (email),
  KEY idx_admin_users_event (event_id),
  CONSTRAINT fk_admin_users_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de acesso (Bearer) emitidos no login. Permite logout/expiração
-- sem depender de cookies entre subdomínios diferentes (front x api).
CREATE TABLE IF NOT EXISTS admin_sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  token         CHAR(64) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NOT NULL,
  UNIQUE KEY uq_admin_sessions_token (token),
  KEY idx_admin_sessions_user (admin_user_id),
  CONSTRAINT fk_admin_sessions_user
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
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
  status        ENUM('pendente', 'confirmado', 'recusado') NOT NULL DEFAULT 'pendente',
  confirmed_at  TIMESTAMP NULL,
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
  event_type        ENUM('wedding','birthday') NOT NULL,
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
