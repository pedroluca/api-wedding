-- Deploy da migração 020: limite de usos do cupom.
--
-- Como rodar no phpMyAdmin: clique primeiro no banco do Presenzo na lista da
-- esquerda e só então abra a aba SQL e cole este arquivo inteiro.
--
-- Pode rodar mais de uma vez sem estragar nada (IF NOT EXISTS). Não consulta
-- o information_schema de propósito (ver deploy_012_a_017.sql).
--
-- Antes: faça um backup do banco (Exportar, no phpMyAdmin).
-- Ordem do deploy: este SQL → API → front.


-- 020: limite de usos do cupom ---------------------------------------------------
-- NULL = sem limite: os cupons que já existem continuam iguais.
ALTER TABLE coupons
  ADD COLUMN IF NOT EXISTS max_uses INT UNSIGNED NULL AFTER valid_until;

INSERT IGNORE INTO schema_migrations (name) VALUES ('020_coupons_max_uses');


-- Conferência: os cupons com o limite (vazio = sem limite) e a 020 entre as
-- migrações.
SELECT id, code, valid_until, max_uses, active FROM coupons ORDER BY id;

SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS migracoes FROM schema_migrations;
