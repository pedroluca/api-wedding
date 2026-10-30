-- Migração 022: pedidos e pagamento online (Mercado Pago, Checkout Pro).
-- O cliente contrata pelo site: o pedido guarda quem é, o que foi escolhido
-- e o preço travado no dia (price_breakdown, mesmo formato do evento). O
-- pagamento confirmado pelo webhook vira "paid", e o super cria o evento a
-- partir dele ("event_created"). Ver docs/plano-pagamento-online.md (front).
--
-- payment_notifications é o diário de cada aviso do Mercado Pago, para
-- investigar um "paguei e não liberou" sem depender do painel deles.
--
-- Só acrescenta: tabelas novas. A API anterior continua funcionando.

CREATE TABLE orders (
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
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_id (public_id),
  KEY idx_orders_status (status),
  KEY idx_orders_coupon_code (coupon_code),
  KEY idx_orders_mp_payment_id (mp_payment_id),
  CONSTRAINT fk_orders_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_notifications (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      INT UNSIGNED NULL,
  mp_payment_id VARCHAR(32) NULL,
  mp_status     VARCHAR(30) NULL,
  -- o que aconteceu com o pedido: paid, refunded, ignored, amount_mismatch…
  outcome       VARCHAR(30) NOT NULL,
  payload       TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_notifications_order (order_id),
  CONSTRAINT fk_payment_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
