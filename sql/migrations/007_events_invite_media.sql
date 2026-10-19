-- Migração 007: personalização da tela interna do convite (casamento).
-- invite_message: mensagem livre dos noivos exibida no convite.
-- cover_photo_path: foto do casal usada como capa (hero) do convite.
-- music_path: música tocada quando o convidado abre o envelope.
-- Os dois *_path seguem o mesmo formato de logo_path ("uploads/...").

ALTER TABLE events
  ADD COLUMN invite_message VARCHAR(1000) NULL AFTER dress_code,
  ADD COLUMN cover_photo_path VARCHAR(255) NULL AFTER logo_path,
  ADD COLUMN music_path VARCHAR(255) NULL AFTER cover_photo_path;
