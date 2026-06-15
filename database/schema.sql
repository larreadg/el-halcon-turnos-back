CREATE TABLE rol (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    nombre TEXT NOT NULL UNIQUE,
    activo INTEGER NOT NULL DEFAULT 1,

    creado_el TEXT NOT NULL
);

INSERT INTO rol (nombre, creado_el) VALUES ('ADMIN', CURRENT_TIMESTAMP), ('MERCHANT', CURRENT_TIMESTAMP);

CREATE TABLE usuario (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    usuario TEXT NOT NULL UNIQUE,
    clave_hash TEXT NOT NULL,
    nombres TEXT,
    apellidos TEXT,
    documento TEXT UNIQUE,
    puesto TEXT,
    activo INTEGER NOT NULL DEFAULT 1,
    rol_id INTEGER,

    creado_por INTEGER,
    modificado_por INTEGER,

    creado_el TEXT NOT NULL,
    modificado_el TEXT,

    FOREIGN KEY (rol_id) REFERENCES rol(id),
    FOREIGN KEY (creado_por) REFERENCES usuario(id),
    FOREIGN KEY (modificado_por) REFERENCES usuario(id)
);

CREATE INDEX idx_usuario_rol_id ON usuario(rol_id);
CREATE INDEX idx_usuario_activo ON usuario(activo);

CREATE TABLE login_intento (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    ip             TEXT NOT NULL UNIQUE,
    captcha_token  TEXT,
    captcha_valor  TEXT,
    captcha_expira TEXT
);

CREATE INDEX idx_login_intento_captcha_expira ON login_intento(captcha_expira);

CREATE TABLE turno_configuracion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    fecha TEXT NOT NULL,
    numero_inicial INTEGER NOT NULL,
    activo INTEGER NOT NULL DEFAULT 1,

    creado_por INTEGER,
    modificado_por INTEGER,

    creado_el TEXT NOT NULL,
    modificado_el TEXT,

    FOREIGN KEY (creado_por) REFERENCES usuario(id),
    FOREIGN KEY (modificado_por) REFERENCES usuario(id)
);

CREATE INDEX idx_turno_configuracion_fecha ON turno_configuracion(fecha);
CREATE UNIQUE INDEX idx_turno_configuracion_activa ON turno_configuracion(fecha) WHERE activo = 1;

CREATE TABLE turno (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    configuracion_id INTEGER NOT NULL,
    fecha TEXT NOT NULL,
    numero INTEGER NOT NULL,
    estado TEXT NOT NULL DEFAULT 'llamado' CHECK (estado IN ('llamado', 'finalizado', 'ausente')),
    merchant_id INTEGER,

    llamado_el TEXT NOT NULL,
    finalizado_el TEXT,

    creado_por INTEGER,
    modificado_por INTEGER,

    creado_el TEXT NOT NULL,
    modificado_el TEXT,

    UNIQUE (configuracion_id, numero),
    FOREIGN KEY (configuracion_id) REFERENCES turno_configuracion(id),
    FOREIGN KEY (merchant_id) REFERENCES usuario(id),
    FOREIGN KEY (creado_por) REFERENCES usuario(id),
    FOREIGN KEY (modificado_por) REFERENCES usuario(id)
);

CREATE INDEX idx_turno_fecha_estado ON turno(fecha, estado);
CREATE INDEX idx_turno_merchant_id ON turno(merchant_id);