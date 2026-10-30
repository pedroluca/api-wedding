-- Migração 019: ponto de foco da foto de capa (o que não pode ser cortado:
-- o rosto, o casal). Em % da largura e da altura da foto; o convite recorta
-- a capa em volta dele, no celular e no computador. 50/50 = centro, que é o
-- recorte de antes, então nenhum convite muda até o admin escolher.
--
-- Só acrescenta. A API anterior continua funcionando com este schema.

ALTER TABLE events
  ADD COLUMN cover_focus_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER cover_photo_path,
  ADD COLUMN cover_focus_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER cover_focus_x;
