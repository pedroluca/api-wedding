-- Migração 016: matriz de preços, parâmetros editáveis e cupons.
-- price_items: valor base de cada tipo de evento e dos adicionais.
-- guest_tiers: faixas de convidados. A maior é aberta ("500+"): cobre
--   qualquer quantidade acima e o evento fica sem limite de convidados.
-- app_settings: margem da faixa, admins incluídos e prazos de acesso,
--   antes no config.php (que continua valendo de reserva).
-- coupons: desconto em % ou em R$, aplicado no orçamento.
--
-- Só cria tabelas: a API anterior continua funcionando com este schema.
-- Os valores começam zerados e são preenchidos em "Preços e planos" no super.
--
-- Sem COLLATE fixo: a única comparação com tabela antiga (coupons.code com
-- events.coupon_code) declara o collation na consulta (handlers/coupons.php),
-- então vale em qualquer servidor.

CREATE TABLE price_items (
  item_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  label     VARCHAR(100) NOT NULL,
  amount    DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO price_items (item_key, label, amount) VALUES
  ('base_wedding', 'Casamento', 0),
  ('base_birthday', 'Aniversário', 0),
  ('base_graduation', 'Formatura', 0),
  ('base_other', 'Celebração', 0),
  ('gifts', 'Lista de presentes e Pix', 0),
  ('extra_admin', 'Admin extra', 0),
  ('premium', 'Premium', 0);

CREATE TABLE guest_tiers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  max_guests  INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_guest_tiers_max_guests (max_guests)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO guest_tiers (max_guests, amount) VALUES
  (50, 0), (100, 0), (150, 0), (200, 0), (300, 0), (500, 0);

CREATE TABLE app_settings (
  setting_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  value        VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, value) VALUES
  ('guest_tolerance_percent', '20'),
  ('included_admins', '2'),
  ('grace_days_standard', '15'),
  ('grace_days_premium', '30');

CREATE TABLE coupons (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  discount_type   ENUM('percent','amount') NOT NULL,
  discount_value  DECIMAL(10,2) NOT NULL,
  valid_until     DATE NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
