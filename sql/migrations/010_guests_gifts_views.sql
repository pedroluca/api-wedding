-- Migração 010: métrica de acesso à lista de presentes, só para o admin.
-- Contada no titular (dono do link): cada carregamento de
-- GET /events/{e}/guests/{g}/gifts soma 1 e atualiza o último acesso.

ALTER TABLE guests
  ADD COLUMN gifts_views INT UNSIGNED NOT NULL DEFAULT 0 AFTER confirmed_at,
  ADD COLUMN gifts_last_viewed_at TIMESTAMP NULL AFTER gifts_views;
