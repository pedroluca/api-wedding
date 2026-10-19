-- Migração 009: modelo da mensagem que o admin copia para enviar o convite
-- (ex: WhatsApp), com variáveis como {primeiro_nome} e {link} trocadas no
-- front para cada convidado. NULL = usa o texto padrão do front.

ALTER TABLE events
  ADD COLUMN share_message VARCHAR(1000) NULL AFTER invite_message;
