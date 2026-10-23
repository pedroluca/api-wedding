-- Migração 013: seções opcionais do convite. O admin do evento pode
-- esconder a contagem regressiva e o "Salve a data" (adicionar à agenda).
-- Ligadas por padrão, então os convites existentes não mudam. Só acrescenta:
-- a API anterior continua funcionando com este schema.

ALTER TABLE events
  ADD COLUMN show_countdown TINYINT(1) NOT NULL DEFAULT 1 AFTER invite_style,
  ADD COLUMN show_calendar TINYINT(1) NOT NULL DEFAULT 1 AFTER show_countdown;
