-- Migração 012: estilo de abertura do convite, escolhido pelo admin do
-- evento. 'direct' abre direto no conteúdo; 'letter' é o envelope com aba
-- (o de sempre dos casamentos); 'doors' e 'vertical' são o envelope de
-- abertura central, abrindo para os lados ou para cima e para baixo.
--
-- Casamentos existentes ficam com o envelope que já usavam ('letter');
-- os demais tipos não tinham envelope, então ficam com 'direct'. Só
-- acrescenta: a API anterior continua funcionando com este schema.

ALTER TABLE events
  ADD COLUMN invite_style ENUM('direct','letter','doors','vertical') NOT NULL DEFAULT 'letter' AFTER name_font;

UPDATE events SET invite_style = 'direct' WHERE event_type <> 'wedding';
