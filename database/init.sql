CREATE TABLE IF NOT EXISTS reservaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    espacio VARCHAR(100) NOT NULL,
    solicitante VARCHAR(100) NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    estado VARCHAR(20) DEFAULT 'pendiente',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO reservaciones (espacio, solicitante, fecha, hora_inicio, hora_fin, estado) VALUES
('Sala A', 'Juan Pérez', '2026-06-01', '09:00:00', '10:00:00', 'pendiente'),
('Sala B', 'María Gómez', '2026-06-02', '11:00:00', '12:00:00', 'confirmada'),
('Auditorio', 'Carlos Ruiz', '2026-06-03', '15:00:00', '17:00:00', 'pendiente');