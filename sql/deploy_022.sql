-- Deploy da migração 022: pedidos e pagamento online (Mercado Pago).
--
-- Como rodar no phpMyAdmin: clique primeiro no banco do Presenzo na lista da
-- esquerda e só então abra a aba SQL e cole este arquivo inteiro.
--
-- Pode rodar mais de uma vez sem estragar nada (IF NOT EXISTS). Não consulta
-- o information_schema de propósito (ver deploy_012_a_017.sql).
--
-- Antes: faça um backup do banco (Exportar, no phpMyAdmin).
-- Ordem do deploy: este SQL → API → front. O botão "Contratar" só aparece
-- com o access_token do Mercado Pago no config.local.php do servidor.


-- 022: pedidos ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id           CHAR(32) NOT NULL,
  status              ENUM('pending','paid','event_created','expired','canceled','refunded') NOT NULL DEFAULT 'pending',
  customer_name       VARCHAR(150) NOT NULL,
  customer_email      VARCHAR(190) NOT NULL,
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
  payment_method      VARCHAR(30) NULL,
  installments        TINYINT UNSIGNED NULL,
  paid_amount         DECIMAL(10,2) NULL,
  paid_at             DATETIME NULL,
  expires_at          DATETIME NOT NULL,
  event_id            INT UNSIGNED NULL,
  super_notes         TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_id (public_id),
  KEY idx_orders_status (status),
  KEY idx_orders_coupon_code (coupon_code),
  KEY idx_orders_mp_payment_id (mp_payment_id),
  CONSTRAINT fk_orders_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Diário de cada aviso do Mercado Pago.
CREATE TABLE IF NOT EXISTS payment_notifications (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      INT UNSIGNED NULL,
  mp_payment_id VARCHAR(32) NULL,
  mp_status     VARCHAR(30) NULL,
  outcome       VARCHAR(30) NOT NULL,
  payload       TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_notifications_order (order_id),
  CONSTRAINT fk_payment_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (name) VALUES ('022_orders');


-- Conferência: as duas tabelas (vazias) e a 022 entre as migrações.
SELECT (SELECT COUNT(*) FROM orders) AS pedidos, (SELECT COUNT(*) FROM payment_notifications) AS avisos;

SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS migracoes FROM schema_migrations;
