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

### Pré-requisitos
- **Git** instalado (para clonar/atualizar o projeto).
- **Python 3.10+** instalado (usado pelo launcher `run.py` e pelo menu `start.py`).
- **Docker** + **Docker Compose** instalados e funcionando.
- **PHP 8.1+** instalado localmente apenas se for executar sensores fora do Docker (`sensors/sensor.php`).

#### Docker por sistema operacional

1. **Windows 10/11**
1. Instale o **Docker Desktop**.
2. Habilite **WSL2** e **Virtual Machine Platform**.
3. No Docker Desktop, ative integracao com sua distro WSL.
4. Valide com:

```bash
docker --version
docker compose version
docker run hello-world
```

2. **macOS (Intel/Apple Silicon)**
1. Instale o **Docker Desktop for Mac** (versao correta para seu chip).
2. Abra o Docker Desktop e conclua as permissoes iniciais.
3. Valide com:

```bash
docker --version
docker compose version
docker run hello-world
```

3. **Linux (Ubuntu/Debian)**
1. Instale Docker de forma rapida com o script oficial:

```bash
curl -fsSL https://get.docker.com | sh
```

2. Permita executar Docker sem `sudo`:

```bash
sudo usermod -aG docker $USER
newgrp docker
```

3. Valide instalacao:

```bash
docker --version
docker compose version
docker run hello-world
```

#### Validacao minima antes de subir o projeto

```bash
python3 --version
docker --version
docker compose version
```

Se os 3 comandos acima responderem corretamente, o ambiente esta pronto para o `python run.py`.

### Launcher por Sistema Operacional (novo)
Use o launcher principal abaixo, que detecta o sistema operacional e chama o executor correto:

```bash
python3 run.py
```

No primeiro uso, se `.env` nao existir, o executor cria automaticamente a partir de `.env.example`.
Depois disso, edite o `.env` com os hosts/ports do seu ambiente local ou EC2.

### Passo 1: Subir a Infraestrutura e Serviços
Na pasta raiz do projeto (`smartbuilding/`), execute:
```bash
docker-compose up --build
```
Isso iniciará:
1. **RabbitMQ** na porta `5672` (painel administrativo em `http://localhost:15672` - guest/guest).
2. **Redis** na porta `6379`.
3. **MySQL Primary** na porta `3306` (com carga automática de `schema.sql`).
4. **MySQL Replica** na porta `3307` (conectado à rede interna).
5. **Servidor Swoole** nas portas `8000` (HTTP) e `9502` (WebSocket).
6. **Worker Python** que se conectará automaticamente ao RabbitMQ e MySQL.

## ⚙️ Configuração de Variáveis (.env) para AWS EC2

Padronize todas as configuracoes em um unico arquivo `.env` na raiz de `smartbuilding/`.

1. Copie o template:

```bash
cp .env.example .env
```

2. Em EC2, ajuste principalmente:
- `BACKEND_HTTP_HOST` e `BACKEND_WS_HOST` com DNS publico ou IP publico da instância.
- `RABBITMQ_HOST`, `REDIS_HOST`, `MYSQL_PRIMARY_HOST` para o endpoint que o cliente local vai usar.
- `MYSQL_*`, `RABBITMQ_*` para credenciais reais (evite defaults em producao).

3. Se tudo estiver no mesmo `docker-compose`, mantenha os valores `*_INTERNAL` com os nomes de servico (`rabbitmq`, `redis`, `mysql-primary`, `mysql-replica`).

4. Abra no Security Group apenas as portas necessarias:
- 8000 (HTTP API)
- 9502 (WebSocket)
- 5672/15672 (RabbitMQ, somente se realmente precisar externo)
- 6379 (Redis, preferencialmente privado)
- 3306/3307 (MySQL, preferencialmente privado)

Recomendacao: em EC2, exponha publicamente apenas `8000` e `9502`; mantenha banco, redis e broker em rede privada/VPC.

## ☁️ AWS EC2 (instâncias já criadas): instalação e rede

Esta secao cobre o passo a passo do que instalar e como configurar comunicacao interna entre maquinas e acesso externo seguro.

### Topologia recomendada

Opcao A (3 EC2, mais simples):
1. `ec2-app`: Swoole (HTTP + WebSocket) e dashboard.
2. `ec2-worker`: Python worker e sensores (quando quiser gerar carga remota).
3. `ec2-data`: RabbitMQ, Redis, MySQL primary e MySQL replica.

Opcao B (6 EC2, mais isolada):
1. `ec2-app`
2. `ec2-worker`
3. `ec2-rabbitmq`
4. `ec2-redis`
5. `ec2-mysql-primary`
6. `ec2-mysql-replica`

### 1) Instalar dependencias em cada instancia

Comandos para Ubuntu 22.04/24.04:

```bash
sudo apt-get update -y
sudo apt-get install -y ca-certificates curl gnupg lsb-release git

# Docker
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo $VERSION_CODENAME) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update -y
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

sudo usermod -aG docker $USER
newgrp docker

# Python launcher
sudo apt-get install -y python3 python3-pip
```

### 2) Security Groups (acesso entre máquinas e externo)

Use dois grupos:

1. `sg-app-public` (para `ec2-app`)
1. Inbound externo: `8000/tcp` (API), `9502/tcp` (WebSocket), `22/tcp` (SSH do seu IP).
2. Outbound: liberado para VPC.

2. `sg-data-private` (para data services)
1. Inbound SOMENTE do SG da aplicacao/worker:
1. `5672/tcp` RabbitMQ
2. `15672/tcp` RabbitMQ UI (opcional)
3. `6379/tcp` Redis
4. `3306/tcp` MySQL primary
5. `3307/tcp` MySQL replica (se mapear externo no host)
2. Sem inbound aberto para `0.0.0.0/0` nessas portas.

Se usar 6 EC2, aplique regras por SG de origem (ex.: apenas `sg-app-public` e `sg-worker` podem acessar `sg-data-private`).

### 3) Clonar projeto e preparar variaveis em cada no

Em cada instancia:

```bash
git clone <URL_DO_REPOSITORIO>
cd s0ftware-concorrente-distribuido/smartbuilding
cp .env.example .env
```

Exemplo de `.env` no `ec2-app` (3 EC2):

```env
BACKEND_HTTP_HOST=<PUBLIC_DNS_EC2_APP>
BACKEND_HTTP_PORT=8000
BACKEND_WS_HOST=<PUBLIC_DNS_EC2_APP>
BACKEND_WS_PORT=9502

RABBITMQ_HOST=<PRIVATE_IP_EC2_DATA>
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASS=guest

REDIS_HOST=<PRIVATE_IP_EC2_DATA>
REDIS_PORT=6379

MYSQL_PRIMARY_HOST=<PRIVATE_IP_EC2_DATA>
MYSQL_PRIMARY_PORT=3306
MYSQL_REPLICA_HOST=<PRIVATE_IP_EC2_DATA>
MYSQL_REPLICA_PORT=3307
MYSQL_DB=smartbuilding
MYSQL_USER=sb_user
MYSQL_PASSWORD=sb_password
MYSQL_ROOT_PASSWORD=root_password
```

### 4) Subir servicos por no (3 EC2)

1. No `ec2-data` (RabbitMQ/Redis/MySQL):

```bash
docker compose up -d rabbitmq redis mysql-primary mysql-replica
```

2. No `ec2-app` (Swoole):

```bash
docker compose up -d swoole-server
```

3. No `ec2-worker` (Worker Python):

```bash
docker compose up -d python-worker
```

4. Sensores (no `ec2-worker` ou local):

```bash
python3 run.py
```

### 5) Acesso externo ao dashboard

O arquivo `dashboard/index.html` usa o host do navegador para montar:
1. WebSocket: `ws://<host>:9502`
2. API: `http://<host>:8000`

Entao abra no navegador com o host publico da `ec2-app`.
Exemplo:
1. `http://<PUBLIC_DNS_EC2_APP>:8000` (API)
2. `ws://<PUBLIC_DNS_EC2_APP>:9502` (WS)

### 6) Checklist rapido de validacao

1. `docker ps` em cada no para confirmar containers ativos.
2. `curl http://<PUBLIC_DNS_EC2_APP>:8000/api/salas` retorna JSON.
3. RabbitMQ UI abre em `http://<PRIVATE_OR_BASTION>:15672` (se habilitado).
4. Ao iniciar sensores, aparecem leituras no log do worker e atualizacao em tempo real no dashboard.

### 7) Recomendacoes de seguranca para uso externo

1. Nao exponha MySQL, Redis e RabbitMQ publicamente.
2. Restrinja SSH para seu IP fixo.
3. Use TLS no balanceador/proxy para HTTP/WS em producao.
4. Troque credenciais default (`guest/guest`, `sb_password`, `root_password`).
5. Considere mover MySQL para RDS e Redis para ElastiCache em ambiente final.

### Passo 2: Acessar o Dashboard
Abra seu navegador no arquivo `smartbuilding/dashboard/index.html`. 
- Ele se conectará ao servidor WebSocket (`ws://localhost:9502`).
- Você verá o status no cabeçalho mudar para **Conectado** (verde pulsante).

### Passo 3: Iniciar a Simulação dos Sensores
Abra um terminal no host local e navegue até `smartbuilding/sensors/`.

**No Windows (PowerShell):**
```powershell
.\run_sensors.ps1 -NumSensors 8 -Interval 3
```

**No Linux/macOS (Bash):**
```bash
chmod +x run_sensors.sh
./run_sensors.sh 8 3
```
Isso simulará 8 sensores de salas diferentes enviando dados concorrentemente a cada 3 segundos.

---

## 📋 Checklist de Requisitos e Implementação

Consulte o arquivo [task_checklist.md](./task_checklist.md) na raiz para verificar a cobertura completa do checklist de requisitos da disciplina de Software Concorrente e Distribuído.
