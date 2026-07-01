# Smart Building IoT — Sistema de Monitoramento Concorrente e Distribuído

Este projeto consiste em um sistema distribuído em tempo real para monitoramento e análise de sensores IoT (energia, iluminação e ocupação) de um prédio inteligente (Smart Building), desenvolvido para a disciplina de **Software Concorrente e Distribuído**.

---

## 🚀 Arquitetura do Sistema

```
[Sensores Simulados]          [Operadores / Dashboard]
  (PHP scripts)                  (navegador web)
       │                               │
       │ publica leitura               │ WebSocket (tempo real, porta 9502)
       ▼                               │ HTTP REST (consultas, porta 8000)
  [RabbitMQ]                           │
       │                               ▼
       │ consome fila          [Servidor PHP + Swoole]
       │                         - HTTP REST (HttpHandler)
       ▼                         - WebSocket (WsHandler)
  [Worker Python]                - Coroutines / Background Processes
  (análise, alertas)                   │
                                       ├──► Redis  (estado atual por sala)
                                       └──► MySQL  (histórico de leituras)
                                              primary ◄──► replica
```

### Divisão de Responsabilidades
- **Sensores Simulados (PHP)**: Enviam medições de energia, luz e ocupação a cada $N$ segundos para a exchange `sensor_data_exchange` do RabbitMQ.
- **RabbitMQ**: Distribui as mensagens dos sensores para duas filas distintas:
  - `swoole_sensor_queue`: Consumida pelo servidor Swoole para atualizar a memória RAM rápida (Redis) e notificar os navegadores.
  - `python_sensor_queue`: Consumida pelo Worker Python para salvar o histórico no MySQL Primary e analisar anomalias.
- **Servidor Principal (PHP + Swoole)**:
  - Serve a API REST (`GET /api/salas` e `GET /api/salas/{sala}/historico`) no banco réplica.
  - Gerencia conexões WebSocket ativas (portas 8000 e 9502) para push em tempo real.
  - Roda processos background integrados para consumir RabbitMQ de forma concorrente.
- **Worker de Análise (Python)**:
  - Consome leituras, persiste no MySQL Primary.
  - Executa regras de análise (ex: detecção de sala vazia com luz acesa, picos de consumo acima de 5 kW).
  - Publica alertas identificados na fila `alertas` do RabbitMQ para que o Swoole envie push aos navegadores.
- **Bancos de Dados**:
  - **Redis**: Chave-valor em memória para o estado atual instantâneo de cada sala.
  - **MySQL (Primary + Replica)**: Persistência histórica com réplica de leitura de dados.

---

## 📂 Estrutura do Projeto

A base estrutural está configurada da seguinte forma:

```
smartbuilding/
├── dashboard/               # Frontend (HTML5 + CSS Glassmorphism + JS Puro)
│   └── index.html           # Tela principal com WebSocket client
├── infra/                   # Arquivos de configuração de infraestrutura
│   ├── mysql/
│   │   └── schema.sql       # Schema do banco e sementes iniciais
│   └── rabbitmq/
│       ├── definitions.json # Filas, exchanges e bindings pré-configurados
│       └── rabbitmq.conf    # Arquivo de configuração de carga de definições
├── sensors/                 # Sensores simulados (PHP CLI)
│   ├── sensor.php           # Script do sensor individual
│   ├── run_sensors.sh       # Script bash para rodar N sensores em paralelo
│   └── run_sensors.ps1      # Script PowerShell para Windows
├── server/                  # Servidor PHP + Swoole
│   ├── handlers/
│   │   ├── HttpHandler.php  # Rotas REST da API
│   │   └── WsHandler.php    # Gerenciador de eventos WebSocket
│   ├── services/
│   │   ├── RedisService.php # Conexão e comandos Redis (com Locks SETNX)
│   │   ├── MysqlService.php # Conexão MySQL (Primary + Replica Failover)
│   │   └── RabbitService.php# Conexão e consumo manual ACK do RabbitMQ
│   ├── config.php           # Arquivo de configurações locais/Docker
│   ├── server.php           # Ponto de entrada do servidor Swoole
│   ├── composer.json        # Dependências PHP (php-amqplib, predis)
│   └── Dockerfile           # Imagem Docker do Swoole com extensões necessárias
├── worker/                  # Worker Python
│   ├── requirements.txt     # Dependências Python (pika, pymysql, redis)
│   ├── Dockerfile           # Imagem Docker do Python Worker
│   ├── analyzer.py          # Lógica isolada de regras de anomalias
│   └── worker.py            # Consumidor RabbitMQ e persistência
├── docker-compose.yml       # Orquestrador local de serviços
└── README.md
```

---

## 🛠️ Como Executar o Projeto Localmente
## Pré-requisitos

1. Git instalado.
2. Python 3.10+ instalado.
3. Docker e Docker Compose funcionando.
4. PHP 8.1+ apenas se quiser rodar sensores fora do Docker.

## Instalação rápida de dependências

### Windows 10/11

1. Instale Docker Desktop.
2. Ative WSL2.
3. Valide:

```bash
docker --version
docker compose version
docker run hello-world
python --version
```

### macOS

1. Instale Docker Desktop (Intel/Apple Silicon).
2. Valide:

```bash
docker --version
docker compose version
docker run hello-world
python3 --version
```

### Linux (Ubuntu/Debian)

1. Instale Docker e Python:

```bash
sudo apt-get update -y
sudo apt-get install -y git python3 python3-pip
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker
```

2. Valide:

```bash
docker --version
docker compose version
docker run hello-world
python3 --version
```

## Execução local (1 máquina)

1. Entre na pasta do projeto e prepare variáveis:

```bash
cd smartbuilding
cp .env.example .env
```

2. Suba tudo:

```bash
docker compose up -d --build
```

3. Acesse a dashboard:

```text
http://localhost:8000/index.html
```

4. Se quiser iniciar sensores localmente fora do Docker:

```bash
python3 run.py
```

## Execução em 3 EC2 (simples e direta)

Topologia:
1. ec2-app: `swoole-server`
2. ec2-worker: `python-worker`
3. ec2-data: `rabbitmq`, `redis`, `mysql-primary`, `mysql-replica`

### 1) Rede entre máquinas (Security Groups)

1. ec2-app (pública): liberar `22`, `8000`, `9502`.
2. ec2-worker (privada): liberar `22`.
3. ec2-data (privada): liberar de ec2-app e ec2-worker as portas `5672`, `15672` (opcional), `6379`, `3306`, `3307`.
4. Não abrir portas de dados para `0.0.0.0/0`.

### 2) Preparar projeto em cada EC2

Em cada máquina:

```bash
git clone <URL_DO_REPOSITORIO>
cd trabalho-final-grupo-14/smartbuilding
cp .env.example .env
```

Considere este exemplo de IPs privados:
1. ec2-app: `172.31.10.10`
2. ec2-worker: `172.31.10.20`
3. ec2-data: `172.31.10.30`

### 3) Ajustar `.env` por máquina

No ec2-app, defina:

```env
BACKEND_HTTP_HOST=<DNS_PUBLICO_EC2_APP>
BACKEND_HTTP_PORT=8000
BACKEND_WS_HOST=<DNS_PUBLICO_EC2_APP>
BACKEND_WS_PORT=9502

RABBITMQ_HOST_INTERNAL=172.31.10.30
RABBITMQ_PORT_INTERNAL=5672
REDIS_HOST_INTERNAL=172.31.10.30
REDIS_PORT_INTERNAL=6379
MYSQL_PRIMARY_HOST_INTERNAL=172.31.10.30
MYSQL_PRIMARY_PORT_INTERNAL=3306
MYSQL_REPLICA_HOST_INTERNAL=172.31.10.30
MYSQL_REPLICA_PORT_INTERNAL=3307

MYSQL_DB=smartbuilding
MYSQL_USER=sb_user
MYSQL_PASSWORD=sb_password
RABBITMQ_USER=guest
RABBITMQ_PASS=guest
```

No ec2-worker, defina:

```env
RABBITMQ_HOST_INTERNAL=172.31.10.30
RABBITMQ_PORT_INTERNAL=5672
REDIS_HOST_INTERNAL=172.31.10.30
REDIS_PORT_INTERNAL=6379
MYSQL_PRIMARY_HOST_INTERNAL=172.31.10.30
MYSQL_PRIMARY_PORT_INTERNAL=3306

MYSQL_DB=smartbuilding
MYSQL_USER=sb_user
MYSQL_PASSWORD=sb_password
RABBITMQ_USER=guest
RABBITMQ_PASS=guest
```

No ec2-data, defina (mínimo):

```env
MYSQL_DB=smartbuilding
MYSQL_USER=sb_user
MYSQL_PASSWORD=sb_password
MYSQL_ROOT_PASSWORD=root_password
RABBITMQ_USER=guest
RABBITMQ_PASS=guest
```

### 4) Subir serviços na ordem correta

No ec2-data:

```bash
docker compose up -d rabbitmq redis mysql-primary mysql-replica
```

No ec2-app:

```bash
docker compose up -d --no-deps swoole-server
```

No ec2-worker:

```bash
docker compose up -d --no-deps python-worker
```

### 5) Acesso e validação

1. Dashboard: `http://<DNS_PUBLICO_EC2_APP>:8000/index.html`
2. API: `http://<DNS_PUBLICO_EC2_APP>:8000/api/salas`
3. Verificação de serviços: `docker compose ps` em cada EC2.
4. Opcional: iniciar sensores no ec2-worker com `python3 run.py`.

## Notas importantes

1. O front usa o host da URL para montar API e WebSocket automaticamente.
2. Para exposição pública segura, prefira publicar apenas `8000` e `9502`.
3. Troque credenciais padrão antes de ambiente real.

---

## 📋 Checklist de Requisitos e Implementação

Consulte o arquivo [task_checklist.md](./task_checklist.md) na raiz para verificar a cobertura completa do checklist de requisitos da disciplina de Software Concorrente e Distribuído.
