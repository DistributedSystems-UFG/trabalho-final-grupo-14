#!/usr/bin/env python3
import os
import sys
import subprocess
import time
import shutil


def load_dotenv(dotenv_path=".env"):
    if not os.path.isfile(dotenv_path):
        return
    try:
        with open(dotenv_path, "r", encoding="utf-8") as f:
            for raw_line in f:
                line = raw_line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                key = key.strip()
                value = value.strip().strip('"').strip("'")
                if key and key not in os.environ:
                    os.environ[key] = value
    except Exception:
        pass


def env_or_default(key, fallback):
    return os.getenv(key, fallback)

# Enable ANSI escape sequences on Windows command prompt if needed
if os.name == 'nt':
    try:
        import ctypes
        kernel32 = ctypes.windll.kernel32
        kernel32.SetConsoleMode(kernel32.GetStdHandle(-11), 7)
    except Exception:
        pass

def print_green(text):
    print(f"\033[92m{text}\033[0m")

def print_red(text):
    print(f"\033[91m{text}\033[0m")

def print_yellow(text):
    print(f"\033[93m{text}\033[0m")

def print_cyan(text):
    print(f"\033[96m{text}\033[0m")

def print_header(title):
    print("\n" + "=" * 60)
    print(f" {title.center(58)} ")
    print("=" * 60)

def get_input(prompt, default):
    value = input(f"{prompt} [\033[94m{default}\033[0m]: ").strip()
    return value if value else default

def check_docker():
    if not shutil.which("docker"):
        print_red("Erro: Docker não encontrado no sistema. Por favor, instale o Docker para continuar.")
        return False
    if not shutil.which("docker-compose") and not shutil.which("docker"):
        # Note: docker compose can be run as 'docker compose' in modern docker
        pass
    return True

def run_docker_compose(services="", detached=False, no_deps=False):
    if not check_docker():
        return
    
    cmd = ["docker", "compose", "up"]
    if detached:
        cmd.append("-d")
    if no_deps:
        cmd.append("--no-deps")
    cmd.append("--build")
    
    if services:
        cmd.extend(services.split())
        
    print_green(f"Executando: {' '.join(cmd)}")
    try:
        subprocess.run(cmd, check=True)
    except subprocess.CalledProcessError as e:
        print_red(f"Erro ao executar docker-compose: {e}")
    except KeyboardInterrupt:
        print_yellow("\nOperação interrompida pelo usuário.")

def run_swoole_local():
    print_header("Configuração: Servidor PHP Swoole (Local)")
    print("Insira os dados de conexão ou aperte ENTER para usar o valor padrão:")
    
    redis_host = get_input("Host do Redis", env_or_default("REDIS_HOST", "127.0.0.1"))
    redis_port = get_input("Porta do Redis", env_or_default("REDIS_PORT", "6379"))
    mysql_primary = get_input("Host do MySQL Primary", env_or_default("MYSQL_PRIMARY_HOST", "127.0.0.1"))
    mysql_replica = get_input("Host do MySQL Replica", env_or_default("MYSQL_REPLICA_HOST", "127.0.0.1"))
    mysql_user = get_input("Usuário do MySQL", env_or_default("MYSQL_USER", "sb_user"))
    mysql_pass = get_input("Senha do MySQL", env_or_default("MYSQL_PASSWORD", "sb_password"))
    mysql_db = get_input("Banco de Dados", env_or_default("MYSQL_DB", "smartbuilding"))
    rabbit_host = get_input("Host do RabbitMQ", env_or_default("RABBITMQ_HOST", "127.0.0.1"))
    rabbit_port = get_input("Porta do RabbitMQ", env_or_default("RABBITMQ_PORT", "5672"))
    rabbit_user = get_input("Usuário do RabbitMQ", env_or_default("RABBITMQ_USER", "guest"))
    rabbit_pass = get_input("Senha do RabbitMQ", env_or_default("RABBITMQ_PASS", "guest"))
    
    # Check if PHP is available
    if not shutil.which("php"):
        print_red("Erro: PHP não está instalado localmente ou não está no PATH.")
        return

    # Check if vendor directory exists
    vendor_path = os.path.join("server", "vendor")
    if not os.path.isdir(vendor_path):
        print_yellow("Aviso: A pasta 'server/vendor' não existe. Tentando instalar dependências...")
        if shutil.which("composer"):
            try:
                subprocess.run(["composer", "install"], cwd="server", check=True)
            except Exception as e:
                print_red(f"Erro ao rodar composer install: {e}")
                return
        else:
            print_red("Erro: Composer não encontrado. Por favor instale as dependências PHP manualmente.")
            return

    # Set Environment Variables
    env = os.environ.copy()
    env["REDIS_HOST"] = redis_host
    env["REDIS_PORT"] = redis_port
    env["MYSQL_PRIMARY_HOST"] = mysql_primary
    env["MYSQL_REPLICA_HOST"] = mysql_replica
    env["MYSQL_USER"] = mysql_user
    env["MYSQL_PASSWORD"] = mysql_pass
    env["MYSQL_DB"] = mysql_db
    env["RABBITMQ_HOST"] = rabbit_host
    env["RABBITMQ_PORT"] = rabbit_port
    env["RABBITMQ_USER"] = rabbit_user
    env["RABBITMQ_PASS"] = rabbit_pass
    
    print_green("\nIniciando o servidor Swoole local na porta 8000...")
    try:
        # Swoole server needs to run from the 'server' directory
        subprocess.run(["php", "server.php"], cwd="server", env=env, check=True)
    except KeyboardInterrupt:
        print_yellow("\nServidor Swoole encerrado pelo usuário.")
    except Exception as e:
        print_red(f"Erro ao iniciar o servidor: {e}")

def run_worker_local():
    print_header("Configuração: Worker de Análise Python (Local)")
    print("Insira os dados de conexão ou aperte ENTER para usar o valor padrão:")
    
    rabbit_host = get_input("Host do RabbitMQ", env_or_default("RABBITMQ_HOST", "127.0.0.1"))
    rabbit_port = get_input("Porta do RabbitMQ", env_or_default("RABBITMQ_PORT", "5672"))
    rabbit_user = get_input("Usuário do RabbitMQ", env_or_default("RABBITMQ_USER", "guest"))
    rabbit_pass = get_input("Senha do RabbitMQ", env_or_default("RABBITMQ_PASS", "guest"))
    redis_host = get_input("Host do Redis", env_or_default("REDIS_HOST", "127.0.0.1"))
    redis_port = get_input("Porta do Redis", env_or_default("REDIS_PORT", "6379"))
    mysql_host = get_input("Host do MySQL Primary", env_or_default("MYSQL_HOST", env_or_default("MYSQL_PRIMARY_HOST", "127.0.0.1")))
    mysql_user = get_input("Usuário do MySQL", env_or_default("MYSQL_USER", "sb_user"))
    mysql_pass = get_input("Senha do MySQL", env_or_default("MYSQL_PASSWORD", "sb_password"))
    mysql_db = get_input("Banco de Dados", env_or_default("MYSQL_DB", "smartbuilding"))
    
    # Determine the python command
    python_cmd = None
    for cmd in ["python", "python3", "py"]:
        if shutil.which(cmd):
            python_cmd = cmd
            break
            
    if not python_cmd:
        print_red("Erro: Executável Python não encontrado localmente.")
        return

    # Set Environment Variables
    env = os.environ.copy()
    env["RABBITMQ_HOST"] = rabbit_host
    env["RABBITMQ_PORT"] = rabbit_port
    env["RABBITMQ_USER"] = rabbit_user
    env["RABBITMQ_PASS"] = rabbit_pass
    env["REDIS_HOST"] = redis_host
    env["REDIS_PORT"] = redis_port
    env["MYSQL_HOST"] = mysql_host
    env["MYSQL_USER"] = mysql_user
    env["MYSQL_PASSWORD"] = mysql_pass
    env["MYSQL_DB"] = mysql_db
    
    print_green("\nIniciando o Worker Python local...")
    try:
        subprocess.run([python_cmd, "worker.py"], cwd="worker", env=env, check=True)
    except KeyboardInterrupt:
        print_yellow("\nWorker Python encerrado pelo usuário.")
    except Exception as e:
        print_red(f"Erro ao iniciar o worker: {e}")

def run_sensors_local():
    print_header("Configuração: Sensores Simulados (Emulação Concorrente)")
    
    num_sensors = int(get_input("Quantidade de sensores/salas a emular", "5"))
    interval = int(get_input("Intervalo de leitura de cada sensor (segundos)", "3"))
    rabbit_host = get_input("Host do RabbitMQ", env_or_default("RABBITMQ_HOST", "127.0.0.1"))
    rabbit_port = get_input("Porta do RabbitMQ", env_or_default("RABBITMQ_PORT", "5672"))
    rabbit_user = get_input("Usuário do RabbitMQ", env_or_default("RABBITMQ_USER", "guest"))
    rabbit_pass = get_input("Senha do RabbitMQ", env_or_default("RABBITMQ_PASS", "guest"))
    
    if not shutil.which("php"):
        print_red("Erro: PHP não está instalado localmente para rodar os sensores simulados.")
        return
        
    rooms = ["101", "102", "201", "202", "301", "302", "401", "402", "501", "502", "601", "602", "701", "702"]
    processes = []
    
    print_cyan(f"\nIniciando {num_sensors} sensores em paralelo...")
    
    env = os.environ.copy()
    env["RABBITMQ_HOST"] = rabbit_host
    env["RABBITMQ_PORT"] = rabbit_port
    env["RABBITMQ_USER"] = rabbit_user
    env["RABBITMQ_PASS"] = rabbit_pass
    
    try:
        for i in range(num_sensors):
            room_index = i % len(rooms)
            room_id = rooms[room_index]
            
            if i >= len(rooms):
                floor = (i // 2) + 1
                suite = (i % 2) + 1
                room_id = f"{floor}0{suite}"
            
            print(f"-> Disparando sensor para a Sala {room_id} (Intervalo: {interval}s)...")
            p = subprocess.Popen(
                ["php", "sensor.php", room_id, str(interval)], 
                cwd="sensors", 
                env=env,
                stdout=subprocess.DEVNULL, # keep console clean
                stderr=subprocess.DEVNULL
            )
            processes.append(p)
            
        print_green(f"\n[OK] {num_sensors} sensores rodando concorrentemente em background.")
        print_yellow("Aperte Ctrl+C para parar todos os sensores emulados.")
        
        while True:
            # Keep parent script alive and check children health
            time.sleep(1)
            for p in processes:
                if p.poll() is not None:
                    # process ended unexpectedly
                    pass
                    
    except KeyboardInterrupt:
        print_red("\n[Encerrando] Parando todos os processos de sensores...")
        for p in processes:
            p.terminate()
        for p in processes:
            p.wait()
        print_green("Sensores parados com sucesso.")

def main_menu():
    while True:
        print_header("Smart Building IoT - Menu de Inicialização")
        print("Escolha uma das opções abaixo para iniciar:")
        print("  \033[92m[1] Rodar TUDO (Ambiente completo via Docker Compose)\033[0m")
        print("  \033[92m[2] Rodar apenas INFRAESTRUTURA (RabbitMQ, Redis, MySQL no Docker)\033[0m")
        print("  \033[92m[3] Rodar apenas SERVIDOR SWOOLE (PHP)\033[0m")
        print("  \033[92m[4] Rodar apenas WORKER DE ANÁLISE (Python)\033[0m")
        print("  \033[92m[5] Rodar apenas SENSORES SIMULADOS (PHP CLI concorrente)\033[0m")
        print("  \033[91m[6] Parar/Limpar containers Docker Compose ativos\033[0m")
        print("  \033[93m[7] Sair\033[0m")
        
        opcao = input("\nDigite a opção desejada: ").strip()
        
        if opcao == "1":
            detached_input = get_input("Deseja rodar em background (detached) (s/n)?", "n")
            detached = detached_input.lower() == "s"
            run_docker_compose(detached=detached)
        elif opcao == "2":
            run_docker_compose(services="rabbitmq redis mysql-primary mysql-replica")
        elif opcao == "3":
            print("\nDeseja rodar o Servidor Swoole no Docker ou Local?")
            modo = get_input("[d] Docker / [l] Local", "d").lower()
            if modo == "d":
                run_docker_compose(services="swoole-server", no_deps=True)
            else:
                run_swoole_local()
        elif opcao == "4":
            print("\nDeseja rodar o Worker Python no Docker ou Local?")
            modo = get_input("[d] Docker / [l] Local", "d").lower()
            if modo == "d":
                run_docker_compose(services="python-worker", no_deps=True)
            else:
                run_worker_local()
        elif opcao == "5":
            run_sensors_local()
        elif opcao == "6":
            print_yellow("Parando e removendo containers do Docker Compose...")
            try:
                subprocess.run(["docker", "compose", "down"], check=True)
                print_green("[OK] Containers finalizados.")
            except Exception as e:
                print_red(f"Erro ao parar docker-compose: {e}")
        elif opcao == "7":
            print("Saindo do inicializador. Até logo!")
            break
        else:
            print_red("Opção inválida! Escolha um número de 1 a 7.")

if __name__ == "__main__":
    # Ensure current directory is the script root (smartbuilding)
    script_dir = os.path.dirname(os.path.abspath(__file__))
    os.chdir(script_dir)
    load_dotenv(os.path.join(script_dir, ".env"))
    try:
        main_menu()
    except KeyboardInterrupt:
        print_yellow("\nSaindo...")
        sys.exit(0)
