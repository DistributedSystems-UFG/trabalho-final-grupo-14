# Checklist de Implementação — Smart Building IoT

Este checklist foi gerado com base nos requisitos especificados no documento `projeto_smartbuilding_iot.md` para a disciplina de **Software Concorrente e Distribuído**.

Ele divide as tarefas entre o que já foi **Estruturado (Base Pronta)** e o que resta a ser feito nos **Próximos Passos (Deployment & Homologação)**.

---

## 🛠️ 1. Infraestrutura e Docker Local

| Requisito do Projeto | Ação Tomada / Implementação | Status |
| :--- | :--- | :---: |
| **RabbitMQ Configurado** | Criado `definitions.json` (queues: `swoole_sensor_queue`, `python_sensor_queue`, `alertas`; exchange: `sensor_data_exchange`) e `rabbitmq.conf` para carga automática. | **Feito** |
| **Redis Cache** | Configurado serviço Alpine no `docker-compose.yml`. | **Feito** |
| **MySQL Primary + Replica** | Configurado `mysql-primary` com binlog ativo e `mysql-replica` com flag read-only no Docker Compose. | **Feito** |
| **MySQL Schema** | Criado `infra/mysql/schema.sql` com criação da tabela `historico_leituras` e dados semente de teste carregados no boot. | **Feito** |
| **Docker Compose Unificado** | Orquestrado todos os 6 componentes locais (`rabbitmq`, `redis`, `mysql-primary`, `mysql-replica`, `swoole-server`, `python-worker`) com healthchecks e dependências de inicialização. | **Feito** |

---

## 💻 2. Servidor Principal (PHP + Swoole)

| Requisito do Projeto | Ação Tomada / Implementação | Status |
| :--- | :--- | :---: |
| **Swoole HTTP Server** | Implementado endpoint `/api/salas` (busca dados em tempo real do Redis) e `/api/salas/{sala}/historico` (busca do MySQL Replica). | **Feito** |
| **Swoole WebSocket Server** | Configurado WebSocket na porta padrão `8000` e porta secundária `9502` com eventos `onOpen`, `onMessage`, `onClose`. | **Feito** |
| **Swoole Background Consumers** | Criados dois processos filho nativos (`Swoole\Process`): um consome a fila de sensores (atualiza Redis e manda broadcast) e outro consome a fila de alertas (manda broadcast). | **Feito** |
| **Swoole IPC (Inter-Process)** | Implementado tratamento de `PipeMessage` para que os consumidores em processos paralelos enviem mensagens de broadcast para o Worker principal de sockets. | **Feito** |
| **Redis Service Layer** | Criado `RedisService.php` com métodos de leitura/escrita e controle concorrente usando `SETNX` (lock distribuído). | **Feito** |
| **MySQL Service Layer** | Criado `MysqlService.php` com suporte a escrita no Primary e leitura na Replica (com failover transparente para o Primary se a replica cair). | **Feito** |
| **RabbitMQ Service Layer** | Criado `RabbitService.php` com conexão manual, publicação e consumo configurado com ACK manual. | **Feito** |

---

## 🐍 3. Worker de Análise (Python)

| Requisito do Projeto | Ação Tomada / Implementação | Status |
| :--- | :--- | :---: |
| **requirements.txt** | Definido com `pika`, `PyMySQL`, `redis` e `cryptography`. | **Feito** |
| **PyMySQL Integration** | Implementada escrita de histórico no MySQL Primary com reconexão automática e retentativas se o banco falhar. | **Feito** |
| **Detecção de Anomalias** | Criado `analyzer.py` contendo regras de negócio: detecção de sala vazia com luz ligada (`luz_acesa_vazia`) e consumo de energia > 5.0 kW (`consumo_alto`). | **Feito** |
| **RabbitMQ Consumption** | Consome `python_sensor_queue` com prefetch de 1. Só envia **ACK** à mensagem após garantir que ela foi salva no MySQL. Se falhar, faz **NACK** e re-enfileira. | **Feito** |
| **Alerta Push** | Publica mensagens de anomalias detectadas diretamente na fila `alertas`. | **Feito** |

---

## 📡 4. Sensores Simulados

| Requisito do Projeto | Ação Tomada / Implementação | Status |
| :--- | :--- | :---: |
| **Sensor de Sala Individual** | Script `sensor.php` simula ocupação, estado de lâmpadas e cálculo de consumo coerente (com flutuações e indução aleatória de anomalias para teste). | **Feito** |
| **run_sensors.sh (Linux/macOS)** | Script Bash para lançar múltiplos sensores paralelos em background e desligar todos de forma limpa ao pressionar Ctrl+C. | **Feito** |
| **run_sensors.ps1 (Windows)** | Script PowerShell equivalente para Windows, facilitando testes locais imediatos no ambiente do usuário. | **Feito** |

---

## 📊 5. Interface do Operador (Dashboard)

| Requisito do Projeto | Ação Tomada / Implementação | Status |
| :--- | :--- | :---: |
| **Visual Premium / Estética** | Implementado design em Glassmorphism, paleta escura (Deep Blue/Slate), ícones Lucide modernos e animações de status (pulsos verdes/amarelos). | **Feito** |
| **WebSocket Connection** | Reestabelece conexão automática em caso de queda (retentativa de 3s). Mostra o status do servidor na barra de cabeçalho em tempo real. | **Feito** |
| **Atualização em Tempo Real** | Grid dinâmico de cards de salas atualizado imediatamente a cada leitura recebida. | **Feito** |
| **Feed de Alertas Lateral** | Alertas de anomalias deslizam na lateral esquerda em tempo real, com tags distintas e timestamps. | **Feito** |
| **Consulta Síncrona de Histórico** | Ao clicar em "Ver Histórico", faz requisição HTTP para a API do Swoole, busca agregados (média, pico) e exibe em tabela modal. | **Feito** |

---

## ⚠️ 6. Próximos Passos (O que não foi feito / A fazer no deploy)

| Requisito do Projeto | Ação Pendente | Status |
| :--- | :--- | :---: |
| **AWS EC2 Provisioning** | Criar as 5 instâncias EC2 na AWS dentro da mesma VPC e configurar os Grupos de Segurança. | **Pendente** |
| **Replicação MySQL Ativa** | No Docker local, os bancos iniciam independentes. Para ter replicação Master-Slave real, é preciso executar comandos de vinculação `CHANGE REPLICATION SOURCE TO...` pós-inicialização dos containers. | **Pendente** |
| **Deploy em Produção** | Copiar cada pasta do projeto para sua respectiva instância EC2 e iniciar os processos locais ou serviços Systemd. | **Pendente** |
| **Gravação de Vídeo** | Executar o cenário de demonstração local e gravar o vídeo explicativo da entrega. | **Pendente** |
