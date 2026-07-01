CREATE DATABASE IF NOT EXISTS smartbuilding;
USE smartbuilding;

DROP TABLE IF EXISTS historico_leituras;
CREATE TABLE historico_leituras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sala VARCHAR(50) NOT NULL,
    energia DOUBLE NOT NULL,
    presenca TINYINT(1) NOT NULL,
    luz TINYINT(1) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sala_criado (sala, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial data for testing
INSERT INTO historico_leituras (sala, energia, presenca, luz, criado_em) VALUES
('101', 1.2, 1, 1, NOW() - INTERVAL 1 HOUR),
('101', 1.1, 1, 1, NOW() - INTERVAL 50 MINUTE),
('101', 1.3, 0, 1, NOW() - INTERVAL 40 MINUTE),
('102', 0.1, 0, 0, NOW() - INTERVAL 30 MINUTE),
('201', 2.5, 1, 1, NOW() - INTERVAL 20 MINUTE),
('302', 0.0, 0, 0, NOW() - INTERVAL 10 MINUTE);
