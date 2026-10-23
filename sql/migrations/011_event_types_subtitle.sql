-- Migração 011: novos tipos de evento (formatura e "outro", para chá de
-- bebê, bodas, batizado...) e subtítulo opcional exibido abaixo dos nomes
-- ("Medicina · UFMG", "30 anos", "Chá de bebê do Theo").
--
-- Só acrescenta: valores novos no fim dos ENUMs (mudança só de metadados,
-- sem reescrever a tabela) e uma coluna NULL. A API anterior continua
-- funcionando com este schema, então dá pra rodar antes do deploy.

ALTER TABLE events
  MODIFY COLUMN event_type ENUM('wedding','birthday','graduation','other') NOT NULL DEFAULT 'wedding',
  ADD COLUMN subtitle VARCHAR(150) NULL AFTER host_name_secondary;

ALTER TABLE gift_templates
  MODIFY COLUMN event_type ENUM('wedding','birthday','graduation','other') NOT NULL;
