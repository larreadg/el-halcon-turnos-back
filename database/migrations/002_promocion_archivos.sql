-- Una promoción puede tener varios archivos (imágenes y/o videos).
-- Los archivos pasan de la tabla promocion a promocion_archivo.

-- 1. Guardar los archivos existentes antes de reconstruir promocion
CREATE TEMP TABLE promocion_archivo_migracion AS
    SELECT id AS promocion_id, tipo, archivo, mime, creado_el FROM promocion;

-- 2. Reconstruir promocion sin las columnas del archivo
CREATE TABLE promocion_nueva (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    titulo TEXT NOT NULL,
    duracion_segundos INTEGER NOT NULL DEFAULT 10,
    orden INTEGER NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1,

    creado_por INTEGER,
    modificado_por INTEGER,

    creado_el TEXT NOT NULL,
    modificado_el TEXT,

    FOREIGN KEY (creado_por) REFERENCES usuario(id),
    FOREIGN KEY (modificado_por) REFERENCES usuario(id)
);

INSERT INTO promocion_nueva (id, titulo, duracion_segundos, orden, activo, creado_por, modificado_por, creado_el, modificado_el)
    SELECT id, titulo, duracion_segundos, orden, activo, creado_por, modificado_por, creado_el, modificado_el FROM promocion;

DROP TABLE promocion;
ALTER TABLE promocion_nueva RENAME TO promocion;

CREATE INDEX idx_promocion_activo_orden ON promocion(activo, orden);

-- 3. Tabla de archivos de cada promoción
CREATE TABLE promocion_archivo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    promocion_id INTEGER NOT NULL,
    tipo TEXT NOT NULL CHECK (tipo IN ('imagen', 'video')),
    archivo TEXT NOT NULL,
    mime TEXT NOT NULL,
    orden INTEGER NOT NULL DEFAULT 0,

    creado_el TEXT NOT NULL,

    FOREIGN KEY (promocion_id) REFERENCES promocion(id) ON DELETE CASCADE
);

CREATE INDEX idx_promocion_archivo_promocion ON promocion_archivo(promocion_id, orden);

INSERT INTO promocion_archivo (promocion_id, tipo, archivo, mime, orden, creado_el)
    SELECT promocion_id, tipo, archivo, mime, 0, creado_el FROM promocion_archivo_migracion;

DROP TABLE promocion_archivo_migracion;
