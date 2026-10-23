-- Migração 017: cupom usado na venda do evento. O orçamento completo fica
-- em price_breakdown (migração 015); coupon_code em coluna própria serve pra
-- contar quantas vendas usaram cada cupom ("Preços e planos" no super).
-- Só acrescenta: a API anterior continua funcionando com este schema.

ALTER TABLE events
  ADD COLUMN coupon_code VARCHAR(40) NULL AFTER price_charged,
  ADD KEY idx_events_coupon_code (coupon_code);
