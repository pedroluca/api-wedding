-- Migração 015: plano do evento e o que foi contratado.
-- plan: 'standard' (Padrão), 'premium' (Premium) ou 'unlimited' (Ilimitado,
-- interno: o super-admin coloca nele qualquer evento que quiser liberar).
-- gifts_enabled: módulo de presentes (lista + Pix). Desligado esconde, não apaga.
-- guest_limit / admin_limit: faixa de pessoas e admins contratados. NULL = sem limite.
-- price_breakdown: JSON do orçamento usado na venda (histórico).
--
-- Só acrescenta, e os padrões reproduzem o comportamento atual (Padrão, com
-- presentes, sem limite). A API anterior continua funcionando com este schema.

ALTER TABLE events
  ADD COLUMN plan ENUM('standard','premium','unlimited') NOT NULL DEFAULT 'standard' AFTER show_calendar,
  ADD COLUMN gifts_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER plan,
  ADD COLUMN guest_limit INT UNSIGNED NULL AFTER gifts_enabled,
  ADD COLUMN admin_limit INT UNSIGNED NULL AFTER guest_limit,
  ADD COLUMN price_breakdown TEXT NULL AFTER payment_notes;
