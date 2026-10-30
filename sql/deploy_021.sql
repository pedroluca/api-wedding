-- Deploy da migração 021: uma conta, vários eventos.
--
-- Como rodar no phpMyAdmin: clique primeiro no banco do Presenzo na lista da
-- esquerda e só então abra a aba SQL e cole este arquivo inteiro.
--
-- Pode rodar mais de uma vez sem estragar nada (IF NOT EXISTS, INSERT IGNORE
-- e preenchimentos que só tocam o que ainda está vazio). Não consulta o
-- information_schema de propósito (ver deploy_012_a_017.sql).
--
-- Antes: faça um backup do banco (Exportar, no phpMyAdmin).
-- Ordem do deploy: este SQL → API → este SQL de novo → front.
-- A segunda rodada pega o que a API anterior tiver criado no intervalo
-- (um admin novo ou um login entre o SQL e a troca da API).


-- 021: contas e eventos -------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_admins (
  event_id      INT UNSIGNED NOT NULL,
  admin_user_id INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, admin_user_id),
  KEY idx_event_admins_user (admin_user_id),
  CONSTRAINT fk_event_admins_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_admins_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS is_super TINYINT(1) NOT NULL DEFAULT 0 AFTER event_id;

ALTER TABLE admin_sessions
  ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED NULL AFTER admin_user_id,
  ADD CONSTRAINT fk_admin_sessions_event
    FOREIGN KEY IF NOT EXISTS (event_id) REFERENCES events(id) ON DELETE CASCADE;

-- Cada conta de evento vira uma ligação com o evento dela. A API nova mantém
-- admin_users.event_id sempre num dos eventos da conta, então rodar de novo
-- depois do deploy não traz de volta uma ligação removida.
INSERT IGNORE INTO event_admins (event_id, admin_user_id, created_at)
  SELECT event_id, id, created_at FROM admin_users WHERE event_id IS NOT NULL;

-- Quem não tem evento é o super-admin (o sinal usado até a 020).
UPDATE admin_users SET is_super = 1 WHERE event_id IS NULL;

-- Sessões abertas continuam no evento de antes: ninguém cai do painel.
UPDATE admin_sessions s
  INNER JOIN admin_users au ON au.id = s.admin_user_id
  SET s.event_id = au.event_id
  WHERE s.event_id IS NULL AND au.event_id IS NOT NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('021_event_admins');


-- Conferência: as contas com os eventos de cada uma (o super aparece com
-- is_super = 1 e sem eventos) e a 021 entre as migrações.
SELECT au.id, au.name, au.email, au.is_super,
       GROUP_CONCAT(ea.event_id ORDER BY ea.event_id SEPARATOR ', ') AS eventos
FROM admin_users au
LEFT JOIN event_admins ea ON ea.admin_user_id = au.id
GROUP BY au.id, au.name, au.email, au.is_super
ORDER BY au.id;

SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS migracoes FROM schema_migrations;
