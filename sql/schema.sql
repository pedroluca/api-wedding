-- Schema do banco de dados do wedding-confirm
-- MySQL / MariaDB, InnoDB, utf8mb4

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_users_email (email)
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

-- Convidados. Uma pessoa "titular" tem related_to_id = NULL e um slug próprio
-- (usado no link individual, ex: /jose-14). Um "dependente" tem related_to_id
-- apontando para o id do titular (id_subordinacao) e não possui slug/link
-- próprio: ele é confirmado junto com o titular através do link dele.
CREATE TABLE IF NOT EXISTS guests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  slug          VARCHAR(180) NULL,
  related_to_id INT UNSIGNED NULL,
  status        ENUM('pendente', 'confirmado', 'recusado') NOT NULL DEFAULT 'pendente',
  confirmed_at  TIMESTAMP NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guests_slug (slug),
  KEY idx_guests_related_to (related_to_id),
  CONSTRAINT fk_guests_related_to
    FOREIGN KEY (related_to_id) REFERENCES guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
