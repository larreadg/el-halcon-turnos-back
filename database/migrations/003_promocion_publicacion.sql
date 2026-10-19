-- Días de publicación de cada promoción en la pantalla pública.
-- fecha_desde / fecha_hasta: rango opcional (YYYY-MM-DD, inclusive). NULL = sin límite.
-- dias_semana: días ISO separados por coma (1 = lunes ... 7 = domingo). NULL = todos los días.
ALTER TABLE promocion ADD COLUMN fecha_desde TEXT;
ALTER TABLE promocion ADD COLUMN fecha_hasta TEXT;
ALTER TABLE promocion ADD COLUMN dias_semana TEXT;
