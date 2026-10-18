-- Promociones (imágenes y videos) que se muestran en la pantalla pública de turnos.
CREATE TABLE IF NOT EXISTS promocion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    titulo TEXT NOT NULL,
    tipo TEXT NOT NULL CHECK (tipo IN ('imagen', 'video')),
    archivo TEXT NOT NULL,
    mime TEXT NOT NULL,
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

CREATE INDEX IF NOT EXISTS idx_promocion_activo_orden ON promocion(activo, orden);
