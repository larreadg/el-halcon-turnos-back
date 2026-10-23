-- Marca de "volver a llamar" de un turno en la pantalla pública (se consume al mostrarse).
ALTER TABLE turno ADD COLUMN llamar_nuevamente INTEGER NOT NULL DEFAULT 0;
