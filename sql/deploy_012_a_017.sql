-- Deploy de uma vez das migrações 012 a 017: aberturas do convite, painel
-- do super ("entrar como admin"), planos, faixa de convidados, preços,
-- cupons e orçamento guardado no evento. A 011 já rodou em produção.
--
-- Pode rodar mais de uma vez sem estragar nada: cada passo confere se já foi
-- feito (IF NOT EXISTS) e os dados iniciais só entram se ainda não existem.
-- Se parar no meio por algum erro, corrija e rode o arquivo inteiro de novo.
--
-- Antes: faça um backup do banco (Exportar, no phpMyAdmin).
-- Ordem do deploy: este SQL → API → front.
-- Os arquivos em sql/migrations/ continuam sendo o histórico, passo a passo.


-- 012: estilo de abertura do convite -----------------------------------------
-- Casamentos ficam com o envelope que já usavam ('letter'); os demais tipos
-- não tinham envelope e ficam com 'direct'. Esse ajuste só roda na primeira
-- vez: depois, o estilo é escolha do admin do evento.
SET @tinha_invite_style = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'invite_style'
);

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS invite_style ENUM('direct','letter','doors','vertical') NOT NULL DEFAULT 'letter' AFTER name_font;

UPDATE events SET invite_style = 'direct' WHERE event_type <> 'wedding' AND @tinha_invite_style = 0;


-- 013: seções opcionais do convite (contagem regressiva, "Salve a data") ----
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS show_countdown TINYINT(1) NOT NULL DEFAULT 1 AFTER invite_style,
  ADD COLUMN IF NOT EXISTS show_calendar TINYINT(1) NOT NULL DEFAULT 1 AFTER show_countdown;


-- 014: sessão aberta pelo super-admin ("entrar como admin") -----------------
ALTER TABLE admin_sessions
  ADD COLUMN IF NOT EXISTS impersonated_by INT UNSIGNED NULL AFTER admin_user_id,
  ADD CONSTRAINT fk_admin_sessions_impersonated_by
    FOREIGN KEY IF NOT EXISTS (impersonated_by) REFERENCES admin_users(id) ON DELETE CASCADE;


-- 015: plano do evento e o que foi contratado --------------------------------
-- Os padrões reproduzem o de hoje: Padrão, com presentes, sem limite.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS plan ENUM('standard','premium','unlimited') NOT NULL DEFAULT 'standard' AFTER show_calendar,
  ADD COLUMN IF NOT EXISTS gifts_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER plan,
  ADD COLUMN IF NOT EXISTS guest_limit INT UNSIGNED NULL AFTER gifts_enabled,
  ADD COLUMN IF NOT EXISTS admin_limit INT UNSIGNED NULL AFTER guest_limit,
  ADD COLUMN IF NOT EXISTS price_breakdown TEXT NULL AFTER payment_notes;


-- 016: matriz de preços, parâmetros editáveis e cupons -----------------------
-- Valores começam zerados e são preenchidos em "Preços e planos" no super.
CREATE TABLE IF NOT EXISTS price_items (
  item_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  label     VARCHAR(100) NOT NULL,
  amount    DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO price_items (item_key, label, amount) VALUES
  ('base_wedding', 'Casamento', 0),
  ('base_birthday', 'Aniversário', 0),
  ('base_graduation', 'Formatura', 0),
  ('base_other', 'Celebração', 0),
  ('gifts', 'Lista de presentes e Pix', 0),
  ('extra_admin', 'Admin extra', 0),
  ('premium', 'Premium', 0);

CREATE TABLE IF NOT EXISTS guest_tiers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  max_guests  INT UNSIGNED NOT NULL,
  amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_guest_tiers_max_guests (max_guests)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Só com a tabela vazia: rodar de novo não recria faixas que você removeu.
INSERT INTO guest_tiers (max_guests, amount)
SELECT faixa.max_guests, 0
FROM (SELECT 50 AS max_guests UNION ALL SELECT 100 UNION ALL SELECT 150
      UNION ALL SELECT 200 UNION ALL SELECT 300 UNION ALL SELECT 500) AS faixa
WHERE NOT EXISTS (SELECT 1 FROM guest_tiers);

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key  VARCHAR(40) NOT NULL PRIMARY KEY,
  value        VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (setting_key, value) VALUES
  ('guest_tolerance_percent', '20'),
  ('included_admins', '2'),
  ('grace_days_standard', '15'),
  ('grace_days_premium', '30');

CREATE TABLE IF NOT EXISTS coupons (
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


-- 017: cupom usado na venda do evento ----------------------------------------
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(40) NULL AFTER price_charged,
  ADD KEY IF NOT EXISTS idx_events_coupon_code (coupon_code);


-- Conferência: deve listar as 4 tabelas novas e as colunas novas de events.
SELECT TABLE_NAME AS tabela_nova FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('price_items', 'guest_tiers', 'app_settings', 'coupons');

SELECT COLUMN_NAME AS coluna_nova_em_events FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'
  AND COLUMN_NAME IN ('invite_style', 'show_countdown', 'show_calendar', 'plan', 'gifts_enabled',
                      'guest_limit', 'admin_limit', 'price_breakdown', 'coupon_code');
