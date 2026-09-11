-- Migração 005: fonte do nome dos noivos/aniversariante é escolhida por
-- evento (cada admin decide entre a fonte padrão do site e as fontes de
-- caligrafia disponíveis), em vez de fixa no código.

ALTER TABLE events
  ADD COLUMN name_font ENUM('sans','fleur','pinyon') NOT NULL DEFAULT 'sans' AFTER color_primary;
