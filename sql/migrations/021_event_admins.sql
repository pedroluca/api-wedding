-- Migração 021: uma conta, vários eventos.
-- Uma conta de admin (email + senha) passa a poder administrar mais de um
-- evento: a ligação conta × evento sai de admin_users.event_id e vai para
-- event_admins. A sessão guarda o evento ativo (admin_sessions.event_id), e
-- o papel de super-admin vira uma marcação própria (admin_users.is_super),
-- em vez de "event_id NULL".
--
-- Só acrescenta. admin_users.event_id continua existindo e a API nova
-- mantém nela um dos eventos da conta, então a API anterior continua
-- funcionando com este schema (e nunca enxerga um cliente como super).

CREATE TABLE event_admins (
  event_id      INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, admin_user_id),
  KEY idx_event_admins_user (admin_user_id),
  CONSTRAINT fk_event_admins_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_admins_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE admin_users
  ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0 AFTER event_id;

ALTER TABLE admin_sessions
  ADD COLUMN event_id INT UNSIGNED NULL AFTER admin_user_id,
  ADD CONSTRAINT fk_admin_sessions_event
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

-- Preenchimento a partir do modelo anterior: cada conta de evento vira uma
-- ligação, quem não tinha evento é o super, e as sessões abertas continuam
-- no evento de antes (ninguém cai do painel).
INSERT IGNORE INTO event_admins (event_id, admin_user_id, created_at)
  SELECT event_id, id, created_at FROM admin_users WHERE event_id IS NOT NULL;

UPDATE admin_users SET is_super = 1 WHERE event_id IS NULL;

UPDATE admin_sessions s
  INNER JOIN admin_users au ON au.id = s.admin_user_id
  SET s.event_id = au.event_id
  WHERE s.event_id IS NULL AND au.event_id IS NOT NULL;
