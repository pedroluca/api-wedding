-- Deploy das migrações 018 e 019: eventos de exemplo (vitrine da página
-- inicial) e ponto de foco da foto de capa.
--
-- Como rodar no phpMyAdmin: clique primeiro no banco do Presenzo na lista da
-- esquerda e só então abra a aba SQL e cole este arquivo inteiro.
--
-- Pode rodar mais de uma vez sem estragar nada (IF NOT EXISTS). Não consulta
-- o information_schema de propósito (ver deploy_012_a_017.sql).
--
-- Antes: faça um backup do banco (Exportar, no phpMyAdmin).
-- Ordem do deploy: este SQL → API → front.


-- Registro das migrações (já existe em produção desde o deploy_012_a_017).
CREATE TABLE IF NOT EXISTS schema_migrations (
  name        VARCHAR(40) NOT NULL PRIMARY KEY,
  applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 018: eventos de exemplo -------------------------------------------------------
-- Padrão 0: nenhum evento existente muda.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_limit;

INSERT IGNORE INTO schema_migrations (name) VALUES ('018_events_is_demo');


-- 019: ponto de foco da foto de capa -------------------------------------------
-- Padrão 50/50 (centro), o mesmo recorte de antes: nenhum convite muda.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS cover_focus_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER cover_photo_path,
  ADD COLUMN IF NOT EXISTS cover_focus_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER cover_focus_x;

INSERT IGNORE INTO schema_migrations (name) VALUES ('019_events_cover_focus');


-- Conferência: deve listar todos os eventos com is_demo = 0 e foco 50/50, e
-- a 018 e a 019 entre as migrações.
SELECT id, slug, is_demo, cover_focus_x, cover_focus_y FROM events ORDER BY id;

SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS migracoes FROM schema_migrations;
