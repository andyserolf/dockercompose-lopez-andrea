CREATE TABLE IF NOT EXISTS reservaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  espacio VARCHAR(100) NOT NULL,
  solicitante VARCHAR(100) NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  estado ENUM('pendiente','confirmada','cancelada') NOT NULL DEFAULT 'pendiente',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_horas CHECK (hora_fin > hora_inicio)
);

INSERT INTO reservaciones (espacio, solicitante, fecha, hora_inicio, hora_fin, estado) VALUES
  ('Sala de juntas A', 'Ana Lopez', '2026-11-10', '09:00', '10:00', 'confirmada'),
  ('Laboratorio 2', 'Carlos Ruiz', '2026-11-10', '11:00', '13:00', 'pendiente'),
  ('Auditorio', 'Maria Perez', '2026-11-12', '16:00', '18:00', 'pendiente');