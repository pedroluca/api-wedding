-- Migração 018: eventos de exemplo (a vitrine da página inicial).
-- is_demo = 1: o evento aparece na home (GET /demos) e o convite dele aceita
-- confirmar presença e escolher presente sem gravar nada, porque o mesmo
-- link é aberto por qualquer visitante. Também fica fora das métricas do
-- dashboard do super.
--
-- Só acrescenta, com padrão 0 (nenhum evento muda). A API anterior continua
-- funcionando com este schema.

ALTER TABLE events
  ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_limit;
