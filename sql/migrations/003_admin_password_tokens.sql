-- Migração 003: tabela unificada de tokens de "esqueci senha" e de
-- "convite de novo admin" (o super-admin cria o admin_users, e o convidado
-- define a própria senha pelo mesmo mecanismo). Independente das migrações
-- 001/002 — só depende de admin_users já existir.

CREATE TABLE IF NOT EXISTS admin_password_tokens (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,        -- sha256 do token bruto; o token bruto só existe no link do email
  purpose       ENUM('invite','reset') NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    TIMESTAMP NOT NULL,
  used_at       TIMESTAMP NULL,
  UNIQUE KEY uq_admin_password_tokens_hash (token_hash),
  KEY idx_admin_password_tokens_user (admin_user_id),
  CONSTRAINT fk_admin_password_tokens_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
