-- Migração 002: cria o Evento #1 a partir dos valores reais que hoje
-- vivem em wedding-confirm/src/weddingInfo.ts, faz o backfill de
-- event_id em todas as linhas existentes, e só então aperta as
-- colunas/constraints. Deve rodar imediatamente antes do deploy
-- coordenado do backend + frontend novos (as rotas antiga e nova não
-- interoperam).

INSERT INTO events
  (slug, event_type, host_name, host_name_secondary, event_date,
   venue_name, venue_name_secondary, address, maps_url, dress_code, pix_key, color_primary)
VALUES
  ('pedro-e-maria', 'wedding', 'Pedro Luca', 'Maria Eduarda', '2026-12-13 10:30:00',
   'Igreja Matriz de Santa Cruz da Paixão', 'Chácara de Cassiano',
   'Av. Santa Cruz, 210, Centro - Malhada, BA', 'https://maps.app.goo.gl/tmGRhN4FB2t8yfgQ8',
   'Esporte fino', 'pedrolucaeeduarda@outlook.com', '#d2afff');
-- access_expires_at fica de fora de propósito (default NULL = nunca expira):
-- é o próprio evento do dono da plataforma, não faz sentido ele se autobloquear.

SET @event1_id = LAST_INSERT_ID();

UPDATE guests      SET event_id = @event1_id WHERE event_id IS NULL;
UPDATE gifts       SET event_id = @event1_id WHERE event_id IS NULL;
UPDATE admin_users SET event_id = @event1_id WHERE event_id IS NULL;

-- Confirmar manualmente (as três consultas abaixo devem retornar 0 linhas)
-- antes de continuar para os ALTER abaixo:
--   SELECT id FROM guests WHERE event_id IS NULL;
--   SELECT id FROM gifts WHERE event_id IS NULL;
--   SELECT id FROM admin_users WHERE event_id IS NULL;

ALTER TABLE guests MODIFY COLUMN event_id INT UNSIGNED NOT NULL;
ALTER TABLE gifts  MODIFY COLUMN event_id INT UNSIGNED NOT NULL;
-- admin_users.event_id NÃO é apertado para NOT NULL: a nulidade é o próprio
-- sinal de papel (NULL = admin superior, não-nulo = admin de um evento).

ALTER TABLE guests ADD CONSTRAINT fk_guests_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT;
ALTER TABLE gifts  ADD CONSTRAINT fk_gifts_event  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT;
ALTER TABLE admin_users ADD CONSTRAINT fk_admin_users_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE guests DROP INDEX uq_guests_slug;
ALTER TABLE guests ADD UNIQUE KEY uq_guests_event_slug (event_id, slug);
