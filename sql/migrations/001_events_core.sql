-- Migração 001: cria a tabela `events` e adiciona a coluna event_id
-- (ainda NULL-ável) em guests/gifts/admin_users. Puramente aditiva —
-- pode rodar em produção a qualquer momento, o código atualmente
-- implantado continua funcionando sem nenhuma alteração de comportamento.

CREATE TABLE IF NOT EXISTS events (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(180) NOT NULL,
  event_type            ENUM('wedding','birthday') NOT NULL DEFAULT 'wedding',

  -- marca / dados do convite, generalizados de weddingInfo.ts
  -- (host_name_secondary / venue_name_secondary NULL para eventos de um anfitrião só, ex: aniversário)
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

  -- ciclo de acesso: NULL = nunca expira (ex: o evento do próprio dono da plataforma)
  access_expires_at     DATETIME NULL,

  -- controle financeiro do admin superior; sem nenhuma lógica de cobrança,
  -- apenas registro manual (pagamento é tratado fora do sistema)
  price_charged         DECIMAL(10,2) NULL,
  last_payment_at       DATE NULL,
  payment_notes         VARCHAR(500) NULL,

  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_events_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE guests      ADD COLUMN event_id INT UNSIGNED NULL AFTER id;
ALTER TABLE gifts       ADD COLUMN event_id INT UNSIGNED NULL AFTER id;
ALTER TABLE admin_users ADD COLUMN event_id INT UNSIGNED NULL AFTER id;
