-- Migração 014: sessão aberta pelo super-admin no painel de um evento
-- ("entrar como admin", para suporte). impersonated_by é o super-admin que
-- abriu a sessão; NULL = login normal. Uma sessão assim ignora a expiração
-- de acesso do evento (ver require_event_admin em src/Auth.php).
-- Só acrescenta: a API anterior continua funcionando com este schema.

ALTER TABLE admin_sessions
  ADD COLUMN impersonated_by INT UNSIGNED NULL AFTER admin_user_id,
  ADD CONSTRAINT fk_admin_sessions_impersonated_by
    FOREIGN KEY (impersonated_by) REFERENCES admin_users(id) ON DELETE CASCADE;
