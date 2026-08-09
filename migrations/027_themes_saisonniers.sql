USE `medexpiry`;

-- Migration 027 — Thèmes saisonniers

-- Thèmes saisonniers Cadurix
INSERT INTO `themes` (`nom`, `couleur1`, `couleur2`, `accent`, `logo_variante`, `sidebar_mode`, `est_defaut`) VALUES
  ('🏖️ Plage',        '#0077B6', '#00B4D8', '#90E0EF', 'A', 'dark',  0),
  ('🏔️ Montagne',     '#2D4A3E', '#4A7C59', '#8BC34A', 'B', 'dark',  0),
  ('🎄 Noël',         '#1B4332', '#D62828', '#FFD700', 'A', 'dark',  0),
  ('🎃 Halloween',    '#1A0A00', '#E85D04', '#9B5DE5', 'C', 'dark',  0),
  ('🌸 Printemps',    '#6B2D8B', '#C77DFF', '#FFB3C6', 'A', 'light', 0),
  ('🌊 Océan',        '#023E8A', '#0096C7', '#48CAE4', 'B', 'dark',  0),
  ('🍂 Automne',      '#6B3A2A', '#CC5500', '#FFB347', 'A', 'dark',  0),
  ('❄️ Hiver',        '#0A1628', '#1E3A5F', '#AED6F1', 'B', 'dark',  0),
  ('🌺 Été',          '#7B2D8B', '#FF6B6B', '#FFE66D', 'C', 'dark',  0),
  ('🎆 Nouvel An',    '#0D0D0D', '#FFD700', '#FF4500', 'A', 'dark',  0);
