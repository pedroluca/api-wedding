-- Cria os 3 eventos de exemplo da página inicial (casamento, aniversário e
-- formatura), iguais aos do ambiente de desenvolvimento: evento, convite da
-- vitrine (o primeiro cadastrado), um admin e a lista de presentes.
--
-- ANTES:
--   1. Rode o deploy_018_019.sql (cria is_demo e o foco da capa).
--   2. Suba os arquivos do demo-uploads.zip: extraia na pasta da API (onde
--      fica o index.php), pra cair em uploads/covers, uploads/music e
--      uploads/gifts. Os nomes são os mesmos que este SQL usa.
--
-- Como rodar no phpMyAdmin: clique primeiro no banco do Presenzo na lista da
-- esquerda e só então abra a aba SQL e cole este arquivo inteiro.
--
-- Só cria linhas novas: não altera nem apaga nada que já existe (o único
-- UPDATE preenche o link do convite que acabou de ser criado). Rodar de novo
-- não duplica: cada passo confere se já foi feito. Se o link de um evento
-- (slug) já for de um evento de cliente, aquele exemplo é pulado inteiro.
--
-- Os admins usam emails @presenzo.com.br e não têm senha: entre pelo super
-- com "Entrar como admin" (ou use "Esqueci minha senha" com o email).
-- A chave Pix é de enfeite: o convite de exemplo nunca mostra.


-- Casamento: Helena & Rafael (/helena-e-rafael) -------------------------------
INSERT INTO events (slug, event_type, host_name, host_name_secondary, subtitle, event_date, venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code, invite_message, share_message, cover_photo_path, cover_focus_x, cover_focus_y, music_path, color_primary, name_font, invite_style, show_countdown, show_calendar, gifts_enabled, pix_key, plan, is_demo)
SELECT 'helena-e-rafael', 'wedding', 'Helena', 'Rafael', NULL, '2027-09-18 16:30:00', 'Paróquia Nossa Senhora do Carmo', 'Villa Jardim Eventos', 'Rua das Flores, 120 · Centro', 'https://maps.google.com/?q=Par%C3%B3quia%20Nossa%20Senhora%20do%20Carmo', 'https://maps.google.com/?q=Villa%20Jardim%20Eventos', 'Esporte fino', 'Com a bênção de Deus e de nossas famílias, convidamos você para celebrar conosco o início da nossa história.', NULL, 'uploads/covers/84fed000a3649981bfce6d2aa3a29171.jpg', 50, 30, 'uploads/music/78ae4ca818ab3c8b87cede69a946ce2c.mp3', '#b07d8f', 'pinyon', 'letter', 1, 1, 1, 'exemplo@presenzo.com.br', 'unlimited', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM events WHERE slug = 'helena-e-rafael');

-- Só segue se o evento com esse link for o exemplo (nunca um evento de cliente).
SET @ev = (SELECT id FROM events WHERE slug = 'helena-e-rafael' AND is_demo = 1);

INSERT INTO admin_users (event_id, name, email, password_hash)
SELECT @ev, 'Helena', 'exemplo-casamento@presenzo.com.br', '!' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM admin_users WHERE email = 'exemplo-casamento@presenzo.com.br');

-- Convite da vitrine (o link mostrado na home).
INSERT INTO guests (event_id, name, role, status)
SELECT @ev, 'Mariana Costa', 'madrinha', 'pendente' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
SET @titular = (SELECT MIN(id) FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
UPDATE guests SET slug = CONCAT('mariana-', id) WHERE id = @titular AND slug IS NULL;
INSERT INTO guests (event_id, name, related_to_id, role, status)
SELECT @ev, 'Bruno Costa', @titular, 'convidado', 'pendente' FROM DUAL
WHERE @titular IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE related_to_id = @titular AND name = 'Bruno Costa');

-- Lista de presentes (19 itens).
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Geladeira', NULL, 'uploads/gifts/a7746c718ecca73626dd63a78c141dc0.webp', 3000, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Geladeira');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Fogão', NULL, 'uploads/gifts/d7aab9786ad930901b74794b48c18d99.jpg', 1000, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Fogão');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Micro-ondas', NULL, 'uploads/gifts/0c85d42487fe350aa967a4751308b97d.webp', 599, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Micro-ondas');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Máquina de Lavar Roupa', NULL, 'uploads/gifts/d6f3bf3511bf33b3b86d2c53eea52aef.webp', 1999, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Máquina de Lavar Roupa');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Máquina de Lavar Louças', NULL, 'uploads/gifts/563c2390f2002d866a1c1a7c798c9390.webp', 1999, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Máquina de Lavar Louças');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jogo de Taças de Vinho', NULL, 'uploads/gifts/8541e2a3d96896c127ed425cda575205.webp', 89.9, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jogo de Taças de Vinho');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jogo de Cama de Casal', NULL, 'uploads/gifts/2f4d361a1d220b92565854e5e735fda8.jpg', 149, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jogo de Cama de Casal');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Kit de Panelas', NULL, 'uploads/gifts/4f88f44d395f65c136ecf18d6783dac4.webp', 219, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Kit de Panelas');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Cafeteira Elétrica', NULL, 'uploads/gifts/7b67f58454003b85ae590a65123bd01a.webp', 179, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Cafeteira Elétrica');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jogo de Jantar', NULL, 'uploads/gifts/050f188cd63543a186d04f7876f2e46e.webp', 259, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jogo de Jantar');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Air Fryer', NULL, 'uploads/gifts/365c2748a1d8f842a44a6a08b93de821.webp', 349, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Air Fryer');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Aspirador de Pó', NULL, 'uploads/gifts/d664c37cdd74883fd9747a23c234df76.webp', 429, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Aspirador de Pó');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jantar Romântico na Lua de Mel', NULL, 'uploads/gifts/89908b1ab905a516434b1e606ee0d2cd.webp', 300, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jantar Romântico na Lua de Mel');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Passeio Especial na Lua de Mel', NULL, 'uploads/gifts/794f89318ad68b16f34f63fcf9d4c75a.jpg', 450, 4 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Passeio Especial na Lua de Mel');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Uma Diária da Lua de Mel', NULL, 'uploads/gifts/e3e6321fdf8d307c454a652e0e74545a.jpg', 650, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Uma Diária da Lua de Mel');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Cota para Viagem dos Noivos', NULL, 'uploads/gifts/4ef147e49cbd8dc66cffb76290beb37f.webp', 1000, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Cota para Viagem dos Noivos');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jogo de Taças e Jarra', NULL, 'uploads/gifts/3fb8141c289a48769b728ed664644f49.webp', 119, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jogo de Taças e Jarra');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Bíblia Sagrada do Casal', NULL, 'uploads/gifts/9de8f12c856ddf2d5d45b6ce7c6a7af4.webp', 70, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Bíblia Sagrada do Casal');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Dia de Spa dos Noivos', NULL, 'uploads/gifts/cdd644fb31c1539771d476c5479a4702.jpg', 399, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Dia de Spa dos Noivos');


-- Aniversário: Laura Mendes (/laura-mendes) ----------------------------------
INSERT INTO events (slug, event_type, host_name, host_name_secondary, subtitle, event_date, venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code, invite_message, share_message, cover_photo_path, cover_focus_x, cover_focus_y, music_path, color_primary, name_font, invite_style, show_countdown, show_calendar, gifts_enabled, pix_key, plan, is_demo)
SELECT 'laura-mendes', 'birthday', 'Laura Mendes', NULL, '15 anos', '2027-06-12 20:00:00', 'Espaço Villa Jardim', NULL, 'Av. das Palmeiras, 450 · Jardim América', 'https://maps.google.com/?q=Espa%C3%A7o%20Villa%20Jardim', NULL, 'Venha confortável e pronta pra dançar', 'Trinta anos merecem ser comemorados com quem eu amo. Conto com você pra essa noite!', NULL, 'uploads/covers/49811fff136b5ef8e55bded847015f15.jpg', 50, 19, 'uploads/music/3f1da501432dadcd5b00f9c236583243.mp3', '#d4694e', 'fleur', 'doors', 1, 1, 1, 'exemplo@presenzo.com.br', 'unlimited', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM events WHERE slug = 'laura-mendes');

-- Só segue se o evento com esse link for o exemplo (nunca um evento de cliente).
SET @ev = (SELECT id FROM events WHERE slug = 'laura-mendes' AND is_demo = 1);

INSERT INTO admin_users (event_id, name, email, password_hash)
SELECT @ev, 'Laura Mendes', 'exemplo-aniversario@presenzo.com.br', '!' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM admin_users WHERE email = 'exemplo-aniversario@presenzo.com.br');

-- Convite da vitrine (o link mostrado na home).
INSERT INTO guests (event_id, name, role, status)
SELECT @ev, 'Camila Rocha', 'convidado', 'pendente' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
SET @titular = (SELECT MIN(id) FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
UPDATE guests SET slug = CONCAT('camila-', id) WHERE id = @titular AND slug IS NULL;

-- Lista de presentes (12 itens).
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Livro Especial', NULL, 'uploads/gifts/ed2cae3f3233770006babe214b45ca9d.jpg', 79, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Livro Especial');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Kit de Chocolates', NULL, 'uploads/gifts/f61c7548095e82f9c7f33d5881154f6f.webp', 49, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Kit de Chocolates');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Camiseta', NULL, 'uploads/gifts/7fbeb74159172243c9cbaa054e99024d.webp', 79, 4 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Camiseta');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Perfume', NULL, 'uploads/gifts/508c06d80e18fdbba29887448fd253b4.webp', 149, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Perfume');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Fone de Ouvido', NULL, 'uploads/gifts/de262907bbd31a9ccfa2cc5be291c212.jpg', 179, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Fone de Ouvido');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Tênis', NULL, 'uploads/gifts/f23d6c2701d67aeb058b108350a0f7d5.webp', 249, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Tênis');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jantar em Restaurante', NULL, 'uploads/gifts/318026d7bfabb2948d551c2ff8cafd01.jpg', 200, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jantar em Restaurante');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Vale-compras', NULL, 'uploads/gifts/c3c356b5bba0c3c0399ab86b24a253ed.jpg', 250, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Vale-compras');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Smartwatch', NULL, 'uploads/gifts/aa35dab3acdfa11474ecf0777bc59bf1.png', 349, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Smartwatch');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Dia de Spa', NULL, 'uploads/gifts/b56d7e8cd614b76f6b086ce0c81a273c.webp', 399, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Dia de Spa');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Fim de Semana Especial', NULL, 'uploads/gifts/7eef99139836f3aa760652ac12afebff.jpg', 400, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Fim de Semana Especial');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Ajuda para Realizar um Sonho', NULL, 'uploads/gifts/e6f66cf03227652b4480c340336f4eec.jpg', 1000, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Ajuda para Realizar um Sonho');


-- Formatura: Gabriel Almeida (/gabriel-almeida) -------------------------------
INSERT INTO events (slug, event_type, host_name, host_name_secondary, subtitle, event_date, venue_name, venue_name_secondary, address, maps_url, maps_url_secondary, dress_code, invite_message, share_message, cover_photo_path, cover_focus_x, cover_focus_y, music_path, color_primary, name_font, invite_style, show_countdown, show_calendar, gifts_enabled, pix_key, plan, is_demo)
SELECT 'gabriel-almeida', 'graduation', 'Gabriel Almeida', NULL, 'Medicina · UFMG', '2027-12-11 19:00:00', 'Grande Teatro do Palácio das Artes', 'Espaço Imperial', 'Av. Afonso Pena, 1537 · Centro', 'https://maps.google.com/?q=Grande%20Teatro%20do%20Pal%C3%A1cio%20das%20Artes', 'https://maps.google.com/?q=Espa%C3%A7o%20Imperial', 'Traje social', 'Depois de seis anos de dedicação, chegou a hora de celebrar essa conquista com quem esteve ao meu lado.', NULL, 'uploads/covers/9d7c1c25eebb7b56cf7f5f282eb88bdd.jpg', 54, 30, 'uploads/music/5d534fa7b6c6e6ed0aa1f4c4252063ec.mp3', '#2f5d8a', 'pinyon', 'vertical', 1, 1, 1, 'exemplo@presenzo.com.br', 'unlimited', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM events WHERE slug = 'gabriel-almeida');

-- Só segue se o evento com esse link for o exemplo (nunca um evento de cliente).
SET @ev = (SELECT id FROM events WHERE slug = 'gabriel-almeida' AND is_demo = 1);

INSERT INTO admin_users (event_id, name, email, password_hash)
SELECT @ev, 'Gabriel Almeida', 'exemplo-formatura@presenzo.com.br', '!' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM admin_users WHERE email = 'exemplo-formatura@presenzo.com.br');

-- Convite da vitrine (o link mostrado na home).
INSERT INTO guests (event_id, name, role, status)
SELECT @ev, 'Ana Almeida', 'pais', 'pendente' FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
SET @titular = (SELECT MIN(id) FROM guests WHERE event_id = @ev AND related_to_id IS NULL);
UPDATE guests SET slug = CONCAT('ana-', id) WHERE id = @titular AND slug IS NULL;
INSERT INTO guests (event_id, name, related_to_id, role, status)
SELECT @ev, 'Carlos Almeida', @titular, 'convidado', 'pendente' FROM DUAL
WHERE @titular IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE related_to_id = @titular AND name = 'Carlos Almeida');
INSERT INTO guests (event_id, name, related_to_id, role, status)
SELECT @ev, 'Pedro Almeida', @titular, 'convidado', 'pendente' FROM DUAL
WHERE @titular IS NOT NULL AND NOT EXISTS (SELECT 1 FROM guests WHERE related_to_id = @titular AND name = 'Pedro Almeida');

-- Lista de presentes (12 itens).
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Livro Especial', NULL, 'uploads/gifts/c5f4c518678f2a00e3ae4352646a00db.jpg', 79, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Livro Especial');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Kit de Chocolates', NULL, 'uploads/gifts/368762b3d5637671c5d82aa9c1a97527.webp', 49, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Kit de Chocolates');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Camiseta', NULL, 'uploads/gifts/6b81e3d16022bbf74ef4074cb24986b6.webp', 79, 4 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Camiseta');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Perfume', NULL, 'uploads/gifts/db971f2db01fe1eb87bbda1633a77390.webp', 149, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Perfume');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Fone de Ouvido', NULL, 'uploads/gifts/f78fec7fc6312821598174bb38a97a32.jpg', 179, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Fone de Ouvido');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Tênis', NULL, 'uploads/gifts/57a544a1677f130c027ee274923edf37.webp', 249, 2 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Tênis');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Jantar em Restaurante', NULL, 'uploads/gifts/fabe3e0fa9e1520bc9ff351543b7ae81.jpg', 200, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Jantar em Restaurante');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Vale-compras', NULL, 'uploads/gifts/fb931a3ce18695ea41b213fec83ff664.jpg', 250, 5 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Vale-compras');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Smartwatch', NULL, 'uploads/gifts/8ebef72405505053e6e3087b96db2e13.png', 349, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Smartwatch');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Dia de Spa', NULL, 'uploads/gifts/ba68a337168afc7a1af45e578b6b58c1.webp', 399, 1 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Dia de Spa');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Fim de Semana Especial', NULL, 'uploads/gifts/adf8266e5329ae2e60abbe82f1de814a.jpg', 400, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Fim de Semana Especial');
INSERT INTO gifts (event_id, name, description, image_path, suggested_amount, quantity)
SELECT @ev, 'Ajuda para Realizar um Sonho', NULL, 'uploads/gifts/7907cf65e7893d25a745ee29b99b0f21.jpg', 1000, 3 FROM DUAL
WHERE @ev IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gifts WHERE event_id = @ev AND name = 'Ajuda para Realizar um Sonho');


-- Conferência: os 3 exemplos, o link da vitrine de cada um, quantas pessoas
-- e presentes. Abra cada link no site pra conferir foto e música.
SELECT e.id, e.slug, e.event_type, e.is_demo,
       CONCAT('/', e.slug, '/', (SELECT g.slug FROM guests g WHERE g.event_id = e.id AND g.related_to_id IS NULL ORDER BY g.id LIMIT 1)) AS link_vitrine,
       (SELECT COUNT(*) FROM guests g WHERE g.event_id = e.id) AS pessoas,
       (SELECT COUNT(*) FROM gifts gi WHERE gi.event_id = e.id) AS presentes,
       (SELECT COUNT(*) FROM admin_users a WHERE a.event_id = e.id) AS admins
FROM events e
WHERE e.slug IN ('helena-e-rafael', 'laura-mendes', 'gabriel-almeida');
