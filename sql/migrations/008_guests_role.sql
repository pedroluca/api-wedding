-- Migração 008: papel do convidado no evento (padrinho, madrinha, pais dos
-- noivos ou convidado especial), usado para exibir um destaque no convite.
-- Só tem efeito no titular (vale para o link inteiro); dependentes ficam
-- sempre com o padrão 'convidado'.

ALTER TABLE guests
  ADD COLUMN role ENUM('convidado','padrinho','madrinha','pais','especial')
    NOT NULL DEFAULT 'convidado' AFTER related_to_id;
