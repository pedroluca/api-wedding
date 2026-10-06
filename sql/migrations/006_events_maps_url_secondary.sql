-- Migração 006: link de mapa próprio para o local da festa (venue_name_secondary),
-- já que nem sempre a festa acontece no mesmo endereço da cerimônia.
-- maps_url continua sendo o link do endereço principal (address).

ALTER TABLE events
  ADD COLUMN maps_url_secondary VARCHAR(500) NULL AFTER maps_url;
