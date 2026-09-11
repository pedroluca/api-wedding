-- Migração 004: lista de presentes-modelo por tipo de evento, mantida pelo
-- admin superior. Independente das anteriores. Um admin de evento pode
-- clonar esses itens (nome, descrição, valor, imagem) para dentro da
-- própria lista (tabela `gifts`) no primeiro acesso à tela de presentes;
-- depois de clonado, a linha em `gifts` é totalmente independente daqui.

CREATE TABLE IF NOT EXISTS gift_templates (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type        ENUM('wedding','birthday') NOT NULL,
  name              VARCHAR(150) NOT NULL,
  description       VARCHAR(500) NULL,
  image_path        VARCHAR(255) NULL,
  suggested_amount  DECIMAL(10,2) NOT NULL,
  quantity          INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gift_templates_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
