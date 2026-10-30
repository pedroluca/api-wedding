-- Migração 020: limite de usos do cupom ("os 5 primeiros têm 30%").
-- Um uso é um evento vendido com o cupom (events.coupon_code), ou seja, o
-- orçamento aplicado no evento pelo super-admin. Pedir pelo WhatsApp no
-- simulador não conta. NULL = sem limite.
--
-- Só acrescenta. A API anterior continua funcionando com este schema.

ALTER TABLE coupons
  ADD COLUMN max_uses INT UNSIGNED NULL AFTER valid_until;
